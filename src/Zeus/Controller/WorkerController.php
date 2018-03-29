<?php

namespace Zeus\Controller;

use Throwable;
use Zend\Log\Logger;
use Zend\Stdlib\RequestInterface;
use Zend\Stdlib\ResponseInterface;
use Zeus\Kernel\Scheduler\MultiProcessingModule\ModuleWrapper;
use Zeus\Kernel\Scheduler\WorkerEvent;
use Zeus\Kernel\SchedulerInterface;
use Zeus\Kernel\System\Runtime;
use Zeus\ServerService\Shared\Logger\DynamicPriorityFilter;
use Zeus\ServerService\Shared\Logger\ExceptionLoggerTrait;

use function defined;
use function getmypid;

class WorkerController extends AbstractController
{
    use ExceptionLoggerTrait;

    /**
     * ZeusController constructor.
     * @param mixed[] $config
     */
    public function __construct(array $config)
    {
        DynamicPriorityFilter::overridePriority(Logger::ERR);

        parent::__construct($config);
    }

    /**
     * {@inheritdoc}
     */
    public function dispatch(RequestInterface $request, ResponseInterface $response = null)
    {
        $this->checkIfConsole($request);

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
            $this->logException($exception, $this->getLogger());
            Runtime::exit($exception->getCode() > 0 ? $exception->getCode() : 500);
        }
    }

    /**
     * @param string $serviceName
     * @param mixed[] $startParams
     */
    private function startWorkerForService(string $serviceName, array $startParams = [])
    {
        /** @var SchedulerInterface $scheduler */
        $scheduler = $this->getServiceManager()->getService($serviceName)->getScheduler();

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
        DynamicPriorityFilter::resetPriority();

        $startParams[SchedulerInterface::WORKER_SERVER] = true;
        $this->triggerWorkerEvent($serviceName, $startParams);
    }

    private function triggerWorkerEvent(string $serviceName, array $startParams)
    {
        /** @var SchedulerInterface $scheduler */
        $scheduler = $this->getServiceManager()->getService($serviceName)->getScheduler();

        $event = $scheduler->getMultiProcessingModule()->getWrapper()->getWorkerEvent();
        $event->setParam(SchedulerInterface::WORKER_SERVER, true);

        $worker = $event->getWorker();
        $worker->setUid(defined("ZEUS_THREAD_ID") ? ZEUS_THREAD_ID : $worker->getProcessId());
        $worker->setProcessId(getmypid());
        $worker->setThreadId(defined("ZEUS_THREAD_ID") ? ZEUS_THREAD_ID : 1);
        $event->setWorker($worker);
        $event->setTarget($worker);
        $event->setParams($startParams);
        if (defined("ZEUS_THREAD_IPC_ADDRESS")) {
            $event->setParam(ModuleWrapper::ZEUS_IPC_ADDRESS_PARAM, ZEUS_THREAD_IPC_ADDRESS);
        }
        $event->setName(WorkerEvent::EVENT_INIT);
        $scheduler->getEventManager()->triggerEvent($event);
    }
}