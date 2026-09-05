<?php

namespace OPNsense\DeviceMonitor;

class IndexController extends \OPNsense\Base\IndexController
{
    public function devicesAction()
    {
        $this->view->pick('OPNsense/DeviceMonitor/devices');
    }

    public function settingsAction()
    {
        $this->view->generalForm = $this->getForm('general');
        $this->view->pick('OPNsense/DeviceMonitor/settings');
    }
}
