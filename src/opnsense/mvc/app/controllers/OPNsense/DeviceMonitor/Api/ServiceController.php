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

        // configd renders the file with the permissions of its parent
        // directory, which has to stay traversable for the web UI to read
        // devices.db. The rendered config.json can hold an SMTP password, so
        // the configure action tightens it back to 0600 before restarting.
        $backend->configdRun('devicemonitor configure');

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

        // Ask configd rather than inspecting the process here: it runs as root,
        // so its kill -0 check is reliable, and it clears a stale pidfile. It
        // also keeps an unvalidated pid out of a shell command.
        $backend = new Backend();
        $state = trim((string)$backend->configdRun('devicemonitor status'));

        if ($state !== 'running') {
            return [
                'result' => 'stopped',
                'message' => 'Daemon is not running'
            ];
        }

        $pid = trim((string)@file_get_contents($pidFile));

        return [
            'result' => 'running',
            'pid' => ctype_digit($pid) ? $pid : '',
            'message' => 'Daemon is running'
        ];
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