<?php

namespace ZeusTest\Services\Shared;

use PHPUnit\Framework\TestCase;
use Zeus\Kernel\Scheduler;
use Zeus\Kernel\Scheduler\Status\WorkerState;
use Zeus\Kernel\Scheduler\WorkerEvent;
use Zeus\ServerService\Http\Config;
use Zeus\ServerService\Http\Message\Message;
use Zeus\ServerService\Shared\Networking\Service\RegistratorService;
use Zeus\ServerService\Shared\Networking\GatewayMessageBroker;
use ZeusTest\Helpers\ZeusFactories;
use Zeus\Kernel\Scheduler\Command\CreateWorker;
use Zeus\Kernel\Scheduler\Command\InitializeWorker;

/**
 * Class GatewayStrategyTest
 * @package ZeusTest\Services\Shared
 * @runTestsInSeparateProcesses true
 * @preserveGlobalState disabled
 */
class GatewayStrategyTest extends TestCase
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
        $broker = new GatewayMessageBroker($this->config, new Message(function() {}), $scheduler->getLogger());
        $events = $scheduler->getEventManager();
        $broker->attach($events);

        $events->attach(CreateWorker::class, function(Scheduler\WorkerEvent $e) {
            $e->setParam('initWorker', true);
        }, 100000);

        $events->attach(InitializeWorker::class, function(Scheduler\WorkerEvent $e) use ($broker, &$initPassed) {
            $this->assertEquals($broker->getRegistrator()->getRegistratorAddress(), $e->getParam(RegistratorService::IPC_ADDRESS_EVENT_PARAM), 'Registrator address should be passed as event param');
            $initPassed = true;
            $e->setParam(RegistratorService::IPC_ADDRESS_EVENT_PARAM, "tcp://127.0.0.1:10");
            $e->getWorker()->setCode(WorkerState::EXITING);
            $e->stopPropagation(true);
        }, WorkerEvent::PRIORITY_INITIALIZE - 1);

        $events->attach(Scheduler\WorkerEvent::EVENT_EXIT, function(Scheduler\WorkerEvent $e) {

            $e->stopPropagation(true);
        }, 100000);

        $events->attach(InitializeWorker::class, function(Scheduler\WorkerEvent $e) use ($broker, &$counter) {
            $this->assertEquals($broker->getRegistrator()->getRegistratorAddress(), $e->getParam(RegistratorService::IPC_ADDRESS_EVENT_PARAM), 'Registrator address should be passed as event param');
            $this->assertEquals("tcp://127.0.0.1:10", $broker->getRegistrator()->getRegistratorAddress(), "Registrator address should have been altered by CreateWorker::class");
            $counter++;
            $e->stopPropagation(true);
        }, WorkerEvent::PRIORITY_INITIALIZE - 1);

        $scheduler->start(false);
        $this->assertTrue($initPassed, 'All callbacks should be executed');
    }
}