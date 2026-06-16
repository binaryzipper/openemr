<?php

/**
 * Listens for patient and encounter lifecycle events and forwards an
 * equivalent HL7v2 ADT message to the configured HTTP endpoint.
 *
 * Mapping:
 *   patient.created      -> ADT^A04 (register a patient)
 *   patient.updated      -> ADT^A08 (update patient information)
 *   service.save.post    -> ADT^A01 (admit/visit) or ADT^A03 (discharge),
 *                           but only for EncounterService saves.
 *
 * Delivery is fire-and-forget: any failure is swallowed so it never blocks or
 * breaks the clinical save that triggered it.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\AdtNotifier\EventSubscriber;

use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Logging\SystemLogger;
use OpenEMR\Events\Patient\PatientCreatedEvent;
use OpenEMR\Events\Patient\PatientUpdatedEvent;
use OpenEMR\Events\Services\ServiceSaveEvent;
use OpenEMR\Modules\AdtNotifier\Client\AdtHttpClient;
use OpenEMR\Modules\AdtNotifier\GlobalConfig;
use OpenEMR\Modules\AdtNotifier\Hl7\AdtMessageBuilder;
use OpenEMR\Services\EncounterService;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class AdtNotifierSubscriber implements EventSubscriberInterface
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly GlobalConfig $config,
        private readonly AdtMessageBuilder $builder,
        private readonly AdtHttpClient $client,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new SystemLogger();
    }

    /**
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            PatientCreatedEvent::EVENT_HANDLE => 'onPatientCreated',
            PatientUpdatedEvent::EVENT_HANDLE => 'onPatientUpdated',
            ServiceSaveEvent::EVENT_POST_SAVE => 'onServicePostSave',
        ];
    }

    public function onPatientCreated(PatientCreatedEvent $event): void
    {
        if (!$this->config->isPatientEventsEnabled()) {
            return;
        }

        $this->guard(function () use ($event): void {
            $message = $this->builder->buildPatientMessage('A04', $event->getPatientData());
            $this->client->sendMessage($message);
        });
    }

    public function onPatientUpdated(PatientUpdatedEvent $event): void
    {
        if (!$this->config->isPatientEventsEnabled()) {
            return;
        }

        $this->guard(function () use ($event): void {
            $newData = $event->getNewPatientData();
            if (!is_array($newData)) {
                return;
            }
            $message = $this->builder->buildPatientMessage('A08', $newData);
            $this->client->sendMessage($message);
        });
    }

    public function onServicePostSave(ServiceSaveEvent $event): void
    {
        if (!$this->config->isEncounterEventsEnabled()) {
            return;
        }
        if (!$event->getService() instanceof EncounterService) {
            return;
        }

        $this->guard(function () use ($event): void {
            $encounterData = $event->getSaveData();
            $pid = $encounterData['pid'] ?? null;
            if (empty($pid)) {
                return;
            }

            $patientData = $this->lookupPatient((int) $pid);
            if ($patientData === null) {
                return;
            }

            $encounterData['encounter'] ??= $encounterData['eid'] ?? '';

            // There is no insert/update signal on the event, so a populated
            // discharge disposition is what distinguishes a discharge (A03)
            // from an admit/visit notification (A01).
            $eventCode = !empty($encounterData['discharge_disposition']) ? 'A03' : 'A01';

            $message = $this->builder->buildEncounterMessage($eventCode, $patientData, $encounterData);
            $this->client->sendMessage($message);
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    private function lookupPatient(int $pid): ?array
    {
        $row = QueryUtils::querySingleRow("SELECT * FROM patient_data WHERE pid = ?", [$pid]);
        return is_array($row) ? $row : null;
    }

    private function guard(callable $callback): void
    {
        try {
            $callback();
        } catch (\Throwable $e) {
            $this->logger->error('ADT notifier: failed to build/send ADT message', [
                'exception' => $e,
            ]);
        }
    }
}
