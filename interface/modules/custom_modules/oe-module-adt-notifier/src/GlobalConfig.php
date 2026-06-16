<?php

/**
 * Configuration accessor for the ADT HL7v2 Notifier module.
 *
 * Reads the module's settings out of the OpenEMR globals bag. Settings are
 * registered with the Admin -> Globals UI from Bootstrap and persisted to the
 * `globals` table. The client secret is stored encrypted.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\AdtNotifier;

use OpenEMR\BC\ServiceContainer;
use OpenEMR\Common\Crypto\CryptoInterface;
use OpenEMR\Services\Globals\GlobalSetting;

class GlobalConfig
{
    public const CONFIG_API_URL = 'oe_adt_notifier_api_url';
    public const CONFIG_TOKEN_URL = 'oe_adt_notifier_token_url';
    public const CONFIG_CLIENT_ID = 'oe_adt_notifier_client_id';
    public const CONFIG_CLIENT_SECRET = 'oe_adt_notifier_client_secret';
    public const CONFIG_SCOPE = 'oe_adt_notifier_scope';

    public const CONFIG_SENDING_APP = 'oe_adt_notifier_sending_app';
    public const CONFIG_SENDING_FACILITY = 'oe_adt_notifier_sending_facility';
    public const CONFIG_RECEIVING_APP = 'oe_adt_notifier_receiving_app';
    public const CONFIG_RECEIVING_FACILITY = 'oe_adt_notifier_receiving_facility';
    public const CONFIG_PROCESSING_ID = 'oe_adt_notifier_processing_id';

    public const CONFIG_ENABLE_PATIENT_EVENTS = 'oe_adt_notifier_enable_patient_events';
    public const CONFIG_ENABLE_ENCOUNTER_EVENTS = 'oe_adt_notifier_enable_encounter_events';

    private readonly CryptoInterface $cryptoGen;

    /**
     * @param array<string, mixed> $globalsArray
     */
    public function __construct(private array $globalsArray)
    {
        $this->cryptoGen = ServiceContainer::getCrypto();
    }

    /**
     * The module only wires its subscribers once the minimum connection
     * settings are present, so an unconfigured install is inert.
     */
    public function isConfigured(): bool
    {
        foreach ([self::CONFIG_API_URL, self::CONFIG_TOKEN_URL, self::CONFIG_CLIENT_ID] as $key) {
            if (empty($this->getGlobalSetting($key))) {
                return false;
            }
        }

        return !empty($this->getClientSecret());
    }

    public function getApiUrl(): string
    {
        return (string) $this->getGlobalSetting(self::CONFIG_API_URL);
    }

    public function getTokenUrl(): string
    {
        return (string) $this->getGlobalSetting(self::CONFIG_TOKEN_URL);
    }

    public function getClientId(): string
    {
        return (string) $this->getGlobalSetting(self::CONFIG_CLIENT_ID);
    }

    public function getClientSecret(): string
    {
        $encryptedValue = $this->getGlobalSetting(self::CONFIG_CLIENT_SECRET);
        if (!is_string($encryptedValue) || $encryptedValue === '') {
            return '';
        }

        return (string) $this->cryptoGen->decryptFromDatabase($encryptedValue);
    }

    public function getScope(): string
    {
        return (string) $this->getGlobalSetting(self::CONFIG_SCOPE);
    }

    public function getSendingApplication(): string
    {
        return (string) ($this->getGlobalSetting(self::CONFIG_SENDING_APP) ?: 'OpenEMR');
    }

    public function getSendingFacility(): string
    {
        return (string) $this->getGlobalSetting(self::CONFIG_SENDING_FACILITY);
    }

    public function getReceivingApplication(): string
    {
        return (string) $this->getGlobalSetting(self::CONFIG_RECEIVING_APP);
    }

    public function getReceivingFacility(): string
    {
        return (string) $this->getGlobalSetting(self::CONFIG_RECEIVING_FACILITY);
    }

    /**
     * MSH-11 processing ID: "P" production or "T" training/test.
     */
    public function getProcessingId(): string
    {
        $value = strtoupper((string) $this->getGlobalSetting(self::CONFIG_PROCESSING_ID));
        return $value === 'P' ? 'P' : 'T';
    }

    public function isPatientEventsEnabled(): bool
    {
        return (bool) $this->getGlobalSetting(self::CONFIG_ENABLE_PATIENT_EVENTS);
    }

    public function isEncounterEventsEnabled(): bool
    {
        return (bool) $this->getGlobalSetting(self::CONFIG_ENABLE_ENCOUNTER_EVENTS);
    }

    public function getGlobalSetting(string $settingKey): mixed
    {
        return $this->globalsArray[$settingKey] ?? null;
    }

    /**
     * Describes the settings rendered into the Admin -> Globals section.
     *
     * @return array<string, array{title: string, description: string, type: string, default: string}>
     */
    public function getGlobalSettingSectionConfiguration(): array
    {
        return [
            self::CONFIG_API_URL => [
                'title' => 'ADT Endpoint URL',
                'description' => 'Full URL that receives the POST body {"hl7_message": "..."}.',
                'type' => GlobalSetting::DATA_TYPE_TEXT,
                'default' => '',
            ],
            self::CONFIG_TOKEN_URL => [
                'title' => 'OAuth Token URL',
                'description' => 'OAuth2 token endpoint used for the client_credentials grant.',
                'type' => GlobalSetting::DATA_TYPE_TEXT,
                'default' => '',
            ],
            self::CONFIG_CLIENT_ID => [
                'title' => 'OAuth Client ID',
                'description' => 'Client identifier issued by the receiving system.',
                'type' => GlobalSetting::DATA_TYPE_TEXT,
                'default' => '',
            ],
            self::CONFIG_CLIENT_SECRET => [
                'title' => 'OAuth Client Secret',
                'description' => 'Client secret issued by the receiving system (stored encrypted).',
                'type' => GlobalSetting::DATA_TYPE_ENCRYPTED,
                'default' => '',
            ],
            self::CONFIG_SCOPE => [
                'title' => 'OAuth Scope',
                'description' => 'Optional scope requested with the token (leave blank if not required).',
                'type' => GlobalSetting::DATA_TYPE_TEXT,
                'default' => '',
            ],
            self::CONFIG_SENDING_APP => [
                'title' => 'HL7 Sending Application (MSH-3)',
                'description' => 'Identifies this OpenEMR instance in the MSH segment.',
                'type' => GlobalSetting::DATA_TYPE_TEXT,
                'default' => 'OpenEMR',
            ],
            self::CONFIG_SENDING_FACILITY => [
                'title' => 'HL7 Sending Facility (MSH-4)',
                'description' => 'Facility identifier for the MSH segment.',
                'type' => GlobalSetting::DATA_TYPE_TEXT,
                'default' => '',
            ],
            self::CONFIG_RECEIVING_APP => [
                'title' => 'HL7 Receiving Application (MSH-5)',
                'description' => 'Receiving application identifier for the MSH segment.',
                'type' => GlobalSetting::DATA_TYPE_TEXT,
                'default' => '',
            ],
            self::CONFIG_RECEIVING_FACILITY => [
                'title' => 'HL7 Receiving Facility (MSH-6)',
                'description' => 'Receiving facility identifier for the MSH segment.',
                'type' => GlobalSetting::DATA_TYPE_TEXT,
                'default' => '',
            ],
            self::CONFIG_PROCESSING_ID => [
                'title' => 'HL7 Processing ID (MSH-11)',
                'description' => 'P for production messages, T for test/training.',
                'type' => GlobalSetting::DATA_TYPE_TEXT,
                'default' => 'T',
            ],
            self::CONFIG_ENABLE_PATIENT_EVENTS => [
                'title' => 'Send patient ADT (A04 register / A08 update)',
                'description' => 'Emit ADT^A04 on patient creation and ADT^A08 on demographics update.',
                'type' => GlobalSetting::DATA_TYPE_BOOL,
                'default' => '1',
            ],
            self::CONFIG_ENABLE_ENCOUNTER_EVENTS => [
                'title' => 'Send encounter ADT (A01 admit / A03 discharge)',
                'description' => 'Emit ADT^A01 on encounter creation and ADT^A03 when a discharge disposition is set.',
                'type' => GlobalSetting::DATA_TYPE_BOOL,
                'default' => '',
            ],
        ];
    }
}
