<?php

namespace Zeus\ServerService\Memcache;

use React\EventLoop\StreamSelectLoop;
use Zeus\Kernel\ProcessManager\Process;
use Zeus\Kernel\ProcessManager\SchedulerEvent;
use Zeus\ServerService\Memcache\Message\Message;
use Zeus\ServerService\Shared\AbstractServerService;
use Zeus\ServerService\Shared\React\ReactEventSubscriber;
use Zeus\ServerService\Shared\React\ReactIoServer;
use Zeus\ServerService\Shared\React\ReactServer;

class Service extends AbstractServerService
{
    /** @var Process */
    protected $process;

    /** @var Message */
    protected $message;

    public function start()
    {
        $this->getScheduler()->getEventManager()->attach(SchedulerEvent::EVENT_PROCESS_INIT, function(SchedulerEvent $event) {
            $this->process = $event->getProcess();
        });

        $this->config['logger'] = get_class();

        $this->createReactLoop($this->message);
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
     * @param Message $messageComponent
     * @return $this
     * @throws \React\Socket\ConnectionException
     */
    protected function createReactLoop(Message $messageComponent)
    {
        $config = new Config($this->getConfig());

        $this->logger->info(sprintf('Launching Memcache server on %s%s', $config->getListenAddress(), $config->getListenPort() ? ':' . $config->getListenPort(): ''));
        $loop = new StreamSelectLoop();
        $reactServer = new ReactServer($loop);
        $reactServer->listen($config->getListenPort(), $config->getListenAddress());
        $loop->removeStream($reactServer->master);

        $server = new ReactIoServer($messageComponent, $reactServer, $loop);
        $reactSubscriber = new ReactEventSubscriber($loop, $server);
        $reactSubscriber->attach($this->scheduler->getEventManager());

        return $this;
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