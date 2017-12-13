<?php

namespace Zeus\ServerService\Async;

use Zend\Log\LoggerInterface;
use Zeus\Kernel\Scheduler;
use Zeus\Kernel\Scheduler\Worker;
use Zeus\Kernel\Scheduler\WorkerEvent;

use Zeus\ServerService\Async\Message\Message;
use Zeus\ServerService\Shared\AbstractSocketServerService;

class Service extends AbstractSocketServerService
{
    /** @var Worker */
    protected $worker;
    protected $message;

    public function __construct(array $config = [], Scheduler $scheduler, LoggerInterface $logger)
    {
        parent::__construct($config, $scheduler, $logger);

        $config = new Config($this->getConfig());
        $this->getServer(new Message(), $config);
    }


    public function start()
    {
        if (!class_exists('Opis\Closure\SerializableClosure')) {
            throw new \LogicException("Async service failed: serialization module is missing");
        }

        $this->getScheduler()->getEventManager()->getSharedManager()->attach('*', WorkerEvent::EVENT_WORKER_INIT, function(WorkerEvent $event) {
            $this->worker = $event->getTarget();
        });

        $this->config['logger'] = get_class();

        parent::start();
    }

    public function getWorker() : Worker
    {
        return $this->worker;
    }

    public function setMessageComponent(Message $message)
    {
        $this->message = $message;
    }
}