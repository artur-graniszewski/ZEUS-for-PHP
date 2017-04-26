<?php

namespace Zeus\Controller;

use Zend\Console\Console;
use Zend\Log\LoggerInterface;
use Zend\Mvc\Controller\AbstractActionController;
use Zend\Stdlib\RequestInterface;
use Zend\Stdlib\ResponseInterface;
use Zeus\Kernel\ProcessManager\Process;
use Zeus\Kernel\ProcessManager\Scheduler;
use Zeus\Kernel\ProcessManager\SchedulerEvent;
use Zeus\Kernel\ProcessManager\Status\SchedulerStatusView;
use Zeus\ServerService\Manager;
use Zend\Console\Request as ConsoleRequest;
use Zeus\ServerService\ManagerEvent;
use Zeus\ServerService\ServerServiceInterface;

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
     * @param Manager $manager
     * @param LoggerInterface $logger
     */
    public function __construct(array $config, Manager $manager, LoggerInterface $logger)
    {
        $this->config = $config;
        $this->manager = $manager;
        $this->logger = $logger;
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

        pcntl_signal(SIGTERM, [$this, 'stopApplication']);
        pcntl_signal(SIGINT, [$this, 'stopApplication']);
        pcntl_signal(SIGTSTP, [$this, 'stopApplication']);

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
        } catch (\Exception $exception) {
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
     * @param bool $autoStartOnly
     * @return string[]
     */
    protected function getServices($serviceName = null, $autoStartOnly = false)
    {
        if ($this->reportBrokenServices($serviceName)) {
            return [];
        }

        return $serviceName
            ?
            [$serviceName]
            :
            $this->manager->getServiceList($autoStartOnly);
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
        $schedulerEvent = null;
        $schedulerEventManager = null;

        $this->manager->getEventManager()->attach(ManagerEvent::EVENT_MANAGER_LOOP, function(ManagerEvent $event) {
            $event->stopPropagation(true);
        }, 1);

        $this->manager->getEventManager()->attach(ManagerEvent::EVENT_SERVICE_START, function(ManagerEvent $event)
        use (& $schedulerEventManager, & $schedulerEvent) {
            $service = $event->getService();
            /** @var Scheduler $scheduler */
            $scheduler = $service->getScheduler();
            // block starting new scheduler
            $scheduler->getEventManager()->attach(SchedulerEvent::EVENT_SCHEDULER_START, function(SchedulerEvent $event) {
                $event->stopPropagation(true);
            }, -5000);

            $scheduler->getEventManager()->attach(SchedulerEvent::EVENT_PROCESS_CREATE, function(SchedulerEvent $_schedulerEvent)
            use (& $schedulerEventManager, & $schedulerEvent) {
                $_schedulerEvent->stopPropagation(true);
                $schedulerEvent = $_schedulerEvent;
                $schedulerEventManager = $_schedulerEvent->getScheduler()->getEventManager();
            }, 2);

        });

        $this->manager->startService($serviceName);
        $schedulerEvent->setName(SchedulerEvent::EVENT_SCHEDULER_START);
        $schedulerEventManager->triggerEvent($schedulerEvent);

        $schedulerEvent->setName(SchedulerEvent::EVENT_PROCESS_INIT);
        $schedulerEventManager->triggerEvent($schedulerEvent);
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
        use (& $schedulerEventManager, & $schedulerEvent) {
            $service = $event->getService();
            /** @var Scheduler $scheduler */
            $scheduler = $service->getScheduler();
            // block starting new scheduler
//            $scheduler->getEventManager()->attach(SchedulerEvent::EVENT_SCHEDULER_START, function(SchedulerEvent $event) {
//                $event->stopPropagation(true);
//            }, -5000);

            $scheduler->getEventManager()->attach(SchedulerEvent::EVENT_PROCESS_CREATE, function(SchedulerEvent $_schedulerEvent)
            use (& $schedulerEventManager, & $schedulerEvent) {
                $schedulerEvent = $_schedulerEvent;
                $schedulerEventManager = $_schedulerEvent->getScheduler()->getEventManager();
                if ($_schedulerEvent->getParam('server')) {
                    $_schedulerEvent->stopPropagation(true);

                    return;
                }

                trigger_error("FORK");
            }, 2);

        });

        $this->manager->startService($serviceName);
        $schedulerEvent->setName(SchedulerEvent::EVENT_SCHEDULER_START);
        $schedulerEventManager->triggerEvent($schedulerEvent);

        $schedulerEvent->setParam('go', 1);
        $schedulerEvent->setName(SchedulerEvent::EVENT_PROCESS_INIT);
        $schedulerEvent->setParam('server', true);
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