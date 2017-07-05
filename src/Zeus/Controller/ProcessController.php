<?php

namespace Zeus\Controller;

use Zend\Log\Logger;
use Zend\Log\LoggerInterface;
use Zend\Mvc\Controller\AbstractActionController;
use Zend\Stdlib\RequestInterface;
use Zend\Stdlib\ResponseInterface;
use Zeus\Kernel\ProcessManager\ProcessEvent;
use Zeus\Kernel\ProcessManager\Scheduler;
use Zeus\Kernel\ProcessManager\SchedulerEvent;
use Zeus\ServerService\Manager;
use Zend\Console\Request as ConsoleRequest;
use Zeus\ServerService\ManagerEvent;
use Zeus\ServerService\ServerServiceInterface;
use Zeus\ServerService\Shared\Logger\DynamicPriorityFilter;

class ProcessController extends AbstractActionController
{
    /** @var mixed[] */
    protected $config;

    /** @var Manager */
    protected $manager;

    /** @var ServerServiceInterface[] */
    protected $services = [];

    /** @var LoggerInterface */
    protected $logger;

    /** @var int */
    protected $servicesRunning = 0;

    /**
     * ZeusController constructor.
     * @param mixed[] $config
     */
    public function __construct(array $config)
    {
        $this->config = $config;
        DynamicPriorityFilter::overridePriority(Logger::ERR);
    }

