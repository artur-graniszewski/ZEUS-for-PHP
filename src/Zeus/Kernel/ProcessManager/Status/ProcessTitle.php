<?php

namespace Zeus\Kernel\ProcessManager\Status;

use Zend\EventManager\EventInterface;
use Zend\EventManager\EventManagerInterface;
use Zeus\Kernel\ProcessManager\EventsInterface;

class ProcessTitle
{
    /** @var string */
    protected $serviceName;

    /** @var EventManagerInterface */
    protected $events;

    /**
     * @param string $serviceName
     */
    public function _construct($serviceName)
    {
        $this->serviceName = $serviceName;
    }

    /**
     * @param string $title
     * @return $this
     */
    protected function setTitle($title)
    {
        cli_set_process_title('zeus ' . $title);

        return $this;
    }

    /**
     * @param EventManagerInterface $events
     * @return $this
     */
    public function attach(EventManagerInterface $events)
    {
        if (function_exists('cli_get_process_title') && function_exists('cli_set_process_title')) {
            $events->attach(EventsInterface::ON_PROCESS_CREATE, [$this, 'onProcessStarting']);
            $events->attach(EventsInterface::ON_PROCESS_IDLING, [$this, 'onProcessWaiting']);
            $events->attach(EventsInterface::ON_PROCESS_TERMINATE, [$this, 'onProcessTerminate']);
            $events->attach(EventsInterface::ON_PROCESS_LOOP, [$this, 'onProcessWaiting']);
            $events->attach(EventsInterface::ON_PROCESS_RUNNING, [$this, 'onProcessRunning']);
            $events->attach(EventsInterface::ON_SERVER_START, [$this, 'onServerStart']);
            $events->attach(EventsInterface::ON_SCHEDULER_START, [$this, 'onSchedulerStart']);
            $events->attach(EventsInterface::ON_SCHEDULER_STOP, [$this, 'onServerStop']);
            $events->attach(EventsInterface::ON_SCHEDULER_LOOP, [$this, 'onSchedulerLoop']);
        }

        return $this;
    }

    /**
     * @param string $function
     * @param mixed[] $args
     */
    public function __call($function, $args)
    {
        /** @var EventInterface $event */
        $event = $args[0];

        $function = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $function));

        list($junk, $taskType, $status) = explode('_', $function, 3);

        if ($event->getParam('cpu_usage') !== null || $event->getParam('requests_finished') !== null) {
            $this->setTitle(sprintf("%s %s [%s] %s req done, %s rps, %d%% CPU usage",
                $taskType,
                $event->getParam('service_name'),
                $status,
                ProcessState::addUnitsToNumber($event->getParam('requests_finished')),
                ProcessState::addUnitsToNumber($event->getParam('requestsPerSecond')),
                $event->getParam('cpu_usage')
            ));
        } else {
            $this->setTitle(sprintf("%s %s [%s]",
                $taskType,
                $event->getParam('service_name'),
                $status
            ));
        }
    }
}