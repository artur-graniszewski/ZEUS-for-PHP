<?php

namespace Zeus\Controller;

use InvalidArgumentException;
use Throwable;
use Zend\Log\Logger;
use Zend\Log\LoggerInterface;
use Zend\Mvc\Controller\AbstractActionController;
use Zend\Stdlib\RequestInterface;
use Zend\Stdlib\ResponseInterface;
use Zeus\Kernel\Scheduler\MultiProcessingModule\ModuleWrapper;
use Zeus\Kernel\Scheduler\Worker;
use Zeus\Kernel\Scheduler\WorkerEvent;
use Zeus\Kernel\Scheduler;
use Zeus\Kernel\System\Runtime;
use Zeus\ServerService\Manager;
use Zend\Console\Request as ConsoleRequest;
use Zeus\ServerService\Shared\Logger\DynamicPriorityFilter;

class WorkerController extends AbstractActionController
{
    /** @var mixed[] */
    private $config;

    /** @var Manager */
    private $manager;

    /** @var LoggerInterface */
    private $logger;

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
            throw new InvalidArgumentException(sprintf(
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
        } catch (Throwable $exception) {
            $this->getLogger()->err(sprintf("%s (%d): %s in %s on line %d",
                get_class($exception),
                $exception->getCode(),
                addcslashes($exception->getMessage(), "\t\n\r\0\x0B"),
                $exception->getFile(),
                $exception->getLine()
            ));
            $this->getLogger()->err(sprintf("Stack Trace:\n%s", $exception->getTraceAsString()));
            Runtime::exit($exception->getCode() > 0 ? $exception->getCode() : 500);
        }
    }

    private function initializeWorker(Worker $worker)
    {
        $worker->setProcessId(getmypid());
        $worker->setThreadId(defined("ZEUS_THREAD_ID") ? ZEUS_THREAD_ID : 1);
        $worker->setUid(defined("ZEUS_THREAD_ID") ? ZEUS_THREAD_ID : getmypid());
    }

    /**
     * @param string $serviceName
     * @param mixed[] $startParams
     */
    private function startWorkerForService(string $serviceName, array $startParams = [])
    {
        /** @var Scheduler $scheduler */
        $scheduler = $this->manager->getService($serviceName)->getScheduler();

        $scheduler->getEventManager()->attach(WorkerEvent::EVENT_INIT, function() {
            DynamicPriorityFilter::resetPriority();
        }, WorkerEvent::PRIORITY_FINALIZE + 1);

        $this->triggerWorkerEvent($serviceName, $startParams);
    }

    /**
     * @param string $serviceName
     * @param mixed[] $startParams
     */
    private function starSchedulerForService(string $serviceName, array $startParams = [])
    {
        $startParams[Scheduler::WORKER_SERVER] = false;
        DynamicPriorityFilter::resetPriority();

        $startParams[Scheduler::WORKER_SERVER] = true;
        $this->triggerWorkerEvent($serviceName, $startParams);
    }

    private function triggerWorkerEvent(string $serviceName, $startParams)
    {
        /** @var Scheduler $scheduler */
        $scheduler = $this->manager->getService($serviceName)->getScheduler();

        $event = $scheduler->getMultiProcessingModule()->getWrapper()->getWorkerEvent();
        $event->setParam(Scheduler::WORKER_SERVER, true);

        $worker = $event->getWorker();
        $worker->setEventManager($scheduler->getEventManager());
        $event->setTarget($worker);
        $this->initializeWorker($worker);
        $event->setParams(array_merge($event->getParams(), $startParams));
        $event->setParam('uid', $worker->getUid());
        $event->setParam('threadId', $worker->getThreadId());
        $event->setParam('processId', $worker->getProcessId());
        if (defined("ZEUS_THREAD_IPC_ADDRESS")) {
            $event->setParam(ModuleWrapper::ZEUS_IPC_ADDRESS_PARAM, ZEUS_THREAD_IPC_ADDRESS);
        }
        $event->setName(WorkerEvent::EVENT_INIT);
        $scheduler->getEventManager()->triggerEvent($event);
    }

    public function getLogger() : LoggerInterface
    {
        return $this->logger;
    }

    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }
}