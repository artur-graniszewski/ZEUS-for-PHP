<?php

namespace Zeus\ServerService\Shared\Logger;

use Zend\Console\ColorInterface;
use Zend\Console\Adapter\AdapterInterface;
use Zend\Log\Formatter\FormatterInterface;

class StreamLogFormatter implements FormatterInterface
{
    /**
     * This method is implemented for FormatterInterface but not used.
     *
     * @return string
     */
    public function getDateTimeFormat()
    {
        return '';
    }

    /**
     * This method is implemented for FormatterInterface but not used.
     *
     * @param  string             $dateTimeFormat
     * @return FormatterInterface
     */
    public function setDateTimeFormat($dateTimeFormat)
    {
        return $this;
    }

    public function format($event)
    {
        $serviceName = $event['extra']['service_name'];
        $dateTime = $event['timestamp']->format('Y-m-d H:i:s.') . sprintf("%'.03d", $event['extra']['microtime']);
        $severity = str_pad($event['priorityName'], 7, " ", STR_PAD_LEFT);
        $pid = $event['extra']['uid'];
        $serviceName = sprintf("--- [%s]", str_pad(substr($serviceName,0, 15), 15, " ", STR_PAD_LEFT));
        $loggerName = str_pad(isset($event['extra']['logger']) ? substr($event['extra']['logger'], -40) : '<unknown>', 40, " ", STR_PAD_RIGHT);
        $message = ": " . $event['message'];

        $eventText = "$dateTime $severity $pid $serviceName $loggerName $message";
        return $eventText;
    }
}