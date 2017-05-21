<?php

namespace ZeusTest;

use PHPUnit_Framework_TestCase;
use Zend\EventManager\EventManager;
use Zeus\Kernel\ProcessManager\MultiProcessingModule\Factory\MultiProcessingModuleFactory;
use Zeus\Kernel\ProcessManager\MultiProcessingModule\MultiProcessingModuleCapabilities;
use Zeus\Kernel\ProcessManager\MultiProcessingModule\PosixProcess;
use Zeus\Kernel\ProcessManager\Scheduler;
use Zeus\Kernel\ProcessManager\SchedulerEvent;
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

    public function testPosixProcessFactory()
    {
        $pcntlMock = new PcntlMockBridge();
        $pcntlMock->setPpid(123456789);
        PosixProcess::setPcntlBridge($pcntlMock);

        $sm = $this->getServiceManager();
        $scheduler = $this->getScheduler(0);
        $sm->setFactory(PosixProcess::class, MultiProcessingModuleFactory::class);
        /** @var PosixProcess $service */
        $service = $sm->build(PosixProcess::class, ['scheduler' => $scheduler, 'scheduler_event' => new SchedulerEvent()]);
        $this->assertInstanceOf(PosixProcess::class, $service);

        $eventLaunched = false;
        $scheduler->getEventManager()->attach(SchedulerEvent::EVENT_SCHEDULER_START, function(SchedulerEvent $event) use (&$eventLaunched) {
            $event->stopPropagation(true);
        }, SchedulerEvent::PRIORITY_FINALIZE + 1);

        $scheduler->getEventManager()->attach(SchedulerEvent::EVENT_SCHEDULER_STOP, function(SchedulerEvent $event) use (&$eventLaunched) {
            $eventLaunched = true;
            $event->stopPropagation(true);
        }, SchedulerEvent::PRIORITY_FINALIZE + 1);

        $scheduler->start(true);
        $service->onSchedulerLoop();

        $this->assertTrue($eventLaunched, 'EVENT_SCHEDULER_STOP should have been triggered by PosixProcess');
    }

    public function eventProvider()
    {
        return [
            [
                SchedulerEvent::EVENT_PROCESS_CREATE,
                123412341234, 123412341234,
                [
                    'pcntlFork' => ['amount' => 1, 'message' => 'Process should be forked'],
                    'pcntlSignal' => ['amount' => 0, 'message' => 'Signal handling should be left intact'],
                ],
                SchedulerEvent::EVENT_PROCESS_CREATED
            ],

            [
                SchedulerEvent::EVENT_PROCESS_CREATE,
                false, getmypid(),
                [
                    'pcntlFork' => ['amount' => 1, 'message' => 'Process should be forked'],
                    'pcntlSignal' => ['amount' => 5, 'message' => 'Signal handling should be left intact'],
                ],
                SchedulerEvent::EVENT_PROCESS_INIT
            ],
        ];
    }

    /**
     * @dataProvider eventProvider
     */
    public function testProcessEvents($initialEventType, $forcedForkValue, $expectedForkValue, $methodAmounts, $endingEventType)
    {
        $em = new EventManager();

        $pcntlMock = new PcntlMockBridge();
        $pcntlMock->setForkResult($forcedForkValue);
        PosixProcess::setPcntlBridge($pcntlMock);
        $event = new SchedulerEvent();
        $posixProcess = new PosixProcess($event);
        $posixProcess->attach($em);

        $event->setName($initialEventType);
        $em->triggerEvent($event);

        foreach ($methodAmounts as $method => $details) {
            $this->assertEquals($details['amount'], $this->countMethodInExecutionLog($pcntlMock->getExecutionLog(), $method), $details['message']);
        }

        if (!is_null($expectedForkValue)) {
            $this->assertEquals($expectedForkValue, $event->getParam('uid'));
        }

        $this->assertEquals($endingEventType, $event->getName());
    }

    public function getKillParams()
    {
        return [
            [SIGKILL, false],
            [SIGINT, true],
        ];
    }

    /**
     * @dataProvider getKillParams
     * @param int $signal
     * @param bool $isSoftKill
     */
    public function testProcessTermination($signal, $isSoftKill)
    {
        $em = new EventManager();
        $posixProcess = new PosixProcess();
        $posixProcess->attach($em);

        $pcntlMock = new PcntlMockBridge();
        PosixProcess::setPcntlBridge($pcntlMock);
        $event = new SchedulerEvent();
        $event->setName(SchedulerEvent::EVENT_SCHEDULER_START);
        $event->setParam('uid', 123456);
        $em->triggerEvent($event);

        $event->setName(SchedulerEvent::EVENT_PROCESS_TERMINATE);

        $event->setParam('soft', $isSoftKill);
        $em->triggerEvent($event);

        $logArray = $pcntlMock->getExecutionLog();
        $this->assertEquals(1, $this->countMethodInExecutionLog($logArray, 'posixKill'), 'Kill signal should be sent');
        $this->assertEquals(123456, $logArray[5][1][0], 'Kill signal should be sent to a certain process');
        $this->assertEquals($signal, $logArray[5][1][1], 'Correct type of kill signal should be sent to a certain process');
        $this->assertEquals(SchedulerEvent::EVENT_PROCESS_TERMINATE, $event->getName());
        $pcntlMock->setExecutionLog([]);
    }

    public function testDetectionOfProcessTermination()
    {
        $em = new EventManager();

        $pcntlMock = new PcntlMockBridge();
        $pcntlMock->setPcntlWaitPids([98765]);

        PosixProcess::setPcntlBridge($pcntlMock);
        $event = new SchedulerEvent();
        $posixProcess = new PosixProcess($event);
        $posixProcess->attach($em);

        $event->setName(SchedulerEvent::EVENT_SCHEDULER_START);
        $em->triggerEvent($event);

        $event->setName(SchedulerEvent::EVENT_SCHEDULER_LOOP);
        $em->triggerEvent($event);

        $this->assertEquals(SchedulerEvent::EVENT_PROCESS_TERMINATED, $event->getName());
        $logArray = $pcntlMock->getExecutionLog();
        $this->assertEquals(2, $this->countMethodInExecutionLog($logArray, 'pcntlWait'), 'Wait for signal should be performed');
        $this->assertEquals(98765, $event->getParam('uid'), 'Correct process UID should be returned on its termination');
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
     */
    public function testDetectionOfSchedulerTermination($signal)
    {
        $em = new EventManager();

        $pcntlMock = new PcntlMockBridge();
        $pcntlMock->setSignal($signal);

        PosixProcess::setPcntlBridge($pcntlMock);
        $event = new SchedulerEvent();
        $posixProcess = new PosixProcess($event);
        $posixProcess->attach($em);

        $event->setName(SchedulerEvent::EVENT_SCHEDULER_START);
        $em->triggerEvent($event);

        $event->setName(SchedulerEvent::EVENT_SCHEDULER_LOOP);
        $em->triggerEvent($event);

        $this->assertEquals(SchedulerEvent::EVENT_SCHEDULER_STOP, $event->getName());
        $logArray = $pcntlMock->getExecutionLog();
        $this->assertEquals(2, $this->countMethodInExecutionLog($logArray, 'pcntlWait'), 'Wait for signal should be performed');
        $this->assertEquals(2, $this->countMethodInExecutionLog($logArray, 'pcntlSignalDispatch'), 'Signal dispatching should be performed');
        $this->assertEquals(getmypid(), $event->getParam('uid'), 'Correct process UID should be returned on its termination');
    }

    public function testDetectionOfSchedulersParentTermination()
    {
        $em = new EventManager();

        $pcntlMock = new PcntlMockBridge();
        $pcntlMock->setPpid(1234567890);

        PosixProcess::setPcntlBridge($pcntlMock);
        $event = new SchedulerEvent();
        $posixProcess = new PosixProcess($event);
        $posixProcess->attach($em);

        $event->setName(SchedulerEvent::EVENT_SCHEDULER_START);
        $em->triggerEvent($event);

        $event->setName(SchedulerEvent::EVENT_SCHEDULER_LOOP);
        $em->triggerEvent($event);

        $this->assertEquals(SchedulerEvent::EVENT_SCHEDULER_STOP, $event->getName());
        $logArray = $pcntlMock->getExecutionLog();
        $this->assertEquals(2, $this->countMethodInExecutionLog($logArray, 'pcntlWait'), 'Wait for signal should be performed');
        $this->assertEquals(2, $this->countMethodInExecutionLog($logArray, 'pcntlSignalDispatch'), 'Signal dispatching should be performed');
        $this->assertEquals(getmypid(), $event->getParam('uid'), 'Correct process UID should be returned on its termination');
    }

    public function testDetectionIfPcntlIsSupported()
    {
        $pcntlMock = new PosixProcess\PcntlBridge();
        PosixProcess::setPcntlBridge($pcntlMock);

        $this->assertEquals(extension_loaded('pcntl'), PosixProcess::isSupported(false), ('PCNTL should be ' . extension_loaded('pcntl') ? 'enabled' : 'disabled'));
    }

    public function testDetectionIfPcntlIsSupportedOrNot()
    {
        $pcntlMock = new PcntlMockBridge();
        PosixProcess::setPcntlBridge($pcntlMock);

        $exceptionCatched = 0;

        foreach ([true, false] as $isSupported) {
            $pcntlMock->setIsSupported($isSupported);
            $this->assertEquals($isSupported, PosixProcess::isSupported(false), ('PCNTL should be ' . $isSupported ? 'enabled' : 'disabled'));
            if (!$isSupported) {
                try {
                    $this->assertEquals($isSupported, PosixProcess::isSupported(true), ('PCNTL should be ' . $isSupported ? 'enabled' : 'disabled'));
                } catch (\RuntimeException $e) {
                    $exceptionCatched++;
                }
            }
        }

        $this->assertEquals(1, $exceptionCatched, 'One exception should be thrown when PCNTL was unavailable');
    }

    public function testIfSetSsidIsPerformedOnStartup()
    {
        $em = new EventManager();
        $pcntlMock = new PcntlMockBridge();

        PosixProcess::setPcntlBridge($pcntlMock);
        $event = new SchedulerEvent();
        $posixProcess = new PosixProcess($event);
        $posixProcess->attach($em);

        $event->setName(SchedulerEvent::INTERNAL_EVENT_KERNEL_START);
        $em->triggerEvent($event);

        $event->setName(SchedulerEvent::EVENT_SCHEDULER_START);
        $em->triggerEvent($event);

        $logArray = $pcntlMock->getExecutionLog();
        $this->assertEquals(1, $this->countMethodInExecutionLog($logArray, 'posixSetsid'), 'Master process should be set as session leader');
    }

    public function testPosixCapabilities()
    {
        $event = new SchedulerEvent();
        $posixProcess = new PosixProcess($event);
        $capabilities = $posixProcess->getCapabilities();

        $this->assertInstanceOf(MultiProcessingModuleCapabilities::class, $capabilities);
        $this->assertEquals(MultiProcessingModuleCapabilities::ISOLATION_PROCESS, $capabilities->getIsolationLevel());
    }

    public function testIfProcMaskIsUsedOnProcessStateChanges()
    {
        $em = new EventManager();
        $pcntlMock = new PcntlMockBridge();

        PosixProcess::setPcntlBridge($pcntlMock);
        $event = new SchedulerEvent();
        $posixProcess = new PosixProcess($event);
        $posixProcess->attach($em);

        $event->setName(SchedulerEvent::EVENT_SCHEDULER_START);
        $em->triggerEvent($event);

        $event->setName(SchedulerEvent::EVENT_PROCESS_WAITING);
        $em->triggerEvent($event);

        $logArray = $pcntlMock->getExecutionLog();
        $this->assertEquals(1, $this->countMethodInExecutionLog($logArray, 'pcntlSigprocmask'), 'Signal masking should be disabled when process is waiting');
        $this->assertEquals(1, $this->countMethodInExecutionLog($logArray, 'pcntlSignalDispatch'), 'Signal dispatching should be performed when process is waiting');
        $pcntlMock->setExecutionLog([]);

        $event->setName(SchedulerEvent::EVENT_PROCESS_RUNNING);
        $em->triggerEvent($event);
        $logArray = $pcntlMock->getExecutionLog();
        $this->assertEquals(1, $this->countMethodInExecutionLog($logArray, 'pcntlSigprocmask'), 'Signal masking should be disabled when process is running');
        $this->assertEquals(0, $this->countMethodInExecutionLog($logArray, 'pcntlSignalDispatch'), 'Signal dispatching should not be performed when process is running');
    }
}