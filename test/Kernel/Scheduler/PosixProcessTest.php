<?php

namespace ZeusTest\Kernel\Scheduler;

use PHPUnit_Framework_TestCase;
use Zend\EventManager\EventManager;
use Zend\EventManager\SharedEventManager;
use Zend\Log\Logger;
use Zend\Log\Writer\Noop;
use Zeus\Kernel\Scheduler\MultiProcessingModule\Factory\MultiProcessingModuleFactory;
use Zeus\Kernel\Scheduler\MultiProcessingModule\ModuleWrapper;
use Zeus\Kernel\Scheduler\MultiProcessingModule\MultiProcessingModuleCapabilities;
use Zeus\Kernel\Scheduler\MultiProcessingModule\PosixProcess;
use Zeus\Kernel\Scheduler\WorkerEvent;
use Zeus\Kernel\Scheduler;
use Zeus\Kernel\Scheduler\SchedulerEvent;
use ZeusTest\Helpers\PcntlMockBridge;
use ZeusTest\Helpers\ZeusFactories;

class PosixProcessTest extends PHPUnit_Framework_TestCase
{
    use ZeusFactories;

    /**
     * @param Scheduler $scheduler
     * @return SchedulerEvent
     */
    protected function getEvent(Scheduler $scheduler)
    {
        $rc = new \ReflectionClass(Scheduler::class);
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

    private function getMpm(Scheduler $scheduler) : ModuleWrapper
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

        $service = new ModuleWrapper($service);
        $service->setLogger($scheduler->getLogger());
        return $service;
    }

    public function testPosixProcessFactory()
    {
        $sm = $this->getServiceManager();
        $scheduler = $this->getScheduler(1);

        $pcntlMock = new PcntlMockBridge();
        $pcntlMock->setPpid(123456789);
        PosixProcess::setPcntlBridge($pcntlMock);
        $event = new SchedulerEvent();
        $event->setScheduler($scheduler);
        $sm->setFactory(PosixProcess::class, MultiProcessingModuleFactory::class);
        $service = $this->getMpm($scheduler);

        $eventLaunched = false;
        $scheduler->getEventManager()->attach(SchedulerEvent::EVENT_START, function(SchedulerEvent $event) use (&$eventLaunched) {
            $event->stopPropagation(true);
        }, SchedulerEvent::PRIORITY_FINALIZE + 1);

        $scheduler->getEventManager()->attach(WorkerEvent::EVENT_INIT, function(WorkerEvent $event) use (&$eventLaunched) {
            $event->stopPropagation(true);
        }, SchedulerEvent::PRIORITY_INITIALIZE + 1);

        $scheduler->getEventManager()->attach(SchedulerEvent::EVENT_STOP, function(SchedulerEvent $event) use (&$eventLaunched) {
            $eventLaunched = true;
            $event->stopPropagation(true);
        }, SchedulerEvent::PRIORITY_FINALIZE + 1);

        $scheduler->start(false);
        $em = $scheduler->getEventManager();
        $event = new SchedulerEvent();
        $event->setScheduler($scheduler);
        $event->setTarget($scheduler);
        $event->setName(SchedulerEvent::EVENT_LOOP);
        $event->setParam('uid', 123456);
        $em->triggerEvent($event);

        $this->assertTrue($eventLaunched, 'EVENT_SCHEDULER_STOP should have been triggered by PosixProcess');
    }

