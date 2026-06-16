<?php

/**
 * Builds HL7v2 ADT messages (A01, A03, A04, A08) from OpenEMR data.
 *
 * The builder produces a minimal but well-formed message: MSH, EVN, PID and a
 * PV1 segment. It deliberately reads only fields that map cleanly from the
 * patient_data / form_encounter tables and escapes every value per the HL7
 * encoding rules.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\AdtNotifier\Hl7;

use OpenEMR\Modules\AdtNotifier\GlobalConfig;

class AdtMessageBuilder
{
    private const SEGMENT_SEPARATOR = "\r";
    private const FIELD_SEPARATOR = '|';
    private const COMPONENT_SEPARATOR = '^';
    private const ENCODING_CHARACTERS = '^~\\&';
    private const HL7_VERSION = '2.5.1';

    public function __construct(private readonly GlobalConfig $config)
    {
    }

    /**
     * Build a patient-level ADT message (A04 register, A08 update).
     *
     * @param array<string, mixed> $patientData patient_data columns
     */
    public function buildPatientMessage(string $eventCode, array $patientData): string
    {
        $segments = [
            $this->buildMsh($eventCode),
            $this->buildEvn($eventCode),
            $this->buildPid($patientData),
            $this->buildSegment(['PV1', '1', 'U']),
        ];

        return implode(self::SEGMENT_SEPARATOR, $segments);
    }

    /**
     * Build an encounter-level ADT message (A01 admit, A03 discharge).
     *
     * @param array<string, mixed> $patientData   patient_data columns
     * @param array<string, mixed> $encounterData form_encounter columns
     */
    public function buildEncounterMessage(string $eventCode, array $patientData, array $encounterData): string
    {
        $segments = [
            $this->buildMsh($eventCode),
            $this->buildEvn($eventCode),
            $this->buildPid($patientData),
            $this->buildPv1($eventCode, $encounterData),
        ];

        return implode(self::SEGMENT_SEPARATOR, $segments);
    }

    private function buildMsh(string $eventCode): string
    {
        $messageType = $this->field('ADT', $eventCode, $this->structureForEvent($eventCode));

        // MSH is special: MSH-1 is the field separator and MSH-2 the encoding
        // characters, so they are not separated by an additional delimiter.
        $fields = [
            'MSH',
            self::ENCODING_CHARACTERS,
            $this->escape($this->config->getSendingApplication()),
            $this->escape($this->config->getSendingFacility()),
            $this->escape($this->config->getReceivingApplication()),
            $this->escape($this->config->getReceivingFacility()),
            $this->now(),
            '',
            $messageType,
            $this->controlId(),
            $this->config->getProcessingId(),
            self::HL7_VERSION,
        ];

        return $fields[0] . self::FIELD_SEPARATOR . $fields[1] . self::FIELD_SEPARATOR
            . implode(self::FIELD_SEPARATOR, array_slice($fields, 2));
    }

    private function buildEvn(string $eventCode): string
    {
        return $this->buildSegment(['EVN', $eventCode, $this->now()]);
    }

    /**
     * @param array<string, mixed> $patientData
     */
    private function buildPid(array $patientData): string
    {
        $patientId = $this->str($patientData, 'pubpid') ?: $this->str($patientData, 'pid');

        $identifier = $this->field(
            $this->escape($patientId),
            '',
            '',
            $this->escape($this->config->getSendingFacility()),
            'MR'
        );

        $name = $this->field(
            $this->escape($this->str($patientData, 'lname')),
            $this->escape($this->str($patientData, 'fname')),
            $this->escape($this->str($patientData, 'mname'))
        );

        $address = $this->field(
            $this->escape($this->str($patientData, 'street')),
            '',
            $this->escape($this->str($patientData, 'city')),
            $this->escape($this->str($patientData, 'state')),
            $this->escape($this->str($patientData, 'postal_code')),
            $this->escape($this->str($patientData, 'country_code'))
        );

        return $this->buildSegment([
            'PID',
            '1',
            '',
            $identifier,
            '',
            $name,
            '',
            $this->date($this->str($patientData, 'DOB')),
            $this->sex($this->str($patientData, 'sex')),
            '',
            '',
            $address,
            '',
            $this->escape($this->str($patientData, 'phone_home')),
            '',
            '',
            '',
            '',
            $this->escape($this->str($patientData, 'ss')),
        ]);
    }

    /**
     * @param array<string, mixed> $encounterData
     */
    private function buildPv1(string $eventCode, array $encounterData): string
    {
        $admitDate = $this->dateTime($this->str($encounterData, 'date'));
        $dischargeDate = $eventCode === 'A03' ? $this->dateTime($this->str($encounterData, 'date')) : '';

        $fields = array_fill(0, 46, '');
        $fields[0] = 'PV1';
        $fields[1] = '1';
        $fields[2] = 'O'; // PV1-2 patient class: outpatient
        $fields[19] = $this->escape($this->str($encounterData, 'encounter')); // visit number
        $fields[36] = $this->escape($this->str($encounterData, 'discharge_disposition'));
        $fields[44] = $admitDate;
        $fields[45] = $dischargeDate;

        return $this->buildSegment($fields);
    }

    /**
     * HL7 message structure id for MSH-9 component 3.
     */
    private function structureForEvent(string $eventCode): string
    {
        return match ($eventCode) {
            'A03' => 'ADT_A03',
            default => 'ADT_A01',
        };
    }

    /**
     * @param string ...$components
     */
    private function field(string ...$components): string
    {
        return $this->trimTrailing($components, self::COMPONENT_SEPARATOR);
    }

    /**
     * @param array<int, string> $fields
     */
    private function buildSegment(array $fields): string
    {
        return $this->trimTrailing($fields, self::FIELD_SEPARATOR, 2);
    }

    /**
     * Join parts with a separator, dropping trailing empty parts while keeping
     * at least $keep leading parts.
     *
     * @param array<int, string> $parts
     */
    private function trimTrailing(array $parts, string $separator, int $keep = 1): string
    {
        $parts = array_values($parts);
        while (count($parts) > $keep && end($parts) === '') {
            array_pop($parts);
        }

        return implode($separator, $parts);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function str(array $data, string $key): string
    {
        $value = $data[$key] ?? '';
        if (is_string($value) || is_int($value) || is_float($value)) {
            return trim((string) $value);
        }

        return '';
    }

    /**
     * Escape the HL7 delimiter characters within a field value.
     */
    private function escape(string $value): string
    {
        $replacements = [
            '\\' => '\\E\\',
            '|' => '\\F\\',
            '^' => '\\S\\',
            '~' => '\\R\\',
            '&' => '\\T\\',
            "\r" => '',
            "\n" => '',
        ];

        return strtr($value, $replacements);
    }

    private function sex(string $value): string
    {
        return match (strtoupper(substr($value, 0, 1))) {
            'M' => 'M',
            'F' => 'F',
            default => 'U',
        };
    }

    private function date(string $value): string
    {
        $digits = preg_replace('/\D/', '', $value) ?? '';
        return substr($digits, 0, 8);
    }

    private function dateTime(string $value): string
    {
        $digits = preg_replace('/\D/', '', $value) ?? '';
        if (strlen($digits) < 8) {
            return '';
        }
        // Pad time component so a date-only value becomes YYYYMMDD000000.
        return substr(str_pad($digits, 14, '0'), 0, 14);
    }

    private function now(): string
    {
        return (new \DateTimeImmutable())->format('YmdHis');
    }

    private function controlId(): string
    {
        return 'OE' . (new \DateTimeImmutable())->format('YmdHis') . random_int(1000, 9999);
    }
}
