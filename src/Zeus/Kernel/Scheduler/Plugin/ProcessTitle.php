<?php

namespace Zeus\Kernel\Scheduler\Plugin;

use Zend\EventManager\EventInterface;
use Zend\EventManager\EventManagerInterface;
use Zend\EventManager\ListenerAggregateInterface;
use Zeus\Kernel\Scheduler\Command\StartScheduler;
use Zeus\Kernel\Scheduler\Helper\AddUnitsToNumbers;
use Zeus\Kernel\Scheduler\WorkerEvent;
use Zeus\Kernel\Scheduler\SchedulerEvent;

use function cli_set_process_title;
use function function_exists;
use function strtolower;
use function sprintf;
use function preg_replace;
use function defined;
use function explode;
use function posix_getuid;
use Zeus\Kernel\Scheduler\Command\TerminateWorker;
use Zeus\Kernel\Scheduler\Command\InitializeWorker;
use Zeus\Kernel\Scheduler\Event\SchedulerLoopRepeated;
use Zeus\Kernel\Scheduler\Event\WorkerProcessingStarted;
use Zeus\Kernel\Scheduler\Event\WorkerProcessingFinished;

class ProcessTitle implements ListenerAggregateInterface
{
    use AddUnitsToNumbers;

    /** @var mixed[] */
    private $eventHandles = [];

    protected function setTitle(string $title)
    {
        cli_set_process_title('zeus ' . $title);
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


        $this->eventHandles[] = $events->attach(InitializeWorker::class, [$this, 'onWorkerStarting'], WorkerEvent::PRIORITY_INITIALIZE + 200000);
        $this->eventHandles[] = $events->attach(WorkerProcessingFinished::class, [$this, 'onWorkerWaiting'], $priority);
        $this->eventHandles[] = $events->attach(TerminateWorker::class, [$this, 'onWorkerTerminate'], $priority);
        $this->eventHandles[] = $events->attach(WorkerProcessingStarted::class, [$this, 'onWorkerRunning'], $priority);
        $this->eventHandles[] = $events->attach(SchedulerEvent::INTERNAL_EVENT_KERNEL_START, [$this, 'onKernelStart'], $priority);
        $this->eventHandles[] = $events->attach(StartScheduler::class, [$this, 'onSchedulerStart'], $priority);
        $this->eventHandles[] = $events->attach(SchedulerEvent::EVENT_STOP, [$this, 'onSchedulerStop'], $priority);
        $this->eventHandles[] = $events->attach(SchedulerLoopRepeated::class, [$this, 'onSchedulerLoop'], $priority);
    }

    private function isSupported() : bool
    {
        if (PHP_OS === 'Darwin') {
            // only root can change the process name...
            if (!function_exists('posix_getuid') || posix_getuid() !== 0) {
                return false;
            }
        }

        if (defined('HHVM_VERSION')) {
            return false;
        }
        return true;
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
            $events->detach($handle);
        }
    }
}