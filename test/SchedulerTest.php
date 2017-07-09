<?php

namespace ZeusTest;

use PHPUnit_Framework_TestCase;
use Zend\Console\Console;
use Zend\EventManager\EventInterface;
use Zend\Log\Logger;
use Zend\Log\Writer\Mock;
use Zend\ServiceManager\Exception\ServiceNotCreatedException;
use Zend\ServiceManager\ServiceManager;
use Zeus\Kernel\ProcessManager\Exception\ProcessManagerException;

use Zeus\Kernel\ProcessManager\WorkerEvent;
use Zeus\Kernel\ProcessManager\Scheduler;
use Zeus\Kernel\ProcessManager\SchedulerEvent;
use Zeus\ServerService\Shared\Logger\ConsoleLogFormatter;
use ZeusTest\Helpers\ZeusFactories;

class SchedulerTest extends PHPUnit_Framework_TestCase
{
    use ZeusFactories;

    /**
     * @var ServiceManager
     */
    protected $serviceManager;

    public function setUp()
    {
        Console::overrideIsConsole(true);
        chdir(__DIR__);
    }

    public function tearDown()
    {
        parent::tearDown();
    }

    public function testCliDetection()
    {
        Console::overrideIsConsole(false);

        try {
            $this->getScheduler();
        } catch (\Exception $e) {
            $this->assertInstanceOf(ServiceNotCreatedException::class, $e);
            $this->assertInstanceOf(ProcessManagerException::class, $e->getPrevious());
        }
    }

    public function testApplicationInit()
    {
        $scheduler = $this->getScheduler(1);
        $this->assertInstanceOf(Scheduler::class, $scheduler);
        $scheduler->setSchedulerActive(false);
        $scheduler->start(false);

        $this->assertEquals(getmypid(), $scheduler->getProcessId());
    }

    public function testMainLoopIteration()
    {
        $scheduler = $this->getScheduler(1);
        $this->assertInstanceOf(Scheduler::class, $scheduler);

        $events = $scheduler->getEventManager();
        $counter = 0;
        $events->attach(SchedulerEvent::EVENT_SCHEDULER_LOOP, function(SchedulerEvent $e) use (&$counter) {
            $e->getTarget()->setSchedulerActive(false);
            $counter++;
        });

        $scheduler->start(false);
        $this->assertEquals(1, $counter, 'Loop should have been executed only once');
    }

    /**
     * @todo: ignore messages created by framework and focus only on test messages
     */
    public function testIpcLogDispatching()
    {
        $scheduler = $this->getScheduler(1);
        $logger = $scheduler->getLogger();
        $ipc = $scheduler->getIpc();

        $messages = [];
        foreach (["debug", "warn", "err", "alert", "info", "crit", "notice", "emerg"] as $severity) {
            $message = sprintf("%s message", ucfirst($severity));
            $logger->$severity($message);
            $messages[strtoupper($severity)] = $message;
        }

        $mockWriter = new Mock();
        $nullLogger = new Logger();
        $nullLogger->addWriter($mockWriter);
        $scheduler->setLogger($nullLogger);

        $ipc->useChannelNumber(0);

        $this->assertInstanceOf(Scheduler::class, $scheduler);

        $scheduler->start(false);
        $ipc->useChannelNumber(1);
        $this->assertEquals(0, count($ipc->receiveAll()), "No messages should be left on IPC");
        $ipc->useChannelNumber(0);

        $this->assertGreaterThanOrEqual(8, count($mockWriter->events), "At least 8 messages should have been transferred from one channel to another");

        $counter = 0;
        $foundEvents = [];
        foreach ($mockWriter->events as $event) {
            if (isset($messages[$event['priorityName']]) && $event['message'] === $messages[$event['priorityName']]) {
                $counter++;
                $foundEvents[] = $event['message'] . ':' . $event['priorityName'];
            }
        }

        $this->assertEquals(8, $counter, "All messages should have been transferred from one channel to another");
        $this->assertEquals(8, count(array_unique($foundEvents)), "Messages should be unique");
    }

