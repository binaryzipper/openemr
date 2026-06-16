<?php

/**
 * ADT HL7v2 Notifier module entry point.
 *
 * Loaded by ModulesApplication on every request for enabled custom modules.
 * Registers the module namespace and wires up its event subscribers.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\AdtNotifier;

use OpenEMR\Core\OEGlobalsBag;

/**
 * @var \OpenEMR\Core\ModulesClassLoader $classLoader Injected by the OpenEMR module loader.
 * @var \Symfony\Component\EventDispatcher\EventDispatcherInterface $eventDispatcher Injected by the OpenEMR module loader.
 */
$classLoader->registerNamespaceIfNotExists('OpenEMR\\Modules\\AdtNotifier\\', __DIR__ . DIRECTORY_SEPARATOR . 'src');

$bootstrap = new Bootstrap($eventDispatcher, OEGlobalsBag::getInstance()->getKernel());
$bootstrap->subscribeToEvents();
