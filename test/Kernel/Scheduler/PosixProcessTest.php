<?php

namespace ZeusTest\Kernel\Scheduler;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Zend\EventManager\EventManager;
use Zend\EventManager\SharedEventManager;
use Zend\Log\Logger;
use Zend\Log\Writer\Noop;
use Zeus\Kernel\Scheduler\MultiProcessingModule\Factory\MultiProcessingModuleFactory;
use Zeus\Kernel\Scheduler\MultiProcessingModule\ModuleDecorator;
use Zeus\Kernel\Scheduler\MultiProcessingModule\MultiProcessingModuleCapabilities;
use Zeus\Kernel\Scheduler\MultiProcessingModule\PosixProcess;
use Zeus\Kernel\Scheduler\Status\WorkerState;
use Zeus\Kernel\Scheduler\WorkerEvent;
use Zeus\Kernel\Scheduler;
use Zeus\Kernel\Scheduler\SchedulerEvent;
use Zeus\Kernel\SchedulerInterface;
use Zeus\Kernel\System\Runtime;
use ZeusTest\Helpers\PcntlBridgeMock;
use ZeusTest\Helpers\ZeusFactories;
use Zeus\Kernel\Scheduler\Config as TestConfig;
use Zeus\Kernel\Scheduler\Command\CreateWorker;
use Zeus\Kernel\Scheduler\Command\TerminateWorker;
use Zeus\Kernel\Scheduler\Command\TerminateScheduler;
use Zeus\Kernel\Scheduler\Command\InitializeWorker;
use Zeus\Kernel\Scheduler\Event\SchedulerLoopRepeated;
use Zeus\Kernel\Scheduler\Event\WorkerLoopRepeated;

/**
 * Class PosixProcessTest
 * @package ZeusTest\Kernel\Scheduler
 * @runTestsInSeparateProcesses true
 * @preserveGlobalState disabled
 */
class PosixProcessTest extends TestCase
{
    use ZeusFactories;

    /**
     * @param SchedulerInterface $scheduler
     * @return SchedulerEvent
     */
    protected function getEvent(SchedulerInterface $scheduler)
    {
        $rc = new ReflectionClass(Scheduler::class);
        $property = $rc->getProperty('event');
        $property->setAccessible(true);

        return $property->getValue($scheduler);
    }

    /**
     * @param mixed[] $log
     * @param $methodName
     * @return int
     */
    protected function countMethodInExecutionLog($log, $methodName)
    {
        $found = 0;
        foreach ($log as $entry) {
            if (preg_match('~' . $methodName . '$~', $entry[0])) {
                $found++;
            }
        }

        return $found;
    }

    private function getMpm(SchedulerInterface $scheduler) : ModuleDecorator
    {
        $sm = $this->getServiceManager();
        $logger = new Logger();
        $logger->addWriter(new Noop());

        $service = $sm->build(PosixProcess::class, [
            'scheduler_event' => new SchedulerEvent(),
            'worker_event' => new WorkerEvent(),
            'logger_adapter' => $logger,
            'event_manager' => $scheduler->getEventManager()
        ]);

        $this->assertInstanceOf(PosixProcess::class, $service);

        $service = new ModuleDecorator($service);
        $service->setLogger($scheduler->getLogger());
        return $service;
    }

