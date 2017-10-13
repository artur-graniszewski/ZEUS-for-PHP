<?php

namespace Zeus\Controller;

use Zend\EventManager\EventManager;
use Zend\Log\Logger;
use Zend\Log\LoggerInterface;
use Zend\Mvc\Controller\AbstractActionController;
use Zend\Stdlib\RequestInterface;
use Zend\Stdlib\ResponseInterface;
use Zeus\Kernel\Scheduler\SchedulerEvent;
use Zeus\Kernel\Scheduler\WorkerEvent;
use Zeus\Kernel\Scheduler;
use Zeus\ServerService\Manager;
use Zend\Console\Request as ConsoleRequest;
use Zeus\ServerService\ManagerEvent;
use Zeus\ServerService\ServerServiceInterface;
use Zeus\ServerService\Shared\Logger\DynamicPriorityFilter;

class WorkerController extends AbstractActionController
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

    public function getManager() : Manager
    {
        return $this->manager;
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
        $startParams = $params->get(3, '{}');

        try {
            switch ($action) {
                case 'worker':
                    $this->startWorkerForService($serviceName, json_decode($startParams, true));
                    break;

                case 'scheduler':
                    $this->starSchedulerForService($serviceName, json_decode($startParams, true));
                    break;
            }
        } catch (\Throwable $exception) {
            $this->getLogger()->err(sprintf("%s (%d): %s in %s on line %d",
                get_class($exception),
                $exception->getCode(),
                addcslashes($exception->getMessage(), "\t\n\r\0\x0B"),
                $exception->getFile(),
                $exception->getLine()
            ));
            $this->getLogger()->debug(sprintf("Stack Trace:\n%s", $exception->getTraceAsString()));
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
     * @param array $startParams
     */
    protected function startWorkerForService($serviceName, array $startParams = [])
    {
        $serviceEventManager = $this->manager->getEventManager();
        /** @var Scheduler $scheduler */
        $scheduler = $this->manager->getService($serviceName)->getScheduler();
        $scheduler->getEventManager()->attach(WorkerEvent::EVENT_WORKER_CREATE, function(SchedulerEvent $event) use ($startParams) {
            $event->stopPropagation(true);
            $event->setParams(array_merge($event->getParams(), $startParams));
            $event->setParam('init_process', true);
            $event->setParam('uid', getmypid());
            $event->setParam('threadId', defined("ZEUS_THREAD_ID") ? ZEUS_THREAD_ID : 1);
        }, 100000);

        $serviceEventManager->attach(ManagerEvent::EVENT_MANAGER_LOOP, function(ManagerEvent $event) {
            $event->stopPropagation(true);
        }, 1);

        $serviceEventManager->attach(ManagerEvent::EVENT_SERVICE_STOP, function(ManagerEvent $event) {
            $event->stopPropagation(true);
        }, 1);

        $scheduler->getEventManager()->attach(WorkerEvent::EVENT_WORKER_INIT, function() {
            DynamicPriorityFilter::resetPriority();
        }, WorkerEvent::PRIORITY_FINALIZE + 1);


        $this->manager->startService($serviceName);
        $scheduler->getMultiProcessingModule()->startWorker($startParams);
    }

    /**
     * @param string $serviceName
     * @param array $startParams
     */
    protected function starSchedulerForService($serviceName, array $startParams = [])
    {
        /** @var Scheduler $scheduler */
        $scheduler = $this->manager->getService($serviceName)->getScheduler();

        /** @var EventManager $schedulerEventManager */
        $schedulerEventManager = null;
        $serviceEventManager = $this->manager->getEventManager();
        $serviceEventManager->attach(ManagerEvent::EVENT_MANAGER_LOOP, function(ManagerEvent $event) {
            $event->stopPropagation(true);
        }, 1);

        $serviceEventManager->attach(ManagerEvent::EVENT_SERVICE_STOP, function(ManagerEvent $event) {
            $event->stopPropagation(true);
        }, 1);

        $serviceEventManager->attach(ManagerEvent::EVENT_SERVICE_START, function(ManagerEvent $event)
        use (& $scheduler, & $schedulerEventManager) {

            $scheduler->getEventManager()->attach(WorkerEvent::EVENT_WORKER_CREATE, function(SchedulerEvent $schedulerEvent)
            use (& $schedulerEventManager) {
                $schedulerEventManager = $schedulerEvent->getTarget()->getEventManager();
                if ($schedulerEvent->getParam('server')) {

                    $schedulerEvent->stopPropagation(true);
                    DynamicPriorityFilter::resetPriority();

                    return;
                }
            }, 2000);
        });

        $this->manager->startService($serviceName);

        $schedulerEvent = $scheduler->getSchedulerEvent();
        $schedulerEvent->setName(SchedulerEvent::EVENT_SCHEDULER_START);
        if ($startParams) {
            $schedulerEvent->setParams($startParams);
        }

        $schedulerEventManager->triggerEvent($schedulerEvent);
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
     * @return LoggerInterface
     */
    public function getLogger() : LoggerInterface
    {
        return $this->logger;
    }

    /**
     * @param LoggerInterface $logger
     * @return $this
     */
    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;

        return $this;
    }
}