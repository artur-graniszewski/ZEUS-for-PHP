<?php

namespace ZeusTest;

use PHPUnit_Framework_TestCase;
use Zeus\Kernel\ProcessManager\Plugin\DropPrivileges;
use Zeus\Kernel\ProcessManager\SchedulerEvent;
use ZeusTest\Helpers\ZeusFactories;

class SchedulerPluginsTest extends PHPUnit_Framework_TestCase
{
    use ZeusFactories;

    /**
     * @param mixed[] $plugin
     * @return \Zeus\Kernel\ProcessManager\Scheduler
     */
    protected function getSchedulerWithPlugin(array $plugin)
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
                \Zeus\Kernel\ProcessManager\Plugin\DropPrivileges::class => [
                    'user' => 'root',
                    'group' => 'root',
                ]
            ]
        );
    }

    public function testDropPrivilegesPluginWhenSudoer()
    {
        $event = new SchedulerEvent();
        $event->setName(SchedulerEvent::EVENT_PROCESS_INIT);

        $plugin = $this->getDropPrivilegesMock();
        $plugin->expects($this->atLeastOnce())->method("posixSetUid")->will($this->returnValue(true));
        $plugin->expects($this->any())->method("posixSetEuid")->will($this->returnValue(true));
        $plugin->expects($this->atLeastOnce())->method("posixSetGid")->will($this->returnValue(true));
        $plugin->expects($this->any())->method("posixSetEgid")->will($this->returnValue(true));
        $scheduler = $this->getSchedulerWithPlugin([$plugin]);
        $event->setScheduler($scheduler);
        $plugin->__construct(['user' => 'root', 'group' => 'root']);
        $scheduler->getEventManager()->attach(SchedulerEvent::EVENT_PROCESS_INIT, function(SchedulerEvent $event) {
            $event->stopPropagation(true); // block process main loop
        }, SchedulerEvent::PRIORITY_FINALIZE + 1);
        $scheduler->getEventManager()->triggerEvent($event);
    }

    public function testSchedulerDestructor()
    {
        $event = new SchedulerEvent();
        $event->setName(SchedulerEvent::EVENT_PROCESS_INIT);

        $plugin = $this->getDropPrivilegesMock();
        $plugin->expects($this->atLeastOnce())->method("posixSetUid")->will($this->returnValue(true));
        $plugin->expects($this->any())->method("posixSetEuid")->will($this->returnValue(true));
        $plugin->expects($this->atLeastOnce())->method("posixSetGid")->will($this->returnValue(true));
        $plugin->expects($this->any())->method("posixSetEgid")->will($this->returnValue(true));
        $scheduler = $this->getSchedulerWithPlugin([$plugin]);
        $event->setScheduler($scheduler);
        $plugin->__construct(['user' => 'root', 'group' => 'root']);
        $scheduler->getEventManager()->attach(SchedulerEvent::EVENT_PROCESS_INIT, function(SchedulerEvent $event) {
            $event->stopPropagation(true); // block process main loop
        }, SchedulerEvent::PRIORITY_FINALIZE + 1);

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
        $event = new SchedulerEvent();
        $event->setName(SchedulerEvent::EVENT_PROCESS_INIT);

        $plugin = $this->getDropPrivilegesMock();

        $plugin->expects($this->never())->method("posixSetUid")->will($this->returnValue(false));
        $plugin->expects($this->exactly(2))->method("posixSetEuid")->will($this->returnValue(true));
        $plugin->expects($this->atLeastOnce())->method("posixSetGid")->will($this->returnValue(false));
        $plugin->expects($this->exactly(2))->method("posixSetEgid")->will($this->returnValue(true));
        $scheduler = $this->getSchedulerWithPlugin([$plugin]);
        $event->setScheduler($scheduler);
        $plugin->__construct(['user' => 'root', 'group' => 'root']);
        $scheduler->getEventManager()->triggerEvent($event);
    }
}