<?php

namespace Zeus\ServerService\Http\ZendFramework;

use Zend\Mvc\Service;
use Zend\ServiceManager\ServiceManager;

/**
 * Class ApplicationProxy
 * @package Zeus\ServerService\Http\ZendFramework
 * @internal
 * @deprecated
 */
final class ApplicationProxy
{
    /**
     * @param mixed[] $configuration
     * @return $this
     */
    public static function init($configuration = [])
    {
        // Prepare the service manager
        $smConfig = isset($configuration['service_manager']) ? $configuration['service_manager'] : [];
        $smConfig = new Service\ServiceManagerConfig($smConfig);

        $serviceManager = new ServiceManager();
        $smConfig->configureServiceManager($serviceManager);
        $serviceManager->setService('ApplicationConfig', $configuration);

        // Load modules
        $serviceManager->get('ModuleManager')->loadModules();

        $listenersFromAppConfig     = isset($configuration['listeners']) ? $configuration['listeners'] : [];
        $config                     = $serviceManager->get('config');
        $listenersFromConfigService = isset($config['listeners']) ? $config['listeners'] : [];

        $listeners = array_unique(array_merge($listenersFromConfigService, $listenersFromAppConfig));

        $application = $serviceManager->get('Application')->bootstrap($listeners);

        return $application;
    }
}