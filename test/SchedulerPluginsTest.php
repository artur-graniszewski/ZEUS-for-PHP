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

        // maybe set the function protected for this to work
        $plugin = $this->getMock(DropPrivileges::class, [
            'posixSetEuid',
            'posixSetUid',
            'posixSetEgid',
            'posixSetGid'
        ], [['user' => 'root', 'group' => 'root']]);
        $plugin->expects($this->atLeastOnce())->method("posixSetUid")->will($this->returnValue(true));
        $plugin->expects($this->any())->method("posixSetEuid")->will($this->returnValue(true));
        $plugin->expects($this->atLeastOnce())->method("posixSetGid")->will($this->returnValue(true));
        $plugin->expects($this->any())->method("posixSetEgid")->will($this->returnValue(true));
        $scheduler = $this->getSchedulerWithPlugin([$plugin]);
        $event->setScheduler($scheduler);
        $scheduler->getEventManager()->triggerEvent($event);
    }

    /**
     * @expectedException \RuntimeException
     * @expectedExceptionMessageRegExp ~Failed to switch to the group ID~
     */
    public function testDropPrivilegesPluginWhenEffectiveSudoerButNotRealSudoer()
    {
        $event = new SchedulerEvent();
        $event->setName(SchedulerEvent::EVENT_PROCESS_INIT);

        // maybe set the function protected for this to work
        $plugin = $this->getMock(DropPrivileges::class, [
            'posixSetEuid',
            'posixSetUid',
            'posixSetEgid',
            'posixSetGid'
        ], [['user' => 'root', 'group' => 'root']]);
        $plugin->expects($this->never())->method("posixSetUid")->will($this->returnValue(false));
        $plugin->expects($this->any())->method("posixSetEuid")->will($this->returnValue(true));
        $plugin->expects($this->atLeastOnce())->method("posixSetGid")->will($this->returnValue(false));
        $plugin->expects($this->any())->method("posixSetEgid")->will($this->returnValue(true));
        $scheduler = $this->getSchedulerWithPlugin([$plugin]);
        $event->setScheduler($scheduler);
        $scheduler->getEventManager()->triggerEvent($event);
    }
}