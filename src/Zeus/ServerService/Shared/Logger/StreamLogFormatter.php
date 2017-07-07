<?php

namespace Zeus\ServerService\Shared\Logger;



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
        $serviceName = $event['extra']['service_name'] . '-' . $event['extra']['threadId'];
        $dateTime = $event['timestamp']->format('Y-m-d H:i:s.') . sprintf("%'.03d", $event['extra']['microtime']);
        $severity = str_pad($event['priorityName'], 7, " ", STR_PAD_LEFT);
        $pid = $event['extra']['uid'];
        $serviceName = sprintf("--- [%s]", str_pad(substr($serviceName,0, 15), 15, " ", STR_PAD_LEFT));
        $loggerName = str_pad(isset($event['extra']['logger']) ? substr($this->getShortLoggerName($event['extra']['logger']), -40) : '<unknown>', 40, " ", STR_PAD_RIGHT);
        $message = ": " . $event['message'];

        $eventText = "$dateTime $severity $pid $serviceName $loggerName $message";
        return $eventText;
    }

    protected function getShortLoggerName($loggerName)
    {
        if (!isset($loggerName[40])) {
            return $loggerName;
        }

        $parts = explode("\\", $loggerName);
        $loggerName = '';
        $className = array_pop($parts);
        foreach ($parts as $part) {
            $loggerName .= $part[0] . '\\';
        }

        return $loggerName . $className;
    }
}