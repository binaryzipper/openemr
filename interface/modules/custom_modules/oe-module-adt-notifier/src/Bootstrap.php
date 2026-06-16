<?php

/**
 * Bootstrap wiring for the ADT HL7v2 Notifier module.
 *
 * Registers the module's settings in Admin -> Globals and, once configured,
 * attaches the event subscriber that turns patient/encounter saves into
 * outbound ADT messages.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\AdtNotifier;

use OpenEMR\Core\Kernel;
use OpenEMR\Core\OEGlobalsBag;
use OpenEMR\Events\Globals\GlobalsInitializedEvent;
use OpenEMR\Modules\AdtNotifier\Client\AdtHttpClient;
use OpenEMR\Modules\AdtNotifier\EventSubscriber\AdtNotifierSubscriber;
use OpenEMR\Modules\AdtNotifier\Hl7\AdtMessageBuilder;
use OpenEMR\Services\Globals\GlobalSetting;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class Bootstrap
{
    private readonly GlobalConfig $globalsConfig;

    public function __construct(
        private readonly EventDispatcherInterface $eventDispatcher,
        ?Kernel $kernel = null,
    ) {
        $this->globalsConfig = new GlobalConfig($GLOBALS);
    }

    public function subscribeToEvents(): void
    {
        $this->addGlobalSettings();

        if ($this->globalsConfig->isConfigured()) {
            $builder = new AdtMessageBuilder($this->globalsConfig);
            $client = new AdtHttpClient($this->globalsConfig);
            $this->eventDispatcher->addSubscriber(
                new AdtNotifierSubscriber($this->globalsConfig, $builder, $client)
            );
        }
    }

    public function getGlobalConfig(): GlobalConfig
    {
        return $this->globalsConfig;
    }

    private function addGlobalSettings(): void
    {
        $this->eventDispatcher->addListener(
            GlobalsInitializedEvent::EVENT_HANDLE,
            $this->addGlobalSettingsSection(...)
        );
    }

    public function addGlobalSettingsSection(GlobalsInitializedEvent $event): void
    {
        $service = $event->getGlobalsService();
        $section = xlt('ADT HL7v2 Notifier');
        $service->createSection($section);

        foreach ($this->globalsConfig->getGlobalSettingSectionConfiguration() as $key => $config) {
            $value = OEGlobalsBag::getInstance()->get($key) ?? $config['default'];
            $service->appendToSection(
                $section,
                $key,
                new GlobalSetting(
                    xlt($config['title']),
                    $config['type'],
                    $value,
                    xlt($config['description']),
                    false
                )
            );
        }
    }
}
