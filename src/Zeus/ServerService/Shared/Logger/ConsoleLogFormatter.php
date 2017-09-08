<?php

namespace Zeus\ServerService\Shared\Logger;

use Zend\Console\ColorInterface;
use Zend\Console\Adapter\AdapterInterface;
use Zend\Log\Formatter\FormatterInterface;

class ConsoleLogFormatter extends StreamLogFormatter implements FormatterInterface
{
    /** @var AdapterInterface */
    protected $console;

    public function __construct(AdapterInterface $console)
    {
        $this->console = $console;
    }

    public function format($event)
    {
        $console = $this->console;

        $serviceName = $event['extra']['service_name'] . '-' . $event['extra']['threadId'];
        $dateTime = $console->colorize($event['timestamp']->format($this->dateTimeFormat . '.') . sprintf("%'.03d", $event['extra']['microtime']), ColorInterface::GRAY);
        $severity = $console->colorize(str_pad($event['priorityName'], 7, " ", STR_PAD_LEFT), $this->getSeverityColor($event['priorityName']));
        $pid = $console->colorize($event['extra']['uid'], ColorInterface::CYAN);
        $serviceName = $console->colorize(sprintf("--- [%s]", str_pad(substr($serviceName, 0, 15), 15, " ", STR_PAD_LEFT)), ColorInterface::GRAY);
        $loggerName = $console->colorize(str_pad(isset($event['extra']['logger']) ? substr($this->getShortLoggerName($event['extra']['logger']), -40): '<unknown>', 40, " ", STR_PAD_RIGHT), ColorInterface::LIGHT_BLUE) ;
        $message = $console->colorize(": ", ColorInterface::GRAY) . $console->colorize($event['message'], $this->getMessageColor($event['priorityName']));

        $eventText = "$dateTime $severity $pid $serviceName $loggerName $message";
        return $eventText;
    }

    protected function getSeverityColor($severityText)
    {
        $colors = [
            'DEBUG' => ColorInterface::GREEN,
            'INFO'  => ColorInterface::LIGHT_GREEN,
            'ERR' => ColorInterface::RED,
            'NOTICE' => ColorInterface::LIGHT_GREEN,
            'WARN' => ColorInterface::YELLOW
        ];

        if (isset($colors[$severityText])) {
            return $colors[$severityText];
        }

        return ColorInterface::GRAY;
    }

    protected function getMessageColor($severityText)
    {
        $colors = [
            'NOTICE' => ColorInterface::LIGHT_WHITE,
            //'DEBUG' => ColorInterface::MAGENTA,
            'ERR' => ColorInterface::RED,
            'WARN' => ColorInterface::YELLOW
        ];

        if (isset($colors[$severityText])) {
            return $colors[$severityText];
        }

        return ColorInterface::NORMAL;
    }
}