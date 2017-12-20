<?php

namespace Zeus\Kernel\Scheduler\Plugin;

use Zend\EventManager\EventInterface;
use Zend\EventManager\EventManagerInterface;
use Zend\EventManager\ListenerAggregateInterface;
use Zeus\Kernel\Scheduler\Helper\AddUnitsToNumbers;
use Zeus\Kernel\Scheduler\WorkerEvent;
use Zeus\Kernel\Scheduler\SchedulerEvent;

use function cli_set_process_title;
use function function_exists;
use function strtolower;
use function preg_replace;

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
        if (!$this->isSupported()) {
            return;
        }

        $events = $events->getSharedManager();
        $this->eventHandles[] = $events->attach('*', WorkerEvent::EVENT_CREATE, [$this, 'onWorkerStarting'], $priority);
        $this->eventHandles[] = $events->attach('*', WorkerEvent::EVENT_WAITING, [$this, 'onWorkerWaiting'], $priority);
        $this->eventHandles[] = $events->attach('*', WorkerEvent::EVENT_TERMINATE, [$this, 'onWorkerTerminate'], $priority);
        $this->eventHandles[] = $events->attach('*', WorkerEvent::EVENT_RUNNING, [$this, 'onWorkerRunning'], $priority);
        $this->eventHandles[] = $events->attach('*', SchedulerEvent::INTERNAL_EVENT_KERNEL_START, [$this, 'onKernelStart'], $priority);
        $this->eventHandles[] = $events->attach('*', SchedulerEvent::EVENT_START, [$this, 'onSchedulerStart'], $priority);
        $this->eventHandles[] = $events->attach('*', SchedulerEvent::EVENT_STOP, [$this, 'onSchedulerStop'], $priority);
        $this->eventHandles[] = $events->attach('*', SchedulerEvent::EVENT_LOOP, [$this, 'onSchedulerLoop'], $priority);
    }

    private function isSupported() : bool
    {
        if (PHP_OS === 'Darwin') {
            // only root can change the process name...
            if (!function_exists('posix_getuid') || posix_getuid() != 0) {
                return false;
            }
        }

        return function_exists('cli_get_process_title') && function_exists('cli_set_process_title');
    }

    /**
     * @param string $function
     * @param mixed[] $args
     */
    public function __call(string $function, array $args)
    {
        /** @var EventInterface $event */
        $event = $args[0];

        $function = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $function));

        list($eventType, $taskType, $status) = explode('_', $function, 3);

        if ($eventType === 'INTERNAL') {
            //return;
        }

        $statusArray = $event->getParam('status') ? $event->getParam('status')->toArray() : [];

        if (isset($statusArray['cpu_usage']) || isset($statusArray['requests_finished'])) {
            $this->setTitle(sprintf("%s %s [%s] %s req done, %s rps, %d%% CPU usage",
                $taskType,
                $statusArray['service_name'],
                $status,
                $this->addUnitsToNumber($statusArray['requests_finished']),
                $this->addUnitsToNumber($statusArray['requests_per_second']),
                $statusArray['cpu_usage']
            ));

            return;
        }

        if ($event->getParam('service_name')) {
            $this->setTitle(sprintf("%s %s [%s]",
                $taskType,
                $event->getParam('service_name'),
                $status
            ));
        } else {
            $this->setTitle("kernel [running]");
        }
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
            //$events->getSharedManager()->detach($handle);
        }
    }
}