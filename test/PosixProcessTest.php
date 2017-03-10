<?php

namespace ZeusTest;

use PHPUnit_Framework_TestCase;
use Zend\EventManager\EventInterface;
use Zend\EventManager\EventManager;
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

        $pcntlMock = new PcntlMockBridge();
        PosixProcess::setPcntlBridge($pcntlMock);
        $event = new SchedulerEvent();
        $posixProcess = new PosixProcess($event);
        $posixProcess->attach($em);

        $event->setName(SchedulerEvent::EVENT_PROCESS_TERMINATE);
        $event->setParam('uid', 123456);
        $event->setParam('soft', $isSoftKill);
        $em->triggerEvent($event);

        $logArray = $pcntlMock->getExecutionLog();
        $this->assertEquals(1, $this->countMethodInExecutionLog($logArray, 'posixKill'), 'Kill signal should be sent');
        $this->assertEquals(123456, $logArray[0][1][0], 'Kill signal should be sent to a certain process');
        $this->assertEquals($signal, $logArray[0][1][1], 'Correct type of kill signal should be sent to a certain process');
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

        $event->setName(SchedulerEvent::EVENT_SCHEDULER_LOOP);
        $em->triggerEvent($event);

        $this->assertEquals(SchedulerEvent::EVENT_PROCESS_TERMINATED, $event->getName());
        $logArray = $pcntlMock->getExecutionLog();
        $this->assertEquals(2, $this->countMethodInExecutionLog($logArray, 'pcntlWait'), 'Wait for signal should be performed');
        $this->assertEquals(98765, $event->getParam('uid'), 'Correct process UID should be returned on its termination');
    }
}