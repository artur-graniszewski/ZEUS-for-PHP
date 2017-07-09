<?php

namespace Zeus\ServerService\Async;

use Zeus\Kernel\ProcessManager\Task;
use Zeus\Kernel\ProcessManager\TaskEvent;

use Zeus\ServerService\Async\Message\Message;
use Zeus\ServerService\Shared\AbstractSocketServerService;

class Service extends AbstractSocketServerService
{
    /** @var Task */
    protected $process;
    protected $message;

    public function start()
    {
        if (!class_exists('Opis\Closure\SerializableClosure')) {
            throw new \LogicException("Async service failed: serialization module is missing");
        }

        $this->getScheduler()->getEventManager()->getSharedManager()->attach('*', TaskEvent::EVENT_PROCESS_INIT, function(TaskEvent $event) {
            $this->process = $event->getTarget();
        });

        $this->config['logger'] = get_class();

        $config = new Config($this->getConfig());
        $this->getServer(new Message(), $config);
        parent::start();

        return $this;
    }

    /**
     * @return Task
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