    public function testPosixProcessFactory()
    {
        Runtime::setShutdownHook(function() {
            return true;
        });
        $sm = $this->getServiceManager();
        $scheduler = $this->getScheduler(1);

        $pcntlMock = new PcntlBridgeMock();
        $pcntlMock->setPpid(123456789);
        $pcntlMock->setForkResult(123456);
        PosixProcess::setPcntlBridge($pcntlMock);
        $event = new SchedulerEvent();
        $event->setScheduler($scheduler);
        $sm->setFactory(PosixProcess::class, MultiProcessingModuleFactory::class);
        $events = $scheduler->getEventManager();

        $eventLaunched = false;

        $this->simulateWorkerInit($events);

        $events->attach(SchedulerEvent::EVENT_START, function(SchedulerEvent $event) use (&$eventLaunched) {
            $event->stopPropagation(true);
        }, SchedulerEvent::PRIORITY_INITIALIZE + 1);

        $events->attach(InitializeWorker::class, function(WorkerEvent $event) use (&$eventLaunched) {
            $event->stopPropagation(true);
        }, SchedulerEvent::PRIORITY_INITIALIZE + 1);

        $events->attach(CreateWorker::class, function(WorkerEvent $event) use (&$eventLaunched) {
            //$event->stopPropagation(true);

        }, SchedulerEvent::PRIORITY_INITIALIZE + 1);

        $events->attach(SchedulerEvent::EVENT_STOP, function(SchedulerEvent $event) use (&$eventLaunched) {
            $eventLaunched = true;
            $event->stopPropagation(true);
        }, SchedulerEvent::PRIORITY_FINALIZE + 1);

        $scheduler->start(false);

        $this->assertTrue($eventLaunched, 'EVENT_SCHEDULER_STOP should have been triggered by PosixProcess');
    }

    public function eventProvider()
    {
        return [
            [
                CreateWorker::class,
                123412341234, 123412341234,
                [
                    'pcntlFork' => ['amount' => 1, 'message' => 'Process should be forked'],
                    'pcntlSignal' => ['amount' => 0, 'message' => 'Signal handling should be left intact'],
                ],
                false,
            ],

            [
                CreateWorker::class,
                false, getmypid(),
                [
                    'pcntlFork' => ['amount' => 1, 'message' => 'Process should be forked'],
                    'pcntlSignal' => ['amount' => 5, 'message' => 'Signal handling should be left intact'],
                ],
                true,
            ],
        ];
    }

    /**
     * @dataProvider eventProvider
     * @param $initialEventType
     * @param $forcedForkValue
     * @param $expectedForkValue
     * @param $methodAmounts
     * @param $isInitExpected
     */
    public function testProcessEvents($initialEventType, $forcedForkValue, $expectedForkValue, $methodAmounts, $isInitExpected)
    {
        $em = new EventManager(new SharedEventManager());
        $em->getSharedManager()->attach('*', $initialEventType, function($event) use (&$triggeredEvent) {
            $triggeredEvent = $event;
        }, -10000);

        $pcntlMock = new PcntlBridgeMock();
        $pcntlMock->setForkResult($forcedForkValue);
        PosixProcess::setPcntlBridge($pcntlMock);
        $event = new WorkerEvent();
        $config = new TestConfig([]);
        $config->setServiceName('test');
        $worker = new WorkerState("test");
        $event->setWorker($worker);
        $posixProcess = new ModuleDecorator(new PosixProcess());
        $posixProcess->setEventManager($em);

        $event->setName($initialEventType);
        $em->triggerEvent($event);

        foreach ($methodAmounts as $method => $details) {
            $this->assertEquals($details['amount'], $this->countMethodInExecutionLog($pcntlMock->getExecutionLog(), $method), $details['message']);
        }

        if (!is_null($expectedForkValue)) {
            //$this->assertEquals($expectedForkValue, $triggeredEvent->getWorker()->getUid());
        }

        $this->assertEquals($isInitExpected, $triggeredEvent->getParam('initWorker'));
    }

