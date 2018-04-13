<?php

namespace Zeus\ServerService\Shared\Logger;

use Zend\Log\Processor\ProcessorInterface;

use function microtime;
use function debug_backtrace;
use function preg_match;
use function floor;
use function defined;
use function getmypid;

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
     */
    public function setConfig(array $config)
    {
        $this->config = $config;
    }

    /**
     * @param mixed[] $event
     * @return mixed[]
     */
    public function process(array $event)
    {
        $microtime = microtime(true);
        $microtime = $microtime > 1 ? $microtime - floor($microtime) : $microtime;

        $extra = isset($event['extra']) ? $event['extra'] : [];

        $extra['service_name'] = isset($extra['service_name']) ?: $this->config['service_name'];
        $extra['uid'] = isset($extra['uid']) ?: getmypid();
        $extra['threadId'] = isset($extra['threadId']) ?: (defined("ZEUS_THREAD_ID") ? ZEUS_THREAD_ID : 1);
        $extra['logger'] = isset($extra['logger']) ?: $this->detectLogger();
        $extra['microtime'] = isset($extra['microtime']) ?: (int) ($microtime * 1000);

        $event['extra'] = $extra;

        return $event;
    }

    protected function detectLogger() : string
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