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
        $loggerConfig = $config['zeus_process_manager']['logger'];

        $severity = isset($loggerConfig['reporting_level']) ?
            $loggerConfig['reporting_level'] : Logger::DEBUG;

        $output = isset($loggerConfig['output']) ?
            $loggerConfig['output'] : 'php://stdout';

        $showBanner = isset($loggerConfig['show_banner']) ?
            $loggerConfig['show_banner'] : true;

        $loggerInstance = isset($loggerConfig['logger_adapter']) ?
            $container->get($loggerConfig['logger_adapter']) : new Logger();

        $loggerWrapper = new LoggerWrapper($loggerInstance);

        // its a custom Logger
        if (!$loggerInstance instanceof Logger) {

            return $loggerWrapper;
        }

        // its a built-in logger
        $banner = $this->getBannerFromModule($container, $options['service_name']);

        $logProcessor = new ExtraLogProcessor();
        $logProcessor->setConfig(['service_name' => $options['service_name']]);
        $loggerWrapper->addProcessor($logProcessor);

        $formatter = $output === 'php://stdout' ?
            new ConsoleLogFormatter(Console::getInstance())
            :
            new StreamLogFormatter();

        $writer = new Writer\Stream($output);
        $writer->addFilter(new Priority($severity));
        $loggerWrapper->addWriter($writer);
        $writer->setFormatter($formatter);
        if ($showBanner && $banner) {
            $loggerWrapper->info($banner);
        }

        return $loggerWrapper;
    }

    /**
     * @param ContainerInterface $container
     * @param string $serviceName
     * @return null|string
     */
    protected function getBannerFromModule(ContainerInterface $container, $serviceName)
    {
        /** @var ModuleManagerInterface $moduleManager */
        $moduleManager = $container->get('ModuleManager');
        $banner = null;

        if ($serviceName === 'main' && static::$showBanner) {
            foreach ($moduleManager->getLoadedModules(false) as $module) {
                if ($module instanceof Module) {
                    $banner = $module->getConsoleBanner(Console::getInstance());
                }
            }
        }

        return $banner;
    }
}