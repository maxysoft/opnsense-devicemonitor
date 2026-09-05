<?php

namespace OPNsense\DeviceMonitor\Api;

use OPNsense\Base\ApiMutableModelControllerBase;

/**
 * Settings for the Device Monitor module.
 *
 * get/set are inherited: they read and write //OPNsense/DeviceMonitor in
 * config.xml and run the model validation on the way in.
 */
class SettingsController extends ApiMutableModelControllerBase
{
    protected static $internalModelClass = 'OPNsense\DeviceMonitor\General';
    protected static $internalModelName = 'devicemonitor';
}
