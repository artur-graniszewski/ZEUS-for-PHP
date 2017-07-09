<?php

namespace Zeus\ServerService\Shared\Logger;

use Zend\Log\Formatter\FormatterInterface;

class StreamLogFormatter implements FormatterInterface
{
    /** @var string[] */
    protected $loggerCache = [];

    /** @var string */
    protected $dateTimeFormat = 'Y-m-d H:i:s';

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
        $this->dateTimeFormat = $dateTimeFormat;

        return $this;
    }

    public function format($event)
    {
        $serviceName = $event['extra']['service_name'] . '-' . $event['extra']['threadId'];
        $dateTime = $event['timestamp']->format($this->dateTimeFormat) . sprintf("%'.03d", $event['extra']['microtime']);
        $severity = str_pad($event['priorityName'], 7, " ", STR_PAD_LEFT);
        $pid = $event['extra']['uid'];
        $serviceName = sprintf("--- [%s]", str_pad(substr($serviceName,0, 15), 15, " ", STR_PAD_LEFT));
        $loggerName = str_pad(isset($event['extra']['logger']) ? substr($this->getShortLoggerName($event['extra']['logger']), -40) : '<unknown>', 40, " ", STR_PAD_RIGHT);
        $message = ": " . $event['message'];

        $eventText = "$dateTime $severity $pid $serviceName $loggerName $message";
        return $eventText;
    }

    protected function getShortLoggerName(string $loggerName) : string
    {
        if (!isset($loggerName[40])) {
            return $loggerName;
        }

        if (isset($this->loggerCache[$loggerName])) {
            return $this->loggerCache[$loggerName];
        }

        if (count($this->loggerCache) > 256) {
            $this->loggerCache = [];
        }

        $parts = explode("\\", $loggerName);
        $shortLoggerName = '';
        $className = array_pop($parts);
        foreach ($parts as $part) {
            $shortLoggerName .= $part[0] . '\\';
        }

        $shortLoggerName .= $className;

        $this->loggerCache[$loggerName] = $shortLoggerName;

        return $shortLoggerName;
    }
}