    /**
     * @param Manager $manager
     * @return $this
     */
    public function setManager(Manager $manager)
    {
        $this->manager = $manager;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function dispatch(RequestInterface $request, ResponseInterface $response = null)
    {
        if (!$request instanceof ConsoleRequest) {
            throw new \InvalidArgumentException(sprintf(
                '%s can only dispatch requests in a console environment',
                get_called_class()
            ));
        }

        /** @var \Zend\Stdlib\Parameters $params */
        $params = $request->getParams();

        $action = $params->get(1);
        $serviceName = $params->get(2);

        try {
            switch ($action) {
                case 'process':
                    $this->starProcessForService($serviceName);
                    break;

                case 'scheduler':
                    $this->starSchedulerForService($serviceName);
                    break;
            }
        } catch (\Throwable $exception) {
            $this->logger->err(sprintf("Exception (%d): %s in %s on line %d",
                $exception->getCode(),
                addcslashes($exception->getMessage(), "\t\n\r\0\x0B"),
                $exception->getFile(),
                $exception->getLine()
            ));
            $this->logger->debug(sprintf("Stack Trace:\n%s", $exception->getTraceAsString()));
            $this->doExit($exception->getCode() > 0 ? $exception->getCode() : 500);
        }
    }

    /**
     * @param int $code
     */
    protected function doExit($code)
    {
        exit($code);
    }

    /**
     * @param string $serviceName
     * @return bool
     */
    protected function reportBrokenServices($serviceName)
    {
        $result = false;
        $brokenServices = $this->manager->getBrokenServices();

        $services = $serviceName !== null ? [$serviceName] : array_keys($brokenServices);

        foreach ($services as $serviceName) {
            if ($serviceName && isset($brokenServices[$serviceName])) {
                /** @var \Exception $exception */
                $exception = $brokenServices[$serviceName];
                $exceptionMessage = $exception->getPrevious() ? $exception->getPrevious()->getMessage() : $exception->getMessage();
                $this->logger->err("Service \"$serviceName\" is broken: " . $exceptionMessage);
                $result = true;
            }
        }

        return $result;
    }

    /**
     * @param string $serviceName
     */
    protected function starProcessForService($serviceName)
    {
        /** @var Scheduler $scheduler */
        $scheduler = $this->manager->getService($serviceName)->getScheduler();
        $scheduler->getEventManager()->getSharedManager()->attach('*', SchedulerEvent::EVENT_PROCESS_CREATE, function(SchedulerEvent $event) {
            $event->stopPropagation(true);
            $event->setParam('init_process', true);
        }, 100000);

        $scheduler->getEventManager()->getSharedManager()->attach('*', ProcessEvent::EVENT_PROCESS_INIT, function() {
            DynamicPriorityFilter::resetPriority();
        }, ProcessEvent::PRIORITY_FINALIZE + 1);

        // @todo: below is a thread code
//        $scheduler->getEventManager()->getSharedManager()->attach('*', ProcessEvent::EVENT_PROCESS_LOOP,
//            function(ProcessEvent $event) use ($scheduler) {
//
//            trigger_error(\Thread::getCurrentThreadId() . " LOOP " . posix_getpid());
//            if (!file_exists($scheduler->getPidFile()) || file_get_contents($scheduler->getPidFile()) != getmypid()) {
//                echo "CLOSE THREAD!\n";
//                $event->getTarget()->getStatus()->incrementNumberOfFinishedTasks(100000);
//            }
//        }, ProcessEvent::PRIORITY_FINALIZE + 1);

        $this->manager->startService($serviceName);
        $scheduler->getProcessService()->start();
    }

    /**
     * @param string $serviceName
     */
    protected function starSchedulerForService($serviceName)
    {
        $schedulerEvent = null;
        $schedulerEventManager = null;
        $this->manager->getEventManager()->attach(ManagerEvent::EVENT_MANAGER_LOOP, function(ManagerEvent $event) {
            $event->stopPropagation(true);
        }, 1);

        $this->manager->getEventManager()->attach(ManagerEvent::EVENT_SERVICE_START, function(ManagerEvent $event)
        use (& $scheduler, & $schedulerEventManager, & $schedulerEvent) {
            $service = $event->getService();
            /** @var Scheduler $scheduler */
            $scheduler = $service->getScheduler();

            $scheduler->getEventManager()->getSharedManager()->attach('*', SchedulerEvent::EVENT_PROCESS_CREATE, function(SchedulerEvent $_schedulerEvent)
            use (& $schedulerEventManager, & $schedulerEvent) {

                $schedulerEvent = $_schedulerEvent;
                $schedulerEventManager = $_schedulerEvent->getTarget()->getEventManager();
                if ($_schedulerEvent->getParam('server')) {
                    $_schedulerEvent->stopPropagation(true);

                    return;
                }
            }, 2000);

            // @todo: below is a thread code
//            $scheduler->getEventManager()->getSharedManager()->attach('*', SchedulerEvent::EVENT_SCHEDULER_LOOP,
//                function(SchedulerEvent $event) use ($scheduler) {
//                    trigger_error(\Thread::getCurrentThreadId() . " S LOOP");
//                    if (!file_exists($scheduler->getPidFile()) || file_get_contents($scheduler->getPidFile()) != getmypid()) {
//                        echo "CLOSE SCHEDULER!\n";
//                        $scheduler->setSchedulerActive(false);
//                    }
//                }, ProcessEvent::PRIORITY_FINALIZE + 1);

        });

        $this->manager->startService($serviceName);
        $schedulerEvent->setName(SchedulerEvent::EVENT_SCHEDULER_START);
        $schedulerEvent->setTarget($scheduler);
        $schedulerEventManager->triggerEvent($schedulerEvent);

        $schedulerEvent->setParam('go', 1);
    }


    /**
     * @param ServerServiceInterface[] $services
     * @param bool $mustBeRunning
     * @throws \Exception
     */
    protected function stopServices($services, $mustBeRunning)
    {
        $servicesLeft = $this->manager->stopServices($services, $mustBeRunning);

        if ($servicesLeft === 0) {
            $this->doExit(0);
        }

        $this->doExit(417);
    }

    public function stopApplication()
    {
        $this->stopServices($this->services, false);
    }

    /**
     * @return LoggerInterface|Logger
     */
    public function getLogger()
    {
        return $this->logger;
    }

    /**
     * @param LoggerInterface $logger
     * @return $this
     */
    public function setLogger($logger)
    {
        $this->logger = $logger;

        return $this;
    }
}