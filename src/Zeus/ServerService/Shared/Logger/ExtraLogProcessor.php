<?php

namespace Zeus\ServerService\Shared\Logger;

use Zend\Log\Processor\ProcessorInterface;

class ExtraLogProcessor implements ProcessorInterface
{
    /** @var float */
    protected $launchMicrotime = null;

    /** @var mixed[] */
    protected $config;

    /** @var null|int */
    protected $backTraceLevel = null;

    public function __construct()
    {
        $this->config['service_name'] = '<unknown>';
    }

    /**
     * @param mixed[] $config
     * @return $this
     */
    public function setConfig(array $config)
    {
        $this->config = $config;

        return $this;
    }

    /**
     * @param mixed[] $event
     * @return mixed[]
     */
    public function process(array $event)
    {
        if (!isset($event['extra'])) {
            $event['extra'] = [];
        }

        $event['extra']['service_name'] = isset($event['extra']['service_name']) ? $event['extra']['service_name'] : $this->config['service_name'];
        $event['extra']['uid'] = isset($event['extra']['uid']) ? $event['extra']['uid'] : getmypid();
        $event['extra']['threadId'] = isset($event['extra']['threadId']) ? $event['extra']['threadId'] : (defined("ZEUS_THREAD_ID") ? ZEUS_THREAD_ID : 1);
        $event['extra']['logger'] = isset($event['extra']['logger']) ? $event['extra']['logger'] : $this->detectLogger();
        $microtime = microtime(true);

        $microtime = $microtime > 1 ? $microtime - floor($microtime) : $microtime;
        $event['extra']['microtime'] = isset($event['extra']['microtime']) ? $event['extra']['microtime'] : (int) ($microtime * 1000);

        return $event;
    }

    protected function detectLogger()
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS | DEBUG_BACKTRACE_PROVIDE_OBJECT, $this->backTraceLevel ? $this->backTraceLevel + 1: 100);
        if (!$this->backTraceLevel) {
            foreach ($trace as $level => $step) {
                if (preg_match('~^(Zend\\\Log\\\Logger|Zeus\\\ServerService\\\Shared\\\Logger\\\ExtraLogProcessor)~', $step['class'])) {
                    continue;
                }

                $this->backTraceLevel = $level;
                break;
            }
        }

        $traceStep = $trace[$this->backTraceLevel];

        return isset($traceStep['class']) ? $traceStep['class'] : $traceStep['function'];
    }
}