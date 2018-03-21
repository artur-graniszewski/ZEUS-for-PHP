<?php

namespace Zeus;

use Zend\Console\Adapter\AdapterInterface as ConsoleAdapter;
use Zend\Console\ColorInterface;
use Zend\EventManager\EventInterface;
use Zend\ModuleManager\Feature\AutoloaderProviderInterface;
use Zend\ModuleManager\Feature\BootstrapListenerInterface;
use Zend\ModuleManager\Feature\ConfigProviderInterface;
use Zend\ModuleManager\Feature\ConsoleBannerProviderInterface;
use Zend\ModuleManager\Feature\ConsoleUsageProviderInterface;
use Zend\ModuleManager\ModuleEvent;
use Zend\ModuleManager\ModuleManager;
use Zend\Stdlib\ArrayUtils;
use Zeus\Kernel\System\Runtime;

use function date_default_timezone_set;
use function realpath;
use function ini_get;

class Module implements
    AutoloaderProviderInterface,
    BootstrapListenerInterface,
    ConfigProviderInterface,
    ConsoleUsageProviderInterface,
    ConsoleBannerProviderInterface
{
    const MODULE_VERSION = "2.0.0";

    protected static $overrideConfig = [];

    public static function setOverrideConfig(array $overrideConfig)
    {
        static::$overrideConfig = $overrideConfig;
    }

    public static function getOverrideConfig() : array
    {
        return static::$overrideConfig;
    }

    /**
     * Listen to the bootstrap event
     *
     * @param EventInterface $event
     * @return void
     */
    public function onBootstrap(EventInterface $event)
    {
        Runtime::init();
        if (!ini_get('date.timezone')) {
            date_default_timezone_set("UTC");
        }
    }

    /**
     * Get config
     *
     * @return mixed[]
     */
    public function getConfig()
    {
        return include __DIR__ . '/../../config/module.config.php';
    }

    /**
     * Return an array for passing to Zend\Loader\AutoloaderFactory.
     *
     * @return mixed[]
     */
    public function getAutoloaderConfig()
    {
        return [
            'Zend\Loader\StandardAutoloader' => [
                'namespaces' => [
                    __NAMESPACE__ => __DIR__,
                ],
            ],
        ];
    }

    /**
     * Get console usage
     *
     * @param ConsoleAdapter $adapter
     * @return mixed[]
     */
    public function getConsoleUsage(ConsoleAdapter $adapter)
    {
        $usage = [];

        $usage['zeus start'] = 'Starts all ZEUS Server Services';
        $usage['zeus start [<service-name>]'] = 'Starts selected Server Service';

        $usage['zeus list'] = 'Lists all Server Services and their configuration';
        $usage['zeus list [<service-name>]'] = 'Shows configuration of a selected Server Service';

        $usage['zeus status'] = 'Returns current status of all Server Services';
        $usage['zeus status [<service-name>]'] = 'Returns current status of the selected Server Service';

        $usage['zeus stop'] = 'Stops all ZEUS Server Services';
        $usage['zeus stop [<service-name>]'] = 'Stops selected Server Service';

        return $usage;
    }

    /**
     * @param ModuleManager $moduleManager
     */
    public function init(ModuleManager $moduleManager)
    {
        $events = $moduleManager->getEventManager();

        // Registering a listener at default priority, 1, which will trigger
        // after the ConfigListener merges config.
        $events->attach(ModuleEvent::EVENT_MERGE_CONFIG, [$this, 'onMergeConfig']);
    }

    /**
     * @param ModuleEvent $event
     */
    public function onMergeConfig(ModuleEvent $event)
    {
        $configListener = $event->getConfigListener();
        $config = $configListener->getMergedConfig(false);

        if (static::getOverrideConfig()) {
            $config = ArrayUtils::merge($config, static::getOverrideConfig());
        }

        // Pass the changed configuration back to the listener:
        $configListener->setMergedConfig($config);
    }

    /**
     * @param ConsoleAdapter $console
     * @return string
     */
    public function getConsoleBanner(ConsoleAdapter $console)
    {
        $banner = PHP_EOL;
        $banner .= ' __________            _________
 \____    /____  __ __/   _____/ PHP
   /     // __ \|  |  \_____  \
  /     /\  ___/|  |  /        \
 /_______ \___  >____/_______  /
         \/   \/             \/ ';

        $banner .= PHP_EOL;
        $banner .= $console->colorize(' ZEUS for PHP - ZF3 Edition', ColorInterface::GREEN);
        $banner .= $console->colorize('   (' . self::MODULE_VERSION . ')', ColorInterface::CYAN);
        $banner .= PHP_EOL;

        return $banner;
    }
}