    public function testProcessTermination()
    {
        if (!defined("SIGKILL")) {
            $this->markTestSkipped("Undefined SIGKILL constant");
        }

        $scheduler = $this->getScheduler(1);
        $em = new EventManager(new SharedEventManager());
        $posixProcess = $this->getMpm($scheduler);
        $posixProcess->setEventManager($em);

        $pcntlMock = new PcntlBridgeMock();
        PosixProcess::setPcntlBridge($pcntlMock);
        $event = new SchedulerEvent();
        $event->setScheduler($scheduler);
        $event->setTarget($scheduler);
        $event->setName(SchedulerEvent::EVENT_START);
        $event->setParam('uid', 123456);
        $em->triggerEvent($event);

        $event = new TerminateWorker();
        $event->setTarget($scheduler);
        $event->setParam('uid', 123456);
        $event->setParam('soft', false);
        $em->triggerEvent($event);

        $logArray = $pcntlMock->getExecutionLog();
        $this->assertEquals(1, $this->countMethodInExecutionLog($logArray, 'posixKill'), 'Kill signal should be sent');
        $this->assertEquals(123456, $logArray[2][1][0], 'Kill signal should be sent to a certain process');
        $this->assertEquals(SIGKILL, $logArray[2][1][1], 'Correct type of kill signal should be sent to a certain process');
        $this->assertEquals(TerminateScheduler::class, $event->getName());
        $pcntlMock->setExecutionLog([]);
    }

    public function testDetectionOfProcessTermination()
    {
        $this->markTestIncomplete("This test fails when executed separately");
        $worker = new WorkerState("test");

        $scheduler = $this->getScheduler(1);
        $em = new EventManager(new SharedEventManager());
        $em->attach(WorkerEvent::EVENT_TERMINATED, function($event) use (&$triggeredEvent) {
            $triggeredEvent = $event;
        });

        $this->simulateWorkerInit($em);

        $pcntlMock = new PcntlBridgeMock();
        $pcntlMock->setPcntlWaitPids([98765]);
        $pcntlMock->setForkResult(98765);

        PosixProcess::setPcntlBridge($pcntlMock);
        $schedulerEvent = new SchedulerLoopRepeated();
        $schedulerEvent->setScheduler($scheduler);
        $workerEvent = new CreateWorker();
        $workerEvent->setWorker($worker);
        $posixProcess = $this->getMpm($scheduler);
        $posixProcess->setWorkerEvent($workerEvent);
        $posixProcess->setEventManager($em);
        $em->triggerEvent($workerEvent);

        $em->triggerEvent($schedulerEvent);

        $this->assertNotNull($triggeredEvent);
        $logArray = $pcntlMock->getExecutionLog();
        $this->assertEquals(2, $this->countMethodInExecutionLog($logArray, 'pcntlWait'), 'Wait for signal should be performed');
        $this->assertEquals(98765, $triggeredEvent->getWorker()->getUid(), 'Correct process UID should be returned on its termination');
    }

    /**
     * @expectedException \Zeus\Kernel\Scheduler\Exception\SchedulerException
     * @expectedExceptionMessage Could not create a descendant process
     */
    public function testExceptionOnForkFailure()
    {
        $worker = new WorkerState("test");

        $scheduler = $this->getScheduler(1);
        $em = new EventManager(new SharedEventManager());
        $em->attach(WorkerEvent::EVENT_TERMINATED, function($event) use (&$triggeredEvent) {
            $triggeredEvent = $event;
        });

        $pcntlMock = new PcntlBridgeMock();
        $pcntlMock->setPcntlWaitPids([98765]);
        $pcntlMock->setForkResult(-1);

        PosixProcess::setPcntlBridge($pcntlMock);
        $schedulerEvent = new SchedulerEvent();
        $schedulerEvent->setScheduler($scheduler);
        $workerEvent = new CreateWorker();
        $workerEvent->setWorker($worker);
        $posixProcess = $this->getMpm($scheduler);
        $posixProcess->setWorkerEvent($workerEvent);
        $posixProcess->setEventManager($em);
        $em->triggerEvent($workerEvent);
    }

    public function signalsProvider()
    {
        return [
            [SIGTERM],
            [SIGINT],
            [SIGHUP],
            [SIGQUIT],
            [SIGTSTP],
        ];
    }

