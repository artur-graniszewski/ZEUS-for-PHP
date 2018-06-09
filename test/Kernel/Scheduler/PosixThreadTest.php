<?php

namespace ZeusTest\Kernel\Scheduler;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Zend\ServiceManager\ServiceManager;
use Zeus\Kernel\Scheduler\MultiProcessingModule\Factory\MultiProcessingModuleFactory;
use Zeus\Kernel\Scheduler\MultiProcessingModule\MultiProcessingModuleCapabilities;
use Zeus\Kernel\Scheduler\MultiProcessingModule\PosixThread;
use Zeus\Kernel\Scheduler\Status\WorkerState;
use Zeus\Kernel\Scheduler\WorkerEvent;
use Zeus\Kernel\Scheduler;
use Zeus\Kernel\Scheduler\SchedulerEvent;
use Zeus\Kernel\SchedulerInterface;
use Zeus\Kernel\System\Runtime;
use ZeusTest\Helpers\PosixThreadBridgeMock;
use ZeusTest\Helpers\PosixThreadWrapperMock;
use ZeusTest\Helpers\ZeusFactories;

/**
 * Class PosixThreadTest
 * @package ZeusTest\Kernel\Scheduler
 * @preserveGlobalState true
 */
class PosixThreadTest extends TestCase
{
    use ZeusFactories;

    public function setUp()
    {
        Runtime::setShutdownHook(function() {
            return true;
        });
    }

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

    private function getCustomServiceManager() : ServiceManager
    {
        $sm = $this->getServiceManager([
            'zeus_process_manager' => [
                'schedulers' => [
                    'test_scheduler_1' => [
                        'multiprocessing_module' => PosixThread::class,
                    ]
                ]
            ]
        ]);
        $sm->setFactory(PosixThread::class, MultiProcessingModuleFactory::class);
        return $sm;
    }

    public function testPosixThreadFactory()
    {
        Runtime::setShutdownHook(function() {
            return true;
        });
        $self = $_SERVER['SCRIPT_NAME'];
        $_SERVER['SCRIPT_NAME'] = __DIR__ . '/PosixThreadExec.php';
        $bridge = new PosixThreadBridgeMock();
        $bridge->setIsSupported(true);
        PosixThread::setPosixThreadBridge($bridge);
        $sm = $this->getCustomServiceManager();
        $scheduler = $this->getScheduler(1, null, $sm);

        $event = new SchedulerEvent();
        $event->setScheduler($scheduler);

        $this->simulateWorkerInit($scheduler->getEventManager());

        $eventLaunched = false;
        $scheduler->getEventManager()->attach(SchedulerEvent::EVENT_START, function(SchedulerEvent $event) use (&$eventLaunched) {
            $event->stopPropagation(true);
        }, SchedulerEvent::PRIORITY_FINALIZE + 1);

        $amount = 0;
        $scheduler->getEventManager()->attach(WorkerEvent::EVENT_CREATE, function(WorkerEvent $event) use (&$eventLaunched, &$amount, &$argv) {
            $amount++;
            if ($amount === 1) {
                $argv = $_SERVER['argv'];
            }
        }, SchedulerEvent::PRIORITY_FINALIZE + 1);

        $scheduler->getEventManager()->attach(WorkerEvent::EVENT_LOOP, function(WorkerEvent $event) {
            $event->getWorker()->setCode(WorkerState::EXITING);
        }, WorkerEvent::PRIORITY_FINALIZE + 1);

        $scheduler->getEventManager()->attach(SchedulerEvent::EVENT_STOP, function(SchedulerEvent $event) use (&$eventLaunched) {
            $eventLaunched = true;
            PosixThreadWrapperMock::setIsTerminated(true);
        }, SchedulerEvent::PRIORITY_FINALIZE + 1);

        ob_start();
        $scheduler->start(false);
        echo "\n\n\n\n\n\n";
        $output = ob_get_clean();
        $newScriptName = $_SERVER['SCRIPT_NAME'];
        $_SERVER['SCRIPT_NAME'] = $self;
        $this->assertNotEmpty($output, "Worker should return serialized data");
        $serverVars = unserialize($output);
        $this->assertNotEmpty($serverVars, "Worker should return unserializable data");
        $this->assertEquals($newScriptName, $serverVars['SCRIPT_NAME']);
        $this->assertEquals($argv, $serverVars['argv']);
        $this->assertTrue($eventLaunched, 'EVENT_SCHEDULER_STOP should have been triggered by PosixThread');
    }

    public function testDetectionIfPosixThreadIsSupportedOrNot()
    {
        $bridge = new PosixThreadBridgeMock();
        PosixThread::setPosixThreadBridge($bridge);

        $status = [];

        foreach ([true, false] as $isSupported) {
            $bridge->setIsSupported($isSupported);

            $status[$isSupported] = '';

            $this->assertEquals($isSupported, PosixThread::isSupported($status[$isSupported]), ('pThreads should be ' . $isSupported ? 'enabled' : 'disabled'));
        }

        $this->assertEquals("pThread extension is required by PosixThread but disabled in PHP", $status[false], 'Error message should be returned if MPM driver is not supported');
        $this->assertEquals("", $status[true], 'No error message should be returned if MPM driver is supported');
    }

    public function testPosixCapabilities()
    {
        $posixProcess = new PosixThread();
        $capabilities = $posixProcess::getCapabilities();

        $this->assertInstanceOf(MultiProcessingModuleCapabilities::class, $capabilities);
        $this->assertEquals(MultiProcessingModuleCapabilities::ISOLATION_THREAD, $capabilities->getIsolationLevel());
    }
}