    public function schedulerProcessAmountProvider()
    {
        return [
            [3, 1, 1, 2],

            [1, 8, 3, 11],
            [1, 10, 3, 13],
            [1, 25, 3, 28],

            [3, 8, 3, 11],
            [5, 10, 3, 13],
            [8, 25, 3, 28],

            [4, 8, 3, 11],
            [7, 10, 3, 13],
            [12, 25, 3, 28],

            [20, 8, 4, 12],
            [22, 10, 5, 15],
            [100, 25, 6, 31],
        ];
    }

    /**
     * @dataProvider schedulerProcessAmountProvider
     * @param $schedulerIterations
     * @param $starProcesses
     * @param $minIdleProcesses
     * @param $expectedProcesses
     */
    public function testProcessCreationWhenTooLittleOfThemIsWaiting($schedulerIterations, $starProcesses, $minIdleProcesses, $expectedProcesses)
    {
        $scheduler = $this->getScheduler($schedulerIterations);
        $scheduler->getConfig()->setStartProcesses($starProcesses);
        $scheduler->getConfig()->setMinSpareProcesses($minIdleProcesses);

        $amountOfScheduledProcesses = 0;
        $processCount = 0;

        $em = $scheduler->getEventManager();
        $em->getSharedManager()->attach('*', WorkerEvent::EVENT_WORKER_EXIT, function(WorkerEvent $e) {$e->stopPropagation(true);}, 100000);
        $em->getSharedManager()->attach('*', SchedulerEvent::EVENT_SCHEDULER_STOP, function(SchedulerEvent $e) {$e->stopPropagation(true);}, 0);

        $em->getSharedManager()->attach('*', SchedulerEvent::EVENT_WORKER_CREATE,
            function(SchedulerEvent $e) use ($em, &$amountOfScheduledProcesses) {
                $amountOfScheduledProcesses++;
                $uid = 100000000 + $amountOfScheduledProcesses;
                $processesCreated[] = $uid;
                $e->setParams(['uid' => $uid, 'init_process' => true]);
            }, 1000
        );

        $em->getSharedManager()->attach('*', SchedulerEvent::EVENT_WORKER_CREATE,
            function(SchedulerEvent $e) use (&$scheduler) {
                $e->stopPropagation(true);
            }, SchedulerEvent::PRIORITY_FINALIZE - 1
        );

        $em->getSharedManager()->attach('*', WorkerEvent::EVENT_WORKER_INIT,
            function(WorkerEvent $e) use (&$processCount, $starProcesses) {
                $process = $e->getTarget();
                $uid = $process->getProcessId();
                $process->setWaiting();
                $process->getStatus()->incrementNumberOfFinishedTasks(1000);

                // mark all processes as busy
                if ($uid - 100000000 <= $starProcesses) {
                    $process->setRunning();
                }
                $e->stopPropagation(true);
            }
        );

        $scheduler->start(false);

        $this->assertEquals($expectedProcesses, $amountOfScheduledProcesses, "Scheduler should try to create $starProcesses processes on startup and "  . ($expectedProcesses - $starProcesses) . " additional one if all the previous were busy");
    }

    public function testProcessCreationWhenTooManyOfThemIsWaiting()
    {
        $scheduler = $this->getScheduler(4);
        $scheduler->getConfig()->setStartProcesses(20);
        $scheduler->getConfig()->setProcessIdleTimeout(0);

        $amountOfScheduledProcesses = 0;
        $amountOfTerminateCommands = 0;
        $processesCreated = [];
        $processesInitialized = [];
        $processesToTerminate = [];

        $em = $scheduler->getEventManager();
        $em->getSharedManager()->attach('*', WorkerEvent::EVENT_WORKER_EXIT, function(EventInterface $e) {$e->stopPropagation(true);});
        $em->getSharedManager()->attach('*', SchedulerEvent::EVENT_WORKER_TERMINATE,
            function(SchedulerEvent $processEvent) use ($em, & $processesToTerminate, & $amountOfTerminateCommands) {
                $processEvent->stopPropagation(true);
                $amountOfTerminateCommands++;
                $processesToTerminate[] = $processEvent->getParam('uid');
            }
        );
        $em->getSharedManager()->attach('*', SchedulerEvent::EVENT_WORKER_CREATE,
            function(SchedulerEvent $e) use ($em, &$amountOfScheduledProcesses) {
                $amountOfScheduledProcesses++;

                $uid = 100000000 + $amountOfScheduledProcesses;
                $e->setParam('uid', $uid);
                $e->setParam('processId', $uid);
                $e->setParam('threadId', 1);
                $processesCreated[] = $uid;
            }
        );

        $em->getSharedManager()->attach('*', WorkerEvent::EVENT_WORKER_LOOP,
            function(WorkerEvent $e) use (&$processesInitialized) {
                $uid = $e->getTarget()->getProcessId();
                $processesInitialized[] = $uid;

                // kill the processs
                $e->getTarget()->getStatus()->incrementNumberOfFinishedTasks(100);
            }
        );
        $scheduler->start(false);

        $this->assertEquals(4, $amountOfTerminateCommands, "Scheduler should try to reduce number of processes to 4 if too many of them is waiting");
    }

