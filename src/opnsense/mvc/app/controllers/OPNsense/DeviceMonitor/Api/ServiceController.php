<?php

namespace OPNsense\DeviceMonitor\Api;

use OPNsense\Base\ApiControllerBase;
use OPNsense\Core\Backend;

class ServiceController extends ApiControllerBase
{
    /**
     * Render the configuration files from config.xml and restart the daemon.
     * Called after the settings form is saved.
     */
    public function reconfigureAction()
    {
        if (!$this->request->isPost()) {
            return ['status' => 'failed'];
        }

        $backend = new Backend();
        $backend->configdRun('template reload OPNsense/DeviceMonitor');
        $backend->configdRun('devicemonitor restart');

        return ['status' => 'ok'];
    }

    /**
     * Spuštění manuálního skenu
     */
    public function scanAction()
    {
        if ($this->request->isPost()) {
            $backend = new Backend();
            $response = $backend->configdRun("devicemonitor scan");
            return ['result' => 'ok', 'output' => $response];
        }
        
        return ['result' => 'failed'];
    }

    /**
     * Status daemona
     */
    public function statusAction()
    {
        $model = new \OPNsense\DeviceMonitor\DeviceMonitor();
        $pidFile = $model->getPidFilePath();
        
        if (file_exists($pidFile)) {
            $pid = trim(file_get_contents($pidFile));
            
            // Zkontroluj jestli proces běží
            exec("ps -p $pid", $output, $return);
            
            if ($return === 0) {
                return [
                    'result' => 'running',
                    'pid' => $pid,
                    'message' => 'Daemon is running'
                ];
            } else {
                return [
                    'result' => 'stopped',
                    'message' => 'Daemon is not running (stale pidfile)'
                ];
            }
        } else {
            return [
                'result' => 'stopped',
                'message' => 'Daemon is not running'
            ];
        }
    }

    /**
     * Start daemona
     */
    public function startAction()
    {
        if ($this->request->isPost()) {
            $status = $this->statusAction();
            if ($status['result'] === 'running') {
                return ['result' => 'already_running', 'message' => 'Daemon is already running'];
            }

            $backend = new Backend();
            $backend->configdRun('devicemonitor start');
            sleep(1);

            $status = $this->statusAction();
            if ($status['result'] === 'running') {
                return ['result' => 'started', 'message' => 'Daemon started successfully'];
            } else {
                return ['result' => 'failed', 'message' => 'Failed to start daemon'];
            }
        }
        return ['result' => 'failed'];
    }

    /**
     * Stop daemona
     */
    public function stopAction()
    {
        if ($this->request->isPost()) {
            $backend = new Backend();
            $backend->configdRun('devicemonitor stop');
            sleep(1);

            $status = $this->statusAction();
            if ($status['result'] === 'stopped') {
                return ['result' => 'stopped', 'message' => 'Daemon stopped successfully'];
            } else {
                return ['result' => 'failed', 'message' => 'Failed to stop daemon'];
            }
        }
        return ['result' => 'failed'];
    }

    /**
     * Restart daemona
     */
    public function restartAction()
    {
        if ($this->request->isPost()) {
            $backend = new Backend();
            $backend->configdRun('devicemonitor restart');
            sleep(2);
            return $this->statusAction();
        }
        return ['result' => 'failed'];
    }
}