<?php

/**
 * Module Manager lifecycle hooks for the ADT HL7v2 Notifier module.
 *
 * Called from the Laminas Module Manager when the module is installed,
 * enabled or disabled. Keeping the config UI button available before enable
 * lets the administrator configure credentials prior to turning the feed on.
 *
 * @package   OpenEMR Modules
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

use OpenEMR\Core\AbstractModuleActionListener;

class ModuleManagerListener extends AbstractModuleActionListener
{
    public function __construct()
    {
        parent::__construct();
    }

    public function moduleManagerAction($methodName, $modId, string $currentActionStatus = 'Success'): string
    {
        if (method_exists(self::class, $methodName)) {
            return self::$methodName($modId, $currentActionStatus);
        }

        return $currentActionStatus;
    }

    public static function getModuleNamespace(): string
    {
        return 'OpenEMR\\Modules\\AdtNotifier\\';
    }

    public static function initListenerSelf(): ModuleManagerListener
    {
        return new self();
    }

    private function install($modId, $currentActionStatus): mixed
    {
        // Expose the config UI button before enable so credentials can be set up first.
        self::setModuleState($modId, '0', '1');
        return $currentActionStatus;
    }

    private function enable($modId, $currentActionStatus): mixed
    {
        self::setModuleState($modId, '1', '0');
        return $currentActionStatus;
    }

    private function disable($modId, $currentActionStatus): mixed
    {
        self::setModuleState($modId, '0', '1');
        return $currentActionStatus;
    }

    private function unregister($modId, $currentActionStatus): mixed
    {
        return $currentActionStatus;
    }

    private static function setModuleState(int|string $modId, int|string $flag, int|string $flag_ui): array|bool|null
    {
        $sql = "UPDATE `modules` SET `mod_active` = ?, `mod_ui_active` = ? WHERE `mod_id` = ? OR `mod_directory` = ?";
        return sqlQuery($sql, [$flag, $flag_ui, $modId, $modId]);
    }
}
