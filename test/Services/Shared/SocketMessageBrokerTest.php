<?php

namespace ZeusTest\Services\Shared;

use Zeus\Kernel\Scheduler;
use Zeus\Kernel\Scheduler\SchedulerEvent;
use Zeus\ServerService\Http\Config;
use Zeus\ServerService\Http\Message\Message;
use Zeus\ServerService\Shared\Networking\Service\RegistratorService;
use Zeus\ServerService\Shared\Networking\SocketMessageBroker;
use ZeusTest\Helpers\ZeusFactories;

class SocketMessageBrokerTest extends \PHPUnit\Framework\TestCase
{
    use ZeusFactories;

    private $config;
    private $port;

    public function setUp()
    {
        $this->port = 0;
        $this->config = new Config();
        $this->config->setListenAddress('0.0.0.0');
        $this->config->setListenPort($this->port);
    }

    public function testWorkerReceivesIpcAddress()
    {
        $initPassed = false;
        $scheduler = $this->getScheduler(1);
        $this->assertInstanceOf(Scheduler::class, $scheduler);
        $broker = new SocketMessageBroker($this->config, new Message(function() {}));
        $broker->setLogger($scheduler->getLogger());
        $broker->attach($scheduler->getEventManager());

        $events = $scheduler->getEventManager();

        $events->attach(Scheduler\WorkerEvent::EVENT_CREATE, function(Scheduler\WorkerEvent $e) {
            $e->setParam('initWorker', true);
        }, 100000);

        $events->attach(Scheduler\WorkerEvent::EVENT_INIT, function(Scheduler\WorkerEvent $e) use ($broker, &$initPassed) {
            $this->assertEquals($broker->getRegistrator()->getRegistratorAddress(), $e->getParam(RegistratorService::IPC_ADDRESS_EVENT_PARAM), 'Registrator address should be passed as event param');
            $initPassed = true;
            $e->setParam(RegistratorService::IPC_ADDRESS_EVENT_PARAM, "testAddress");
            $e->getWorker()->setIsTerminating(true);
        }, 100000);

        $events->attach(Scheduler\WorkerEvent::EVENT_EXIT, function(Scheduler\WorkerEvent $e) {

            $e->stopPropagation(true);
        }, 100000);

        $events->attach(Scheduler\WorkerEvent::EVENT_LOOP, function(Scheduler\WorkerEvent $e) use ($broker, &$counter) {
            $this->assertEquals($broker->getRegistrator()->getRegistratorAddress(), $e->getParam(RegistratorService::IPC_ADDRESS_EVENT_PARAM), 'Registrator address should be passed as event param');
            $counter++;
            $e->stopPropagation(true);
        }, 100000);

        $scheduler->start(false);
        $this->assertTrue($initPassed, 'All callbacks should be executed');
        $this->assertEquals("testAddress", $broker->getRegistrator()->getRegistratorAddress(), "Registrator address should have been altered by WorkerEvent::EVENT_CREATE");
    }
}