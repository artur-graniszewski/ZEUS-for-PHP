<?php

namespace Zeus\ServerService\Shared\Logger;

use Interop\Container\ContainerInterface;
use Interop\Container\Exception\ContainerException;
use Zend\Log\Filter\Priority;
use Zend\ModuleManager\ModuleManagerInterface;
use Zend\ServiceManager\Exception\ServiceNotCreatedException;
use Zend\ServiceManager\Exception\ServiceNotFoundException;
use Zend\ServiceManager\Factory\FactoryInterface;
use Zend\Console\Console;
use Zend\Log\Logger;
use Zend\Log\Writer;
use Zeus\Module;

class LoggerFactory implements FactoryInterface
{
    protected static $showBanner = true;

    /**
     * Create an object
     *
     * @param  ContainerInterface $container
     * @param  string $requestedName
     * @param  null|array $options
     * @return object
     * @throws ServiceNotFoundException if unable to resolve the service.
     * @throws ServiceNotCreatedException if an exception is raised when
     *     creating a service.
     * @throws ContainerException if any other error occurs
     */
    public function __invoke(ContainerInterface $container, $requestedName, array $options = null)
    {
        $config = $container->get('configuration');
        $severity = isset($config['zeus_process_manager']['logger']['reporting_level']) ?
            $config['zeus_process_manager']['logger']['reporting_level'] : Logger::DEBUG;

        $output = isset($config['zeus_process_manager']['logger']['output']) ?
            $config['zeus_process_manager']['logger']['output'] : 'php://stdout';

        $showBanner = isset($config['zeus_process_manager']['logger']['show_banner']) ?
            $config['zeus_process_manager']['logger']['show_banner'] : true;

        /** @var ModuleManagerInterface $moduleManager */
        $moduleManager = $container->get('ModuleManager');
        $banner = null;

        if ($options['service_name'] === 'main' && static::$showBanner) {
            foreach ($moduleManager->getLoadedModules(false) as $module) {
                if ($module instanceof Module) {
                    $banner = $module->getConsoleBanner(Console::getInstance());
                }
            }

            static::$showBanner = false;
        }

        $isCustomLogger = false;
        if (!isset($config['zeus_process_manager']['logger']['logger_adapter'])) {
            $loggerInstance = new Logger();
        } else {
            $loggerInstance = $container->get($config['zeus_process_manager']['logger']['logger_adapter']);
            $isCustomLogger = true;
        }
        $loggerWrapper = new LoggerWrapper($loggerInstance);

        $logProcessor = new ExtraLogProcessor();
        if (is_callable([$logProcessor, 'setConfig'])) {
            $logProcessor->setConfig(['service_name' => $options['service_name']]);
        }
        $loggerWrapper->addProcessor($logProcessor);

        if (!$isCustomLogger) {
            if ($output === 'php://stdout') {
                $formatter = new ConsoleLogFormatter(Console::getInstance());
            } else {
                $formatter = new StreamLogFormatter();
            }
            $writer = new Writer\Stream($output);
            $writer->addFilter(new Priority($severity));
            $loggerWrapper->addWriter($writer);
            $writer->setFormatter($formatter);
            if ($showBanner && $banner) {
                $loggerWrapper->info($banner);
            }
        }

        return $loggerWrapper;
    }
}