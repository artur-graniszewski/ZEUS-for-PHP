<?php

namespace ZeusTest\Kernel\Scheduler;

use PHPUnit\Framework\TestCase;
use Zeus\Kernel\Scheduler\Plugin\DropPrivileges;
use Zeus\Kernel\Scheduler\Status\WorkerState;
use Zeus\Kernel\Scheduler\WorkerEvent;
use ZeusTest\Helpers\ZeusFactories;
use Zeus\Kernel\Scheduler\Command\InitializeWorker;

/**
 * Class SchedulerPluginsTest
 * @package ZeusTest\Kernel\Scheduler
 * @runTestsInSeparateProcesses true
 * @preserveGlobalState disabled
 */
class SchedulerPluginsTest extends TestCase
{
    use ZeusFactories;

    /**
     * @param mixed[] $plugin
     * @return \Zeus\Kernel\SchedulerInterface
     */
    private function getSchedulerWithPlugin(array $plugin)
    {
        $sm = $this->getServiceManager(
            [
                'zeus_process_manager' => [
                    'schedulers' => [
                        'test_scheduler_1' => [
                            'plugins' => $plugin
                        ]
                    ]
                ]
            ]
        );

        return $this->getScheduler(0, null, $sm);
    }

    /**
     * @return \PHPUnit_Framework_MockObject_MockObject|DropPrivileges
     */
    protected function getDropPrivilegesMock()
    {
        $pluginBuilder = $this->getMockBuilder(DropPrivileges::class);
        $pluginBuilder->setMethods([
            'posixSetEuid',
            'posixSetUid',
            'posixSetEgid',
            'posixSetGid'
        ]);

        $pluginBuilder->disableOriginalConstructor();
        $plugin = $pluginBuilder->getMock();

        return $plugin;
    }

    /**
     * @expectedException \Zend\ServiceManager\Exception\ServiceNotCreatedException
     * @expectedExceptionMessageRegExp ~Failed to switch to the group ID~
     */
    public function testDropPrivilegesPluginFailureWhenNotSudoer()
    {
        $this->getSchedulerWithPlugin([
                DropPrivileges::class => [
                    'user' => 'root',
                    'group' => 'root',
                ]
            ]
        );
    }

    public function testDropPrivilegesPluginWhenSudoer()
    {
        $plugin = $this->getDropPrivilegesMock();
        $plugin->expects($this->atLeastOnce())->method("posixSetUid")->will($this->returnValue(true));
        $plugin->expects($this->any())->method("posixSetEuid")->will($this->returnValue(true));
        $plugin->expects($this->atLeastOnce())->method("posixSetGid")->will($this->returnValue(true));
        $plugin->expects($this->any())->method("posixSetEgid")->will($this->returnValue(true));
        $scheduler = $this->getSchedulerWithPlugin([$plugin]);

        $worker = new WorkerState("test");
        $event = new InitializeWorker();
        $event->setWorker($worker);
        $event->setTarget($worker);
        $event->setScheduler($scheduler);

        $plugin->__construct(['user' => 'root', 'group' => 'root']);
        $scheduler->getEventManager()->attach(InitializeWorker::class, function(WorkerEvent $event) {
            $event->stopPropagation(true); // block process main loop
        }, WorkerEvent::PRIORITY_FINALIZE + 1);
        $scheduler->getEventManager()->triggerEvent($event);
    }

    public function testSchedulerDestructor()
    {
        $plugin = $this->getDropPrivilegesMock();
        $plugin->expects($this->atLeastOnce())->method("posixSetUid")->will($this->returnValue(true));
        $plugin->expects($this->any())->method("posixSetEuid")->will($this->returnValue(true));
        $plugin->expects($this->atLeastOnce())->method("posixSetGid")->will($this->returnValue(true));
        $plugin->expects($this->any())->method("posixSetEgid")->will($this->returnValue(true));
        $scheduler = $this->getSchedulerWithPlugin([$plugin]);

        $worker = new WorkerState("test");
        $event = new InitializeWorker();
        $event->setWorker($worker);
        $event->setTarget($worker);
        $event->setScheduler($scheduler);

        $event->setTarget($scheduler);
        $plugin->__construct(['user' => 'root', 'group' => 'root']);
        $scheduler->getEventManager()->attach(InitializeWorker::class, function(WorkerEvent $event) {
            $event->stopPropagation(true); // block process main loop
        }, WorkerEvent::PRIORITY_FINALIZE + 1);

        $scheduler->getEventManager()->triggerEvent($event);
        $this->assertEquals(2, count($scheduler->getPluginRegistry()), 'Two plugins should be registered');
        $scheduler->__destruct();
        $this->assertEquals(1, count($scheduler->getPluginRegistry()), 'No plugin should be registered after Scheduler destruction');
    }

    /**
     * @expectedException \RuntimeException
     * @expectedExceptionMessageRegExp ~Failed to switch to the group ID~
     */
    public function testDropPrivilegesPluginWhenEffectiveSudoerButNotRealSudoer()
    {
        $plugin = $this->getDropPrivilegesMock();

        $plugin->expects($this->never())->method("posixSetUid")->will($this->returnValue(false));
        $plugin->expects($this->exactly(2))->method("posixSetEuid")->will($this->returnValue(true));
        $plugin->expects($this->atLeastOnce())->method("posixSetGid")->will($this->returnValue(false));
        $plugin->expects($this->exactly(2))->method("posixSetEgid")->will($this->returnValue(true));
        $scheduler = $this->getSchedulerWithPlugin([$plugin]);

        $event = new InitializeWorker();
        $event->setScheduler($scheduler);
        $event->setTarget($scheduler);
        $event->setWorker(new WorkerState('test'));
        $plugin->__construct(['user' => 'root', 'group' => 'root']);
        $scheduler->getEventManager()->triggerEvent($event);
    }
}