    public function getSchedulerLaunchTypes()
    {
        return [
            [true, 'running in background'],
            [false, 'running in foreground'],
        ];
    }

    /**
     * @dataProvider getSchedulerLaunchTypes
     * @param $runInBackground
     * @param $launchDescription
     */
    public function testSchedulerStartingEvents($runInBackground, $launchDescription)
    {
        $serverStarted = false;
        $schedulerStarted = false;
        $scheduler = $this->getScheduler(1);
        $events = $scheduler->getEventManager();

        $events->getSharedManager()->attach('*', SchedulerEvent::INTERNAL_EVENT_KERNEL_START, function() use (& $serverStarted) {
            $serverStarted = true;
        });

        $events->getSharedManager()->attach('*', SchedulerEvent::EVENT_SCHEDULER_START, function() use (& $schedulerStarted) {
            $schedulerStarted = true;
        });

        $scheduler->start($runInBackground);
        $this->assertTrue($serverStarted, 'Server should have been started when ' . $launchDescription);
        $this->assertTrue($serverStarted, 'Scheduler should have been started when ' . $launchDescription);
    }

    public function testProcessErrorHandling()
    {
        $scheduler = $this->getScheduler(1);

        $amountOfScheduledProcesses = 0;
        $processesCreated = [];
        $processesInitialized = [];

        $em = $scheduler->getEventManager();
        $em->getSharedManager()->attach('*', WorkerEvent::EVENT_WORKER_EXIT, function(EventInterface $e) {$e->stopPropagation(true);});
        $em->getSharedManager()->attach('*', SchedulerEvent::EVENT_SCHEDULER_STOP, function(SchedulerEvent $e) {$e->stopPropagation(true);}, 0);
        $em->getSharedManager()->attach('*', SchedulerEvent::EVENT_WORKER_CREATE,
            function(SchedulerEvent $e) use ($em, &$amountOfScheduledProcesses, &$processesCreated) {
                $amountOfScheduledProcesses++;
                $uid = 100000000 + $amountOfScheduledProcesses;
                $processesCreated[] = $uid;
                $e->setParams(['uid' => $uid, 'init_process' => true]);
            }, 1000
        );

        $em->getSharedManager()->attach('*', SchedulerEvent::EVENT_WORKER_CREATE,
            function(SchedulerEvent $e) use (&$scheduler) {
                $e->stopPropagation(true);
            }, SchedulerEvent::PRIORITY_FINALIZE - 1
        );

        $em->getSharedManager()->attach('*', WorkerEvent::EVENT_WORKER_LOOP,
            function(WorkerEvent $e) use (&$processesInitialized) {
                $id = $e->getTarget()->getProcessId();
                $e->getTarget()->getStatus()->incrementNumberOfFinishedTasks(1001);

                $processesInitialized[] = $id;

                throw new \RuntimeException("Exception thrown by $id!", 10000);
            }
        );

        $logger = new Logger();
        $mockWriter = new Mock();
        $scheduler->setLogger($logger);
        $scheduler->getLogger()->addWriter($mockWriter);
        $mockWriter->setFormatter(new ConsoleLogFormatter(Console::getInstance()));
        $scheduler->start(false);

        $this->assertEquals(11, $amountOfScheduledProcesses, "Scheduler should try to create 8 processes on its startup and additional 3 as previous failed");
        $this->assertEquals($processesCreated, $processesInitialized, "Scheduler should have initialized all requested processes");

        $foundExceptions = [];
        foreach ($mockWriter->events as $event) {
            if (preg_match('~^Exception \(10000\): Exception thrown by ([0-9]+)~', $event['message'], $matches)) {
                $foundExceptions[] = $matches[1];
            }
        }

        $this->assertEquals(8, count($foundExceptions), "Logger should have reported 8 errors: " . json_encode($foundExceptions));
    }

