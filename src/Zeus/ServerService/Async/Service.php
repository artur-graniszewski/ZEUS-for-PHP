<?php

namespace Zeus\ServerService\Async;

use Zeus\Kernel\ProcessManager\Process;
use Zeus\Kernel\ProcessManager\ProcessEvent;

use Zeus\ServerService\Async\Message\Message;
use Zeus\ServerService\Shared\AbstractSocketServerService;

class Service extends AbstractSocketServerService
{
    /** @var Process */
    protected $process;
    protected $message;

    public function start()
    {
        if (!class_exists('Opis\Closure\SerializableClosure')) {
            throw new \LogicException("Async service failed: serialization module is missing");
        }

        $this->getScheduler()->getEventManager()->getSharedManager()->attach('*', ProcessEvent::EVENT_PROCESS_INIT, function(ProcessEvent $event) {
            $this->process = $event->getTarget();
        });

        $this->config['logger'] = get_class();

        $config = new Config($this->getConfig());
        $this->getServer(new Message(), $config);
        parent::start();

        return $this;
    }

    /**
     * @return Process
     */
    public function getProcess()
    {
        return $this->process;
    }

    /**
     * @param Message $message
     * @return $this
     */
    public function setMessageComponent($message)
    {
        $this->message = $message;

        return $this;
    }
}