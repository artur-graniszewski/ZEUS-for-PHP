<?php

namespace Zeus\Kernel\ProcessManager\Plugin;

use Zend\EventManager\EventInterface;
use Zend\EventManager\EventManagerInterface;
use Zend\EventManager\ListenerAggregateInterface;
use Zeus\Kernel\ProcessManager\Helper\AddUnitsToNumbers;
use Zeus\Kernel\ProcessManager\SchedulerEvent;

class ProcessTitle implements ListenerAggregateInterface
{
    use AddUnitsToNumbers;

    /** @var EventManagerInterface */
    protected $events;

    /** @var mixed[] */
    protected $eventHandles = [];

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
     * @param int $priority
     */
    public function attach(EventManagerInterface $events, $priority = 0)
    {
        if (function_exists('cli_get_process_title') && function_exists('cli_set_process_title')) {
            $this->eventHandles[] = $events->attach(SchedulerEvent::EVENT_PROCESS_CREATE, [$this, 'onProcessStarting'], $priority);
            $this->eventHandles[] = $events->attach(SchedulerEvent::EVENT_PROCESS_WAITING, [$this, 'onProcessWaiting'], $priority);
            $this->eventHandles[] = $events->attach(SchedulerEvent::EVENT_PROCESS_TERMINATE, [$this, 'onProcessTerminate'], $priority);
            $this->eventHandles[] = $events->attach(SchedulerEvent::EVENT_PROCESS_LOOP, [$this, 'onProcessWaiting'], $priority);
            $this->eventHandles[] = $events->attach(SchedulerEvent::EVENT_PROCESS_RUNNING, [$this, 'onProcessRunning'], $priority);
            $this->eventHandles[] = $events->attach(SchedulerEvent::INTERNAL_EVENT_KERNEL_START, [$this, 'onServerStart'], $priority);
            $this->eventHandles[] = $events->attach(SchedulerEvent::EVENT_SCHEDULER_START, [$this, 'onSchedulerStart'], $priority);
            $this->eventHandles[] = $events->attach(SchedulerEvent::EVENT_SCHEDULER_STOP, [$this, 'onServerStop'], $priority);
            $this->eventHandles[] = $events->attach(SchedulerEvent::EVENT_SCHEDULER_LOOP, [$this, 'onSchedulerLoop'], $priority);
        }
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

        list($eventType, $taskType, $status) = explode('_', $function, 3);

        if ($eventType === 'INTERNAL') {

            return;
        }

        if ($event->getParam('cpu_usage') !== null || $event->getParam('requests_finished') !== null) {
            $this->setTitle(sprintf("%s %s [%s] %s req done, %s rps, %d%% CPU usage",
                $taskType,
                $event->getParam('service_name'),
                $status,
                $this->addUnitsToNumber($event->getParam('requests_finished')),
                $this->addUnitsToNumber($event->getParam('requests_per_second')),
                $event->getParam('cpu_usage')
            ));

            return;
        }

        $this->setTitle(sprintf("%s %s [%s]",
            $taskType,
            $event->getParam('service_name'),
            $status
        ));
    }

    /**
     * Detach all previously attached listeners
     *
     * @param EventManagerInterface $events
     * @return void
     */
    public function detach(EventManagerInterface $events)
    {
        foreach ($this->eventHandles as $handle) {
            $events->detach($handle);
        }
    }
}