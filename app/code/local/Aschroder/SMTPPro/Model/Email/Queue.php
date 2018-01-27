<?php

/**
 * This class wraps the Queue to add email sending functionality
 * If enabled it will send emails using the given configuration.
 *
 * @author Ashley Schroder (aschroder.com)
 * @copyright Copyright (c) 2013 ASchroder Consulting Ltd
 */
class Aschroder_SMTPPro_Model_Email_Queue extends Mage_Core_Model_Email_Queue {

    protected $finalSMTPcodesForEmail = [
        401, 432, 441, 450, 510, 511, 512, 513, 521, 523, 530, 541, 550, 551, 553, 555
    ];

    // As per parent class - except addition of before and after send events
    public function send()
    {

        $_helper = Mage::helper('smtppro');

        // if we have a valid queue page size override, use it
        if (is_numeric($_helper->getQueuePerCron()) &&
            intval($_helper->getQueuePerCron()) > 0) {

            $percron = $_helper->getQueuePerCron();
            $_helper->log('SMTP Pro using queue override page size: '. $percron);
        } else {
            $percron = self::MESSAGES_LIMIT_PER_CRON_RUN;
        }


        $pauseMicros = 0;
        // if we have a valid pause, use it
        if (is_numeric($_helper->getQueuePause()) &&
            intval($_helper->getQueuePause()) > 0) {

            $pauseMicros = $_helper->getQueuePause() * 1000; // * 1000 for millis => micros
            $_helper->log('SMTP Pro using queue override pause: '. $pauseMicros);
        }

        /** @var $collection Mage_Core_Model_Resource_Email_Queue_Collection */
        $collection = Mage::getModel('core/email_queue')->getCollection()
            ->addOnlyForSendingFilter()
            ->setPageSize($percron)
            ->setCurPage(1)
            ->load();


        ini_set('SMTP', Mage::getStoreConfig('system/smtp/host'));
        ini_set('smtp_port', Mage::getStoreConfig('system/smtp/port'));

        /** @var $message Mage_Core_Model_Email_Queue */
        foreach ($collection as $message) {
            if ($message->getId()) {
                $parameters = new Varien_Object($message->getMessageParameters());
                if ($parameters->getReturnPathEmail() !== null) {
                    $mailTransport = new Zend_Mail_Transport_Sendmail("-f" . $parameters->getReturnPathEmail());
                    Zend_Mail::setDefaultTransport($mailTransport);
                }

                $mailer = new Zend_Mail('utf-8');
                foreach ($message->getRecipients() as $recipient) {
                    list($email, $name, $type) = $recipient;
                    switch ($type) {
                        case self::EMAIL_TYPE_BCC:
                            $mailer->addBcc($email, '=?utf-8?B?' . base64_encode($name) . '?=');
                            break;
                        case self::EMAIL_TYPE_TO:
                        case self::EMAIL_TYPE_CC:
                        default:
                            $mailer->addTo($email, '=?utf-8?B?' . base64_encode($name) . '?=');
                            break;
                    }
                }

                if ($parameters->getIsPlain()) {
                    $mailer->setBodyText($message->getMessageBody());
                } else {
                    $mailer->setBodyHTML($message->getMessageBody());
                }

                $mailer->setSubject('=?utf-8?B?' . base64_encode($parameters->getSubject()) . '?=');
                $mailer->setFrom($parameters->getFromEmail(), $parameters->getFromName());
                if ($parameters->getReplyTo() !== null) {
                    $mailer->setReplyTo($parameters->getReplyTo());
                }
                if ($parameters->getReturnTo() !== null) {
                    $mailer->setReturnPath($parameters->getReturnTo());
                }

                try {


                    $transport = new Varien_Object();
                    Mage::dispatchEvent('aschroder_smtppro_queue_before_send', array(
                        'mail' => $mailer,
                        'transport' => $transport,
                        'message' => $message
                    ));

                    if ($transport->getTransport()) { // if set by an observer, use it
                        $mailer->send($transport->getTransport());
                    } else {
                        $mailer->send();
                    }

                    unset($mailer);
                    $message->setProcessedAt(Varien_Date::formatDate(true));
                    $message->save();

                    // loop each email to fire an after send event
                    foreach ($message->getRecipients() as $recipient) {
                        list($email, $name, $type) = $recipient;
                        Mage::dispatchEvent('aschroder_smtppro_after_send', array(
                            'to' => $email,
                            'template' => "queued email",
                            // TODO: should we preserve the template id in the queue object, in order to include it here?
                            'subject' => $parameters->getSubject(),
                            'html' => !$parameters->getIsPlain(),
                            'email_body' => $message->getMessageBody()));
                    }


                }
                catch (Exception $e) {
                    unset($mailer);
                    $oldDevMode = Mage::getIsDeveloperMode();
                    Mage::setIsDeveloperMode(true);

                    Mage::logException($e);

                    // if SMTP code not final for email, stop queue
                    if ($e instanceof Zend_Mail_Protocol_Exception &&
                        $transport->getTransport() instanceof Zend_Mail_Transport_Smtp &&
                        !in_array($e->getCode(), $this->finalSMTPcodesForEmail)
                    ) {
                        Mage::logException(new Exception('Email Queue was stopped with error code - ' . $e->getCode()));
                        Mage::setIsDeveloperMode($oldDevMode);
                        return false;
                    }

                    Mage::setIsDeveloperMode($oldDevMode);

                    $message->setProcessedAt(Varien_Date::formatDate(true));
                    $message->save();

                    continue;
                }

                // after each valid message has been sent - pause if required
                if ($pauseMicros > 0) {
                    $_helper->log('SMTP Pro pausing.');
                    usleep($pauseMicros);
                }

            }
        }

        return $this;
    }

}