    public function eventProvider()
    {
        return [
            [
                WorkerEvent::EVENT_CREATE,
                123412341234, 123412341234,
                [
                    'pcntlFork' => ['amount' => 1, 'message' => 'Process should be forked'],
                    'pcntlSignal' => ['amount' => 0, 'message' => 'Signal handling should be left intact'],
                ],
                false,
            ],

            [
                WorkerEvent::EVENT_CREATE,
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

        $pcntlMock = new PcntlMockBridge();
        $pcntlMock->setForkResult($forcedForkValue);
        PosixProcess::setPcntlBridge($pcntlMock);
        $event = new WorkerEvent();
        $event->setWorker(new Scheduler\Worker());
        $posixProcess = new ModuleWrapper(new PosixProcess());
        $posixProcess->setEventManager($em);

        $event->setName($initialEventType);
        $em->triggerEvent($event);

        foreach ($methodAmounts as $method => $details) {
            $this->assertEquals($details['amount'], $this->countMethodInExecutionLog($pcntlMock->getExecutionLog(), $method), $details['message']);
        }

        if (!is_null($expectedForkValue)) {
            $this->assertEquals($expectedForkValue, $triggeredEvent->getWorker()->getUid());
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

        $pcntlMock = new PcntlMockBridge();
        PosixProcess::setPcntlBridge($pcntlMock);
        $event = new SchedulerEvent();
        $event->setScheduler($scheduler);
        $event->setTarget($scheduler);
        $event->setName(SchedulerEvent::EVENT_START);
        $event->setParam('uid', 123456);
        $em->triggerEvent($event);

        $event = new WorkerEvent();
        $event->setScheduler($scheduler);
        $event->setTarget($scheduler);
        $event->setName(WorkerEvent::EVENT_TERMINATE);
        $event->setParam('uid', 123456);
        $event->setParam('soft', false);
        $em->triggerEvent($event);

        $logArray = $pcntlMock->getExecutionLog();
        $this->assertEquals(1, $this->countMethodInExecutionLog($logArray, 'posixKill'), 'Kill signal should be sent');
        $this->assertEquals(123456, $logArray[1][1][0], 'Kill signal should be sent to a certain process');
        $this->assertEquals(SIGKILL, $logArray[1][1][1], 'Correct type of kill signal should be sent to a certain process');
        $this->assertEquals(WorkerEvent::EVENT_TERMINATE, $event->getName());
        $pcntlMock->setExecutionLog([]);
    }

    public function testDetectionOfProcessTermination()
    {
        $worker = new Scheduler\Worker();
        $scheduler = $this->getScheduler(1);
        $em = new EventManager(new SharedEventManager());
        $em->attach(WorkerEvent::EVENT_TERMINATED, function($event) use (&$triggeredEvent) {
            $triggeredEvent = $event;
        });

        $pcntlMock = new PcntlMockBridge();
        $pcntlMock->setPcntlWaitPids([98765]);
        $pcntlMock->setForkResult(98765);

        PosixProcess::setPcntlBridge($pcntlMock);
        $schedulerEvent = new SchedulerEvent();
        $schedulerEvent->setScheduler($scheduler);
        $workerEvent = new WorkerEvent();
        $workerEvent->setWorker($worker);
        $posixProcess = $this->getMpm($scheduler);
        $posixProcess->setSchedulerEvent($schedulerEvent);
        $posixProcess->setWorkerEvent($workerEvent);
        $posixProcess->setEventManager($em);

        $workerEvent->setName(WorkerEvent::EVENT_CREATE);
        $em->triggerEvent($workerEvent);

        $schedulerEvent->setName(SchedulerEvent::EVENT_LOOP);
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
        $worker = new Scheduler\Worker();
        $scheduler = $this->getScheduler(1);
        $em = new EventManager(new SharedEventManager());
        $em->attach(WorkerEvent::EVENT_TERMINATED, function($event) use (&$triggeredEvent) {
            $triggeredEvent = $event;
        });

        $pcntlMock = new PcntlMockBridge();
        $pcntlMock->setPcntlWaitPids([98765]);
        $pcntlMock->setForkResult(-1);

        PosixProcess::setPcntlBridge($pcntlMock);
        $schedulerEvent = new SchedulerEvent();
        $schedulerEvent->setScheduler($scheduler);
        $workerEvent = new WorkerEvent();
        $workerEvent->setWorker($worker);
        $posixProcess = $this->getMpm($scheduler);
        $posixProcess->setSchedulerEvent($schedulerEvent);
        $posixProcess->setWorkerEvent($workerEvent);
        $posixProcess->setEventManager($em);

        $workerEvent->setName(WorkerEvent::EVENT_CREATE);
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

        $pcntlMock = new PcntlMockBridge();
        $pcntlMock->setSignal($signal);
        $pcntlMock->setPpid(1234);

        PosixProcess::setPcntlBridge($pcntlMock);
        $event = new SchedulerEvent();
        $posixProcess = new ModuleWrapper(new PosixProcess());
        $posixProcess->setSchedulerEvent($event);
        $posixProcess->setEventManager($em);

        $event->setName(SchedulerEvent::EVENT_START);
        $em->triggerEvent($event);

        $pcntlMock->setPpid(12345);

        $event->setName(SchedulerEvent::EVENT_LOOP);
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

        $pcntlMock = new PcntlMockBridge();
        $pcntlMock->setPpid(1234567890);

        PosixProcess::setPcntlBridge($pcntlMock);
        $event = new WorkerEvent();
        $posixProcess = $this->getMpm($scheduler);

        //$scheduler->start(false);

        $event->setName(WorkerEvent::EVENT_LOOP);
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

        $pcntlMock = new PcntlMockBridge();
        $pcntlMock->setPpid(1234567890);

        PosixProcess::setPcntlBridge($pcntlMock);
        $event = new SchedulerEvent();
        $posixProcess = $this->getMpm($scheduler);

        //$scheduler->start(false);

        $event->setName(SchedulerEvent::EVENT_LOOP);
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
        $pcntlMock = new PcntlMockBridge();
        PosixProcess::setPcntlBridge($pcntlMock);

        $status = [];

        foreach ([true, false] as $isSupported) {
            $pcntlMock->setIsSupported($isSupported);

            $status[$isSupported] = '';

            $this->assertEquals($isSupported, PosixProcess::isSupported($status[$isSupported]), ('PCNTL should be ' . $isSupported ? 'enabled' : 'disabled'));
        }

        $this->assertEquals("PCNTL extension is required by PosixProcess but disabled in PHP", $status[false], 'Error message should be returned if MPM driver is not supported');
        $this->assertEquals("", $status[true], 'No error message should be returned if MPM driver is not supported');
    }

    public function testIfSetSsidIsPerformedOnStartup()
    {
        $em = new EventManager(new SharedEventManager());
        $pcntlMock = new PcntlMockBridge();

        PosixProcess::setPcntlBridge($pcntlMock);
        $event = new SchedulerEvent();
        $posixProcess = new ModuleWrapper(new PosixProcess());
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
        $pcntlMock = new PcntlMockBridge();

        PosixProcess::setPcntlBridge($pcntlMock);
        $event = new SchedulerEvent();
        $posixProcess = new ModuleWrapper(new PosixProcess());
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