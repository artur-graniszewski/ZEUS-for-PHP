<?php

namespace ZeusTest;

use PHPUnit_Framework_TestCase;
use Zend\Console\Console;
use Zend\EventManager\Event;
use Zend\EventManager\EventInterface;
use Zend\Log\Logger;
use Zend\Log\Writer\Mock;
use Zend\ServiceManager\Exception\ServiceNotCreatedException;
use Zend\ServiceManager\ServiceManager;
use Zeus\Kernel\ProcessManager\Exception\ProcessManagerException;
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
        $scheduler = $this->getScheduler();
        $this->assertInstanceOf(Scheduler::class, $scheduler);
        $scheduler->setContinueMainLoop(false);
        $scheduler->startScheduler(new Event());
        $this->assertEquals(getmypid(), $scheduler->getId());
    }

    public function testMainLoopIteration()
    {
        $scheduler = $this->getScheduler();
        $this->assertInstanceOf(Scheduler::class, $scheduler);

        $events = $scheduler->getEventManager();
        $counter = 0;
        $events->attach(SchedulerEvent::EVENT_SCHEDULER_LOOP, function(SchedulerEvent $e) use (&$counter) {
            $e->getScheduler()->setContinueMainLoop(false);
            $counter++;
        });

        $scheduler->startScheduler(new Event());
        $this->assertEquals(1, $counter, 'Loop should have been executed only once');
    }

    public function testIpcLogDispatching()
    {
        $scheduler = $this->getScheduler(1);
        $logger = $scheduler->getLogger();
        $ipc = $scheduler->getIpcAdapter();

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

        $scheduler->startScheduler(new Event());
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

    public function testProcessCreationOnStartup()
    {
        $scheduler = $this->getScheduler(1);

        $amountOfScheduledProcesses = 0;
        $processesCreated = [];
        $processesInitialized = [];

        $em = $scheduler->getEventManager();
        $em->attach(SchedulerEvent::EVENT_PROCESS_EXIT, function(EventInterface $e) {$e->stopPropagation(true);});
        $em->attach(SchedulerEvent::EVENT_PROCESS_CREATE,
            function(EventInterface $e) use ($em) {
                $e->stopPropagation(true);
                $em->trigger(SchedulerEvent::EVENT_PROCESS_CREATED, null, []);
            }
        );
        $em->attach(SchedulerEvent::EVENT_PROCESS_CREATED,
            function(EventInterface $e) use (&$amountOfScheduledProcesses, &$processesCreated, $em) {
                $amountOfScheduledProcesses++;

                $uid = 100000000 + $amountOfScheduledProcesses;
                $event = new SchedulerEvent();
                $event->setName(SchedulerEvent::EVENT_PROCESS_INIT);
                $event->setParams(['uid' => $uid]);
                $em->triggerEvent($event);
                $processesCreated[] = $uid;
            }
        );
        $em->attach(SchedulerEvent::EVENT_PROCESS_LOOP,
            function(SchedulerEvent $e) use (&$processesInitialized) {
                $processesInitialized[] = $e->getProcess()->getId();

                // kill the processs
                $e->getProcess()->getStatus()->incrementNumberOfFinishedTasks(100);
            }
        );
        $scheduler->startScheduler(new Event());

        $this->assertEquals(8, $amountOfScheduledProcesses, "Scheduler should try to create 8 processes on its startup");
        $this->assertEquals($processesCreated, $processesInitialized, "Scheduler should have initialized all requested processes");
    }

    public function testProcessCreationWhenTooLittleOfThemIsWaiting()
    {
        $scheduler = $this->getScheduler(2);

        $amountOfScheduledProcesses = 0;
        $processesCreated = [];
        $processesInitialized = [];

        $em = $scheduler->getEventManager();
        $em->attach(SchedulerEvent::EVENT_PROCESS_EXIT, function(EventInterface $e) {$e->stopPropagation(true);});
        $em->attach(SchedulerEvent::EVENT_PROCESS_INIT, function(EventInterface $e) {$e->stopPropagation(true);});
        $em->attach(SchedulerEvent::EVENT_PROCESS_CREATE,
            function(EventInterface $e) use ($em) {
                $e->stopPropagation(true);
                $em->trigger(SchedulerEvent::EVENT_PROCESS_CREATED, null, []);
            }
        );
        $em->attach(SchedulerEvent::EVENT_PROCESS_CREATED,
            function(EventInterface $e) use (&$amountOfScheduledProcesses, &$processesCreated, $em) {
                $amountOfScheduledProcesses++;

                $uid = 100000000 + $amountOfScheduledProcesses;
                $event = new SchedulerEvent();
                $event->setName(SchedulerEvent::EVENT_PROCESS_INIT);
                $event->setParams(['uid' => $uid]);
                $em->triggerEvent($event);
                $processesCreated[] = $uid;
            }
        );
        $em->attach(SchedulerEvent::EVENT_PROCESS_LOOP,
            function(SchedulerEvent $e) use (&$processesInitialized) {
                $uid = $e->getProcess()->getId();
                $processesInitialized[] = $uid;

                // kill the processs
                $e->getProcess()->getStatus()->incrementNumberOfFinishedTasks(100);
                if ($uid < 10000001) {
                    $e->getProcess()->setRunning();
                }
            }
        );
        $scheduler->startScheduler(new Event());

        $this->assertEquals(11, $amountOfScheduledProcesses, "Scheduler should try to create 8 processes on startup and 3 additional one if all the previous were busy");
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
        $em->attach(SchedulerEvent::EVENT_PROCESS_EXIT, function(EventInterface $e) {$e->stopPropagation(true);});
        $em->attach(SchedulerEvent::EVENT_PROCESS_TERMINATE,
            function(SchedulerEvent $processEvent) use ($em, & $processesToTerminate, & $amountOfTerminateCommands) {
                $processEvent->stopPropagation(true);
                $amountOfTerminateCommands++;
                $processesToTerminate[] = $processEvent->getParam('uid');
            }
        );
        $em->attach(SchedulerEvent::EVENT_PROCESS_CREATE,
            function(SchedulerEvent $e) use ($em, &$amountOfScheduledProcesses) {
                $amountOfScheduledProcesses++;

                $uid = 100000000 + $amountOfScheduledProcesses;
                $e->stopPropagation(true);
                $e->setName(SchedulerEvent::EVENT_PROCESS_CREATED);
                $e->setParam('uid', $uid);
                $em->triggerEvent($e);
            }
        );
        $em->attach(SchedulerEvent::EVENT_PROCESS_CREATED,
            function(SchedulerEvent $event) use (&$amountOfScheduledProcesses, &$processesCreated, $em, $scheduler) {
                $processesCreated[] = $event->getParam('uid');
            }
        );
        $em->attach(SchedulerEvent::EVENT_PROCESS_LOOP,
            function(SchedulerEvent $e) use (&$processesInitialized) {
                $uid = $e->getProcess()->getId();
                $processesInitialized[] = $uid;

                // kill the processs
                $e->getProcess()->getStatus()->incrementNumberOfFinishedTasks(100);
            }
        );
        $scheduler->startScheduler(new Event());

        $this->assertEquals(15, $amountOfTerminateCommands, "Scheduler should try to reduce number of processes to 5 if too many of them is waiting");
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
     */
    public function testSchedulerStartingEvents($runInBackground, $launchDescription)
    {
        $serverStarted = false;
        $schedulerStarted = false;
        $scheduler = $this->getScheduler(1);
        $events = $scheduler->getEventManager();

        $events->attach(SchedulerEvent::INTERNAL_EVENT_KERNEL_START, function() use (& $serverStarted) {
            $serverStarted = true;
        });

        $events->attach(SchedulerEvent::EVENT_SCHEDULER_START, function() use (& $schedulerStarted) {
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
        $em->attach(SchedulerEvent::EVENT_PROCESS_EXIT, function(EventInterface $e) {$e->stopPropagation(true);});
        $em->attach(SchedulerEvent::EVENT_PROCESS_CREATE,
            function(EventInterface $e) use ($em) {
                $e->stopPropagation(true);
                $em->trigger(SchedulerEvent::EVENT_PROCESS_CREATED, null, []);
            }
        );
        $em->attach(SchedulerEvent::EVENT_PROCESS_CREATED,
            function(EventInterface $e) use (&$amountOfScheduledProcesses, &$processesCreated, $em) {
                $amountOfScheduledProcesses++;
                $e->stopPropagation(true);
                $uid = 100000000 + $amountOfScheduledProcesses;
                $event = new SchedulerEvent();
                $event->setName(SchedulerEvent::EVENT_PROCESS_INIT);
                $event->setParams(['uid' => $uid]);
                $em->triggerEvent($event);
                $processesCreated[] = $uid;
            }
        );
        $em->attach(SchedulerEvent::EVENT_PROCESS_LOOP,
            function(SchedulerEvent $e) use (&$processesInitialized) {
                $id = $e->getProcess()->getId();
                if (in_array($id, $processesInitialized)) {
                    $e->getProcess()->setRunning();
                    $e->getProcess()->getStatus()->incrementNumberOfFinishedTasks(100);
                    $e->getProcess()->setWaiting();
                    return;
                }
                $processesInitialized[] = $id;

                $e->getProcess()->setRunning();
                throw new \RuntimeException("Exception thrown by $id!", 10000);
            }
        );

        $logger = $scheduler->getLogger();
        $mockWriter = new Mock();
        $scheduler->setLogger($logger);
        $scheduler->getLogger()->addWriter($mockWriter);
        $mockWriter->setFormatter(new ConsoleLogFormatter(Console::getInstance()));
        $scheduler->startScheduler(new SchedulerEvent());

        $this->assertEquals(8, $amountOfScheduledProcesses, "Scheduler should try to create 8 processes on its startup");
        $this->assertEquals($processesCreated, $processesInitialized, "Scheduler should have initialized all requested processes");

        $foundExceptions = [];
        foreach ($mockWriter->events as $event) {
            if (preg_match('~^Exception \(10000\): Exception thrown by ([0-9]+)~', $event['message'], $matches)) {
                $foundExceptions[] = $matches[1];
            }
        }

        $this->assertEquals(8, count($foundExceptions), "Logger should have reported 8 errors");
    }

    public function testProcessShutdownSequence()
    {
        $scheduler = $this->getScheduler(1);

        $amountOfScheduledProcesses = 0;
        $processesCreated = [];

        $em = $scheduler->getEventManager();
        $em->attach(SchedulerEvent::EVENT_PROCESS_EXIT, function(EventInterface $e) {$e->stopPropagation(true);});
        $em->attach(SchedulerEvent::EVENT_PROCESS_CREATE,
            function(EventInterface $e) use (&$amountOfScheduledProcesses, &$processesCreated, $em) {
                $amountOfScheduledProcesses++;

                $uid = 100000000 + $amountOfScheduledProcesses;
                $processesCreated[$uid] = true;
                $em->trigger(SchedulerEvent::EVENT_PROCESS_CREATED, null, ['uid' => $uid]);
            }
        );
        $em->attach(SchedulerEvent::EVENT_PROCESS_LOOP,
            function(EventInterface $e) {
                // stop the process
                $e->getTarget()->getStatus()->incrementNumberOfFinishedTasks(100);
            }
        );

        $schedulerStopped = false;
        $em->attach(SchedulerEvent::EVENT_SCHEDULER_STOP,
            function(EventInterface $e) use (&$schedulerStopped) {
                $schedulerStopped = true;
                $e->stopPropagation(true);
            }, -9999);

        $unknownProcesses = [];
        $em->attach(SchedulerEvent::EVENT_PROCESS_TERMINATE,
            function(EventInterface $e) use ($em) {
                $uid = $e->getParam('uid');
                $em->trigger(SchedulerEvent::EVENT_PROCESS_TERMINATED, null, ['uid' => $uid]);
            }
        );

        $em->attach(SchedulerEvent::EVENT_PROCESS_TERMINATED,
            function(EventInterface $e) use (&$unknownProcesses, &$processesCreated, $em) {
                $uid = $e->getParam('uid');
                if (!isset($processesCreated[$uid])) {
                    $unknownProcesses[] = true;
                } else {
                    unset($processesCreated[$uid]);
                }
            }
        );

        $scheduler->startScheduler(new Event());

        $this->assertEquals(8, $amountOfScheduledProcesses, "Scheduler should try to create 8 processes on its startup");

        $scheduler->getEventManager()->trigger(SchedulerEvent::EVENT_SCHEDULER_STOP, null);

        $this->assertEquals(0, count($processesCreated), 'All processes should have been planned to be terminated on scheduler shutdown');
        $this->assertEquals(0, count($unknownProcesses), 'No unknown processes should have been terminated');
    }
}