    /**
     * @dataProvider signalsProvider
     * @param $signal
     */
    public function testDetectionOfSchedulerTermination(int $signal)
    {
        $this->markTestSkipped("Signal handling is currently disabled in PosixProcess MPM");
        $em = new EventManager(new SharedEventManager());
        $em->attach(SchedulerEvent::EVENT_STOP, function($event) use (&$triggeredEvent) {
            $triggeredEvent = $event;
        });

        $pcntlMock = new PcntlBridgeMock();
        $pcntlMock->setSignal($signal);
        $pcntlMock->setPpid(1234);

        PosixProcess::setPcntlBridge($pcntlMock);
        $event = new SchedulerLoopRepeated();
        $posixProcess = new ModuleDecorator(new PosixProcess());
        $posixProcess->setEventManager($em);

        $event->setName(SchedulerEvent::EVENT_START);
        $em->triggerEvent($event);

        $pcntlMock->setPpid(12345);
        $em->triggerEvent($event);

        $this->assertNotNull($triggeredEvent);
        $logArray = $pcntlMock->getExecutionLog();
        $this->assertEquals(2, $this->countMethodInExecutionLog($logArray, 'pcntlWait'), 'Wait for signal should be performed');
        $this->assertEquals(2, $this->countMethodInExecutionLog($logArray, 'pcntlSignalDispatch'), 'Signal dispatching should be performed');
        $this->assertEquals(getmypid(), $triggeredEvent->getParam('uid'), 'Correct process UID should be returned on its termination');
    }

    public function testDetectionOfWorkerParentTermination()
    {
        $this->markTestIncomplete();
        $scheduler = $this->getScheduler(1);
        $em = new EventManager(new SharedEventManager());
        $triggeredEvent = null;
        $em->attach(WorkerEvent::EVENT_EXIT, function($event) use (&$triggeredEvent) {
            $triggeredEvent = $event;
        });

        $pcntlMock = new PcntlBridgeMock();
        $pcntlMock->setPpid(1234567890);

        PosixProcess::setPcntlBridge($pcntlMock);
        $event = new WorkerLoopRepeated();
        $posixProcess = $this->getMpm($scheduler);

        $em->triggerEvent($event);

        $this->assertNotNull($triggeredEvent);
        $logArray = $pcntlMock->getExecutionLog();
        $this->assertEquals(2, $this->countMethodInExecutionLog($logArray, 'pcntlWait'), 'Wait for signal should be performed');
        $this->assertEquals(2, $this->countMethodInExecutionLog($logArray, 'pcntlSignalDispatch'), 'Signal dispatching should be performed');
        $this->assertEquals(getmypid(), $triggeredEvent->getParam('uid'), 'Correct process UID should be returned on its termination');
    }

    public function testDetectionOfSchedulersParentTermination()
    {
        $this->markTestIncomplete();
        $scheduler = $this->getScheduler(1);
        $em = new EventManager(new SharedEventManager());
        $triggeredEvent = null;
        $em->attach(SchedulerEvent::EVENT_STOP, function($event) use (&$triggeredEvent) {
            $triggeredEvent = $event;
        });

        $pcntlMock = new PcntlBridgeMock();
        $pcntlMock->setPpid(1234567890);

        PosixProcess::setPcntlBridge($pcntlMock);
        $event = new SchedulerLoopRepeated();
        $posixProcess = $this->getMpm($scheduler);

        //$scheduler->start(false);
        $em->triggerEvent($event);

        $this->assertNotNull($triggeredEvent);
        $logArray = $pcntlMock->getExecutionLog();
        $this->assertEquals(2, $this->countMethodInExecutionLog($logArray, 'pcntlWait'), 'Wait for signal should be performed');
        $this->assertEquals(2, $this->countMethodInExecutionLog($logArray, 'pcntlSignalDispatch'), 'Signal dispatching should be performed');
        $this->assertEquals(getmypid(), $triggeredEvent->getParam('uid'), 'Correct process UID should be returned on its termination');
    }

