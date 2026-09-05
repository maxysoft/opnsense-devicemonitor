<?php

namespace OPNsense\DeviceMonitor;

use OPNsense\Base\BaseModel;
use OPNsense\Base\Messages\Message;

/**
 * Device Monitor settings.
 *
 * Field level validation lives in General.xml. Only the rules that depend on
 * more than one field are implemented here, because the model definition
 * cannot express them.
 */
class General extends BaseModel
{
    public function performValidation($validateFullModel = false)
    {
        $result = parent::performValidation($validateFullModel);

        if ((string)$this->general->email_enabled == '1') {
            if ((string)$this->general->email_to == '') {
                $result->appendMessage(new Message(
                    gettext('A recipient address is required when email notifications are enabled.'),
                    'general.email_to'
                ));
            }
            if ((string)$this->general->email_method == 'smtp' && (string)$this->general->smtp_host == '') {
                $result->appendMessage(new Message(
                    gettext('An SMTP server is required when the direct SMTP transport is selected.'),
                    'general.smtp_host'
                ));
            }
        }

        if ((string)$this->general->webhook_enabled == '1' && (string)$this->general->webhook_url == '') {
            $result->appendMessage(new Message(
                gettext('A webhook URL is required when webhook notifications are enabled.'),
                'general.webhook_url'
            ));
        }

        return $result;
    }
}
