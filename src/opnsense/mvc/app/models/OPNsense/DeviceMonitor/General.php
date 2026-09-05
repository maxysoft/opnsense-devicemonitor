<?php

namespace OPNsense\DeviceMonitor;

use OPNsense\Base\BaseModel;
use OPNsense\Base\Messages\Message;

/**
 * Device Monitor settings.
 *
 * Field level validation lives in General.xml. Only the rules that depend on
 * more than one field are implemented here, because the model definition
 * cannot express them. They run on every save, so enabling a notification
 * channel without filling in what that channel needs is rejected and the
 * message is attached to the offending field in the form.
 */
class General extends BaseModel
{
    public function performValidation($validateFullModel = false)
    {
        $result = parent::performValidation($validateFullModel);
        $general = $this->general;

        if ((string)$general->email_enabled == '1') {
            if ((string)$general->email_to == '') {
                $result->appendMessage(new Message(
                    gettext('A recipient address is required when email notifications are enabled.'),
                    'general.email_to'
                ));
            }
            if ((string)$general->email_from == '') {
                $result->appendMessage(new Message(
                    gettext('A sender address is required when email notifications are enabled.'),
                    'general.email_from'
                ));
            }

            if ((string)$general->email_method == 'smtp') {
                if ((string)$general->smtp_host == '') {
                    $result->appendMessage(new Message(
                        gettext('An SMTP server is required when the direct SMTP transport is selected.'),
                        'general.smtp_host'
                    ));
                }
                if ((string)$general->smtp_port == '') {
                    $result->appendMessage(new Message(
                        gettext('An SMTP port is required when the direct SMTP transport is selected.'),
                        'general.smtp_port'
                    ));
                }

                // Half a credential pair authenticates nothing and the server
                // rejects the session, so ask for both or neither.
                $user = (string)$general->smtp_username;
                $pass = (string)$general->smtp_password;
                if ($user !== '' && $pass === '') {
                    $result->appendMessage(new Message(
                        gettext('A password is required when an SMTP username is set.'),
                        'general.smtp_password'
                    ));
                }
                if ($pass !== '' && $user === '') {
                    $result->appendMessage(new Message(
                        gettext('A username is required when an SMTP password is set.'),
                        'general.smtp_username'
                    ));
                }
            }
        }

        if ((string)$general->webhook_enabled == '1') {
            $url = (string)$general->webhook_url;
            if ($url === '') {
                $result->appendMessage(new Message(
                    gettext('A webhook URL is required when webhook notifications are enabled.'),
                    'general.webhook_url'
                ));
            } elseif (!preg_match('#^https?://#i', $url)) {
                // The notification is delivered with an HTTP POST, so any other
                // scheme cannot work.
                $result->appendMessage(new Message(
                    gettext('The webhook URL must start with http:// or https://.'),
                    'general.webhook_url'
                ));
            }
        }

        return $result;
    }
}