    public function testDetectionIfPcntlIsSupported()
    {
        $pcntlMock = new PosixProcess\PcntlBridge();
        PosixProcess::setPcntlBridge($pcntlMock);

        $this->assertEquals(extension_loaded('pcntl'), PosixProcess::isSupported(), 'PCNTL should be ' . (extension_loaded('pcntl') ? 'enabled' : 'disabled'));
    }

    public function testDetectionIfPcntlIsSupportedOrNot()
    {
        $pcntlMock = new PcntlBridgeMock();
        PosixProcess::setPcntlBridge($pcntlMock);

        $status = [];

        foreach ([true, false] as $isSupported) {
            $pcntlMock->setIsSupported($isSupported);

            $status[$isSupported] = '';

            $this->assertEquals($isSupported, PosixProcess::isSupported($status[$isSupported]), ('PCNTL should be ' . $isSupported ? 'enabled' : 'disabled'));
        }

        $this->assertEquals("PCNTL extension is required by PosixProcess but disabled in PHP", $status[false], 'Error message should be returned if MPM driver is not supported');
        $this->assertEquals("", $status[true], 'No error message should be returned if MPM driver is supported');
    }

    public function testIfSetSsidIsPerformedOnStartup()
    {
        $em = new EventManager(new SharedEventManager());
        $pcntlMock = new PcntlBridgeMock();

        PosixProcess::setPcntlBridge($pcntlMock);
        $event = new SchedulerEvent();
        $posixProcess = new ModuleDecorator(new PosixProcess());
        $posixProcess->setLogger($this->getDummyLogger());
        $posixProcess->setEventManager($em);

        $event->setName(SchedulerEvent::INTERNAL_EVENT_KERNEL_START);
        $em->triggerEvent($event);

        $event->setName(SchedulerEvent::EVENT_START);
        $em->triggerEvent($event);

        $logArray = $pcntlMock->getExecutionLog();
        $this->assertEquals(1, $this->countMethodInExecutionLog($logArray, 'posixSetsid'), 'Master process should be set as session leader');
    }

    public function testPosixCapabilities()
    {
        $posixProcess = new PosixProcess();
        $capabilities = $posixProcess::getCapabilities();

        $this->assertInstanceOf(MultiProcessingModuleCapabilities::class, $capabilities);
        $this->assertEquals(MultiProcessingModuleCapabilities::ISOLATION_PROCESS, $capabilities->getIsolationLevel());
    }

    public function testIfProcMaskIsUsedOnProcessStateChanges()
    {
        $this->markTestSkipped('This test may no longer be needed?');
        $em = new EventManager(new SharedEventManager());
        $pcntlMock = new PcntlBridgeMock();

        PosixProcess::setPcntlBridge($pcntlMock);
        $event = new SchedulerEvent();
        $posixProcess = new ModuleDecorator(new PosixProcess());
        $posixProcess->setEventManager($em);

        $event->setName(SchedulerEvent::EVENT_START);
        $em->triggerEvent($event);

        $event = new WorkerEvent();
        $event->setName(WorkerEvent::EVENT_WAITING);
        $em->triggerEvent($event);

        $logArray = $pcntlMock->getExecutionLog();
        $this->assertEquals(1, $this->countMethodInExecutionLog($logArray, 'pcntlSigprocmask'), 'Signal masking should be disabled when process is waiting');
        $this->assertEquals(1, $this->countMethodInExecutionLog($logArray, 'pcntlSignalDispatch'), 'Signal dispatching should be performed when process is waiting');
        $pcntlMock->setExecutionLog([]);

        $event->setName(WorkerEvent::EVENT_RUNNING);
        $em->triggerEvent($event);
        $logArray = $pcntlMock->getExecutionLog();
        $this->assertEquals(1, $this->countMethodInExecutionLog($logArray, 'pcntlSigprocmask'), 'Signal masking should be disabled when process is running');
        $this->assertEquals(0, $this->countMethodInExecutionLog($logArray, 'pcntlSignalDispatch'), 'Signal dispatching should not be performed when process is running');
    }
}