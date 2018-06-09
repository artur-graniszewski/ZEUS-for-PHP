<?php

namespace ZeusTest;

use \PHPUnit\Framework\TestCase;
use Zend\Console\Console;
use Zend\ModuleManager\Listener\ConfigListener;
use Zend\ModuleManager\ModuleEvent;
use Zend\ModuleManager\ModuleManager;
use Zeus\Module;
use ZeusTest\Helpers\ZeusFactories;

/**
 * Class ZeusModuleTest
 * @package ZeusTest
 * @runTestsInSeparateProcesses true
 * @preserveGlobalState disabled
 */
class ZeusModuleTest extends \PHPUnit\Framework\TestCase
{
    use ZeusFactories;

    public function testGetBanner()
    {
        $module = new Module();
        $banner = $module->getConsoleBanner(Console::getInstance());

        $this->assertGreaterThan(0, strpos($banner, 'ZEUS for PHP'));
    }

    public function testGetUsage()
    {
        $module = new Module();
        $usage = $module->getConsoleUsage(Console::getInstance());

        foreach (['start', 'start [<service-name>]', 'list', 'list [<service-name>]', 'start', 'start [<service-name>]'] as $command)
        $this->assertArrayHasKey('zeus ' . $command, $usage);
    }

    public function testModuleOverridesConfig()
    {
        $sm = $this->getServiceManager();
        /** @var ModuleManager $moduleManager */
        $moduleManager = $sm->get('ModuleManager');
        $module = new Module();
        $module->init($moduleManager);
        Module::setOverrideConfig(['test_passed' => 'true']);
        $event = new ModuleEvent();
        $event->setName(ModuleEvent::EVENT_MERGE_CONFIG);
        $event->setConfigListener(new ConfigListener());
        $moduleManager->getEventManager()->triggerEvent($event, $this);
        $this->assertEquals('true', $event->getConfigListener()->getMergedConfig()->get('test_passed', null));
    }
}