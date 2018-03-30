<?php

namespace Zeus\ServerService\Async;

use LogicException;
use Zend\Log\LoggerInterface;
use Zeus\Kernel\Scheduler\WorkerEvent;
use Zeus\Kernel\SchedulerInterface;
use Zeus\ServerService\Async\Message\Message;
use Zeus\ServerService\Shared\AbstractSocketServerService;

class Service extends AbstractSocketServerService
{
    private $message;

    public function __construct(array $config = [], SchedulerInterface $scheduler, LoggerInterface $logger)
    {
        parent::__construct($config, $scheduler, $logger);

        $config = new Config($this->getConfig());
        $this->getServer(new Message(), $config);
    }


    public function start()
    {
        if (!class_exists('Opis\Closure\SerializableClosure')) {
            throw new LogicException("Async service failed: serialization module is missing");
        }

        $this->getScheduler()->getEventManager()->attach(WorkerEvent::EVENT_INIT, function(WorkerEvent $event) {
            $this->worker = $event->getTarget();
        });

        $config = $this->getConfig();
        $config['logger'] = get_class();
        $this->setConfig($config);

        parent::start();
    }

    public function setMessageComponent(Message $message)
    {
        $this->message = $message;
    }
}