    public function testProcessShutdownSequence()
    {
        $scheduler = $this->getScheduler(1);

        $amountOfScheduledProcesses = 0;
        $processesCreated = [];

        $em = $scheduler->getEventManager();
        $em->getSharedManager()->attach('*', WorkerEvent::EVENT_WORKER_EXIT, function(EventInterface $e) {$e->stopPropagation(true);});
        $em->getSharedManager()->attach('*', SchedulerEvent::EVENT_WORKER_CREATE,
            function(SchedulerEvent $e) use ($em, &$amountOfScheduledProcesses, &$processesCreated) {
                $amountOfScheduledProcesses++;
                $uid = 100000000 + $amountOfScheduledProcesses;
                $processesCreated[$uid] = $uid;
                $e->setParams(['uid' => $uid, 'init_process' => true]);
            }, 1000
        );

        $em->getSharedManager()->attach('*', SchedulerEvent::EVENT_WORKER_CREATE,
            function(SchedulerEvent $e) use (&$scheduler) {
                $e->stopPropagation(true);
            }, SchedulerEvent::PRIORITY_FINALIZE - 1
        );

        $em->getSharedManager()->attach('*', WorkerEvent::EVENT_WORKER_LOOP,
            function(WorkerEvent $e) {
                // stop the process loop
                $e->getTarget()->getStatus()->incrementNumberOfFinishedTasks(100);
            }
        );

        $schedulerStopped = false;
        $em->getSharedManager()->attach('*', SchedulerEvent::EVENT_SCHEDULER_STOP,
            function(SchedulerEvent $e) use (&$schedulerStopped) {
                $schedulerStopped = true;
                $e->stopPropagation(true);
            }, -9999);

        $unknownProcesses = [];
        $em->getSharedManager()->attach('*', SchedulerEvent::EVENT_WORKER_TERMINATE,
            function(SchedulerEvent $e) use ($em, &$processesCreated, &$unknownProcesses) {
                $uid = $e->getParam('uid');
                if (!isset($processesCreated[$uid])) {
                    $unknownProcesses[] = $uid;

                    return;
                }

                unset($processesCreated[$uid]);
            }
        );

        $event = new SchedulerEvent();
        $scheduler->start(false);

        $this->assertGreaterThan(0, $amountOfScheduledProcesses, "Scheduler should try to create some processes on its startup");

        $event->setName(SchedulerEvent::EVENT_SCHEDULER_STOP);
        $scheduler->setSchedulerActive(true);
        $scheduler->getEventManager()->triggerEvent($event);

        $this->assertEquals(0, count($unknownProcesses), 'No unknown processes should have been terminated');
        $this->assertEquals(0, count($processesCreated), 'All processes should have been planned to be terminated on scheduler shutdown');
    }

    public function testSchedulerIsTerminatingIfPidFileIsInvalid()
    {
        $scheduler = $this->getScheduler(1);
        $scheduler->getConfig()->setIpcDirectory('invalidSchema://invalidUrl');
        $em = $scheduler->getEventManager();

        $exitDetected = false;
        $exception = null;

        $em->getSharedManager()->attach('*',
            SchedulerEvent::EVENT_WORKER_CREATE, function (SchedulerEvent $event) use ($em) {
            $event->setParams(["uid" => 123456789, "server" => true]);
        }
        );
        $em->getSharedManager()->attach('*', WorkerEvent::EVENT_WORKER_EXIT, function(SchedulerEvent $e) {$e->stopPropagation(true);});
        $em->getSharedManager()->attach('*', SchedulerEvent::EVENT_SCHEDULER_STOP,
            function(SchedulerEvent $e) use (&$exitDetected, &$exception) {
                $exitDetected = true;
                $e->stopPropagation(true);
                $exception = $e->getParam('exception');
            },
            -5000
        );

        $scheduler->start(true);

        $this->assertTrue($exitDetected, "Scheduler should shutdown when it can't create PID file");
        $this->assertInstanceOf(ProcessManagerException::class, $exception, "Exception should be returned in SchedulerEvent");

    }
}