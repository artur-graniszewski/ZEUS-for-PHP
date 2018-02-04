<?php

namespace ZeusTest\Kernel\Scheduler;

use \PHPUnit\Framework\TestCase;
use Zend\Console\Console;
use Zend\EventManager\EventInterface;
use Zend\Log\Logger;
use Zend\Log\Writer\Mock;
use Zend\Log\Writer\Noop;
use Zend\ServiceManager\Exception\ServiceNotCreatedException;
use Zend\ServiceManager\ServiceManager;
use Zeus\Kernel\Scheduler\Exception\SchedulerException;
use Zeus\Kernel\Scheduler\WorkerEvent;
use Zeus\Kernel\Scheduler;
use Zeus\Kernel\Scheduler\SchedulerEvent;
use Zeus\ServerService\Shared\Logger\ConsoleLogFormatter;
use ZeusTest\Helpers\DummyMpm;
use ZeusTest\Helpers\ZeusFactories;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class SchedulerTest extends \PHPUnit\Framework\TestCase
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
            $this->assertInstanceOf(SchedulerException::class, $e->getPrevious());
        }
    }

    public function testApplicationInit()
    {
        $scheduler = $this->getScheduler(1);
        $this->assertInstanceOf(Scheduler::class, $scheduler);
        $scheduler->setIsTerminating(true);
        $scheduler->start(false);

        $this->assertTrue($scheduler->isTerminating());
    }

    public function testMainLoopIteration()
    {
        $scheduler = $this->getScheduler(1);
        $this->assertInstanceOf(Scheduler::class, $scheduler);

        $events = $scheduler->getEventManager();
        $counter = 0;
        $events->attach(SchedulerEvent::EVENT_LOOP, function(SchedulerEvent $e) use (&$counter) {
            $e->getScheduler()->setIsTerminating(true);
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
        $this->markTestIncomplete("IPC logger should be tested elsewhere");
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

        $this->assertInstanceOf(Scheduler::class, $scheduler);

        $scheduler->start(false);
        $this->assertEquals(0, count($ipc->readAll()), "No messages should be left on IPC");

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
            [1, 1, 1, 1],
            [3, 1, 1, 2],

            [4, 8, 3, 11],
            [4, 10, 3, 13],
            [4, 25, 3, 28],

            [3, 8, 3, 11],
            [5, 10, 3, 13],
            [8, 25, 3, 28],

            [4, 8, 3, 11],
            [7, 10, 3, 13],
            [3, 25, 3, 28],

            [3, 8, 4, 12],
            [3, 10, 5, 15],
            [3, 25, 6, 31],
        ];
    }

    /**
     * @dataProvider schedulerProcessAmountProvider
     * @param $schedulerIterations
     * @param $startWorkers
     * @param $minIdleProcesses
     * @param $expectedProcesses
     */
    public function testProcessCreationWhenTooLittleOfThemIsWaiting($schedulerIterations, $startWorkers, $minIdleProcesses, $expectedProcesses)
    {
        $scheduler = $this->getScheduler($schedulerIterations);
        $scheduler->getConfig()->setStartProcesses($startWorkers);
        $scheduler->getConfig()->setMinSpareProcesses($minIdleProcesses);

        $amountOfScheduledProcesses = 0;
        $processCount = 0;
        $workers = [];

        $em = $scheduler->getEventManager();
        $sm = $em->getSharedManager();

        $sm->attach('*', WorkerEvent::EVENT_EXIT, function(WorkerEvent $e) {
            $e->stopPropagation(true);
            }, 100000);
        $sm->attach('*', SchedulerEvent::EVENT_STOP, function(SchedulerEvent $e) {$e->stopPropagation(true);}, 0);

        $sm->attach('*', WorkerEvent::EVENT_CREATE,
            function(WorkerEvent $e) use ($em, &$amountOfScheduledProcesses) {
                $amountOfScheduledProcesses++;
                $uid = 100000000 + $amountOfScheduledProcesses;
                $processesCreated[] = $uid;
                $e->setParams(['uid' => $uid, 'initWorker' => true]);
                $e->getWorker()->setProcessId($uid);
                $e->getWorker()->setUid($uid);
                $e->getWorker()->setThreadId(1);
            }, 1000
        );

        $sm->attach('*', WorkerEvent::EVENT_CREATE,
            function(WorkerEvent $e) use (&$scheduler, $em) {
                $e->stopPropagation(true);
                $scheduler->setIsTerminating(false);
            }, WorkerEvent::PRIORITY_FINALIZE - 1
        );

        $sm->attach('*', WorkerEvent::EVENT_INIT,
            function(WorkerEvent $e) use (&$processCount, $startWorkers, &$workers) {
                $worker = $e->getWorker();
                $uid = $worker->getUid();
                $workers[] = $worker;

                $worker->getStatus()->incrementNumberOfFinishedTasks(1000);

                // mark all processes as busy
                if ($uid - 100000000 <= $startWorkers) {
                    $worker->setRunning();
                } else {
                    $worker->setWaiting();
                }
                $e->stopPropagation(true);
            }, WorkerEvent::PRIORITY_FINALIZE + 1
        );

        $scheduler->start(false);

        $this->assertEquals($expectedProcesses, $amountOfScheduledProcesses, "Scheduler should try to create $expectedProcesses processes in total ($startWorkers processes on startup and "  . ($expectedProcesses - $startWorkers) . " additionally if all the other were busy)");
    }

    private function getMpm(Scheduler $scheduler) : DummyMpm
    {
        $sm = $this->getServiceManager();
        $logger = new Logger();
        $logger->addWriter(new Noop());

        $workerEvent = new WorkerEvent();
        $workerEvent->setWorker(new Scheduler\Worker());

        $service = $sm->build(DummyMpm::class, [
            'scheduler_event' => new SchedulerEvent(),
            'worker_event' => $workerEvent,
            'logger_adapter' => $logger,
            'event_manager' => $scheduler->getEventManager()
        ]);

        $this->assertInstanceOf(DummyMpm::class, $service);
        return $service;
    }

    public function testProcessCreationWhenTooManyOfThemIsWaiting()
    {
        $scheduler = $this->getScheduler(4);
        $scheduler->setMultiProcessingModule($this->getMpm($scheduler));
        $scheduler->getConfig()->setStartProcesses(20);
        $scheduler->getConfig()->setMinSpareProcesses(3);
        $scheduler->getConfig()->setMaxSpareProcesses(4);
        $scheduler->getConfig()->setProcessIdleTimeout(0);

        $amountOfScheduledProcesses = 0;
        $amountOfTerminateCommands = 0;
        $processesInitialized = [];
        $processesToTerminate = [];

        $em = $scheduler->getEventManager();
        $sm = $em->getSharedManager();

        $sm->attach('*', WorkerEvent::EVENT_EXIT, function(EventInterface $e) {$e->stopPropagation(true);});
        $sm->attach('*', SchedulerEvent::EVENT_STOP, function(EventInterface $e) {$e->stopPropagation(true);});
        $sm->attach('*', WorkerEvent::EVENT_TERMINATE,
            function(WorkerEvent $processEvent) use ($em, & $processesToTerminate, & $amountOfTerminateCommands) {
                $amountOfTerminateCommands++;
                $processesToTerminate[] = $processEvent->getParam('uid');
            }
        );
        $sm->attach('*', WorkerEvent::EVENT_CREATE,
            function(WorkerEvent $e) use ($em, &$amountOfScheduledProcesses) {
                $amountOfScheduledProcesses++;

                $uid = 100000000 + $amountOfScheduledProcesses;
                $worker = $e->getWorker();
                $worker->setUid($uid);
            }, WorkerEvent::PRIORITY_INITIALIZE + 1
        );

        $sm->attach('*', WorkerEvent::EVENT_INIT,
            function(WorkerEvent $e) use (&$processesInitialized) {
                $e->stopPropagation(true);
            }
        );
        $scheduler->start(false);

        $this->assertGreaterThan(0, $amountOfTerminateCommands, "Scheduler should try to reduce number of workers if too many of them is waiting");
        $this->assertEquals(4, $scheduler->getWorkers()->count(), "Scheduler should try to reduce number of processes to 4 if too many of them is waiting");
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
        $sm = $events->getSharedManager();

        $sm->attach('*', SchedulerEvent::INTERNAL_EVENT_KERNEL_START, function() use (& $serverStarted) {
            $serverStarted = true;
        });

        $sm->attach('*', SchedulerEvent::EVENT_START, function() use (& $schedulerStarted) {
            $schedulerStarted = true;
        });

        $scheduler->start($runInBackground);
        $this->assertTrue($serverStarted, 'Server should have been started when ' . $launchDescription);
        $this->assertTrue($serverStarted, 'Scheduler should have been started when ' . $launchDescription);
    }

    public function testProcessErrorHandling()
    {
        $this->markTestIncomplete("Fix the process UID checks");
        $scheduler = $this->getScheduler(2);
        $logger = new Logger();
        $mockWriter = new Mock();
        $scheduler->setLogger($logger);

        $amountOfScheduledProcesses = 0;
        $processesCreated = [];
        $processesInitialized = [];
        $processes = [];

        $em = $scheduler->getEventManager();
        $sm = $em->getSharedManager();

        $sm->attach('*', WorkerEvent::EVENT_INIT,
            function(WorkerEvent $e) use (&$processCount, &$processes, $mockWriter) {
                $process = $e->getWorker();
                $processes[] = $process;
                $process->getLogger()->addWriter($mockWriter);
            });
        $sm->attach('*', WorkerEvent::EVENT_EXIT, function(EventInterface $e) {$e->stopPropagation(true);}, WorkerEvent::PRIORITY_FINALIZE + 1);
        $sm->attach('*', SchedulerEvent::EVENT_STOP, function(SchedulerEvent $e) {$e->stopPropagation(true);}, 0);
        $sm->attach('*', WorkerEvent::EVENT_CREATE,
            function(WorkerEvent $e) use ($em, &$amountOfScheduledProcesses, &$processesCreated) {
                $amountOfScheduledProcesses++;
                $uid = 100000000 + $amountOfScheduledProcesses;
                $processesCreated[] = $uid;
                $e->setParams(['uid' => $uid, 'initWorker' => true]);
            }, WorkerEvent::PRIORITY_FINALIZE + 1
        );

        $sm->attach('*', WorkerEvent::EVENT_CREATE,
            function(WorkerEvent $e) use (&$scheduler) {
                $e->stopPropagation(true);
                $scheduler->setIsTerminating(false);
            }, SchedulerEvent::PRIORITY_FINALIZE - 1
        );

        $em->attach(WorkerEvent::EVENT_LOOP,
            function(WorkerEvent $e) use (&$processesInitialized) {
                $id = $e->getParam('uid');
                $e->getWorker()->getStatus()->incrementNumberOfFinishedTasks(1001);
                $processesInitialized[] = $id;

                throw new \RuntimeException("Exception thrown by $id!", 10000);
            }
        );

        $scheduler->getLogger()->addWriter($mockWriter);
        $mockWriter->setFormatter(new ConsoleLogFormatter(Console::getInstance()));
        $scheduler->start(false);

        $this->assertEquals(11, $amountOfScheduledProcesses, "Scheduler should try to create 8 processes on its startup and additional 3 as previous failed");
        $this->assertEquals($processesCreated, $processesInitialized, "Scheduler should have initialized all requested processes");

        $foundExceptions = [];
        foreach ($mockWriter->events as $event) {
            if (preg_match('~^RuntimeException \(10000\): Exception thrown by ([0-9]+)~', $event['message'], $matches)) {
                $foundExceptions[$matches[1]] = $matches[1];
            }
        }

        $this->assertEquals(11, count($foundExceptions), "Logger should have reported 11 errors: " . json_encode($foundExceptions));
    }

    public function testProcessShutdownSequence()
    {
        $scheduler = $this->getScheduler(2);

        $amountOfScheduledProcesses = 0;
        $processesCreated = [];

        $em = $scheduler->getEventManager();
        $sm = $em->getSharedManager();
        $sm->attach('*', WorkerEvent::EVENT_EXIT, function(EventInterface $e) {$e->stopPropagation(true);});

        $sm->attach('*', WorkerEvent::EVENT_CREATE,
            function(WorkerEvent $e) use ($em, &$amountOfScheduledProcesses, &$processesCreated) {
                $amountOfScheduledProcesses++;

                $uid = 100000000 + $amountOfScheduledProcesses;
                $processesCreated[$uid] = $uid;

                $worker = $e->getWorker();
                $worker->setUid($uid);
            }, WorkerEvent::PRIORITY_INITIALIZE + 1
        );

        $sm->attach('*', WorkerEvent::EVENT_INIT,
            function(WorkerEvent $e) use (&$processesInitialized) {
                $e->stopPropagation(true);
            }
        );

        $schedulerStopped = false;
        $sm->attach('*', SchedulerEvent::EVENT_STOP,
            function(SchedulerEvent $e) use (&$schedulerStopped) {
                $schedulerStopped = true;
                $e->stopPropagation(true);
            }, -9999);

        $unknownProcesses = [];
        $sm->attach('*', WorkerEvent::EVENT_TERMINATE,
            function(WorkerEvent $e) use ($em, &$processesCreated, &$unknownProcesses) {
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

        $event->setName(SchedulerEvent::EVENT_STOP);
        $scheduler->setIsTerminating(false);
        $scheduler->getEventManager()->triggerEvent($event);

        $this->assertEquals(0, count($unknownProcesses), 'No unknown processes should have been terminated');
        $this->assertEquals(0, count($processesCreated), 'All processes should have been planned to be terminated on scheduler shutdown');
    }

    public function testSchedulerIsTerminatingIfPidFileIsInvalid()
    {
        $scheduler = $this->getScheduler(1);
        $scheduler->getConfig()->setIpcDirectory('invalidSchema://invalidUrl');
        $em = $scheduler->getEventManager();
        $sm = $em->getSharedManager();

        $exitDetected = false;
        $exception = null;

        $sm->attach('*',
            WorkerEvent::EVENT_CREATE, function (WorkerEvent $event) use ($em) {
            $event->setParams(["uid" => 123456789, "server" => true]);
        }
        );
        $sm->attach('*', WorkerEvent::EVENT_EXIT, function(SchedulerEvent $e) {$e->stopPropagation(true);});
        $sm->attach('*', SchedulerEvent::EVENT_STOP,
            function(SchedulerEvent $e) use (&$exitDetected, &$exception) {
                $exitDetected = true;
                $e->stopPropagation(true);
                $exception = $e->getParam('exception');
            },
            -5000
        );

        $scheduler->start(true);

        $this->assertTrue($exitDetected, "Scheduler should shutdown when it can't create PID file");
        $this->assertInstanceOf(SchedulerException::class, $exception, "Exception should be returned in SchedulerEvent");
    }
}