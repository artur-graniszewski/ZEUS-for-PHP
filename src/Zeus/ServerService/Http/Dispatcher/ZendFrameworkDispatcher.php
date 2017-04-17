<?php

namespace Zeus\ServerService\Http\Dispatcher;

use Zend\Console\Console;
use Zend\Http\Request;
use Zend\Http\Response;
use Zend\Mvc\Application;
use Zend\Mvc\MvcEvent;
use Zend\Stdlib\ArrayUtils;
use Zeus\Module;
use Zeus\ServerService\Http\Factory\RequestFactory;
use Zeus\ServerService\Http\ZendFramework\ApplicationProxy;

/**
 * Class ZendFrameworkDispatcher
 * @package Zeus\ServerService\Http\Dispatcher
 * @internal
 */
final class ZendFrameworkDispatcher implements DispatcherInterface
{
    protected static $applicationConfig;

    /**
     * @param mixed[] $config
     */
    public static function setApplicationConfig($config)
    {
        static::$applicationConfig = $config;
    }

    /**
     * ZendFrameworkDispatcher constructor.
     * @param mixed[] $config
     * @param DispatcherInterface|null $anotherDispatcher
     */
    public function __construct(array $config, DispatcherInterface $anotherDispatcher = null)
    {
        if ($anotherDispatcher) {
            throw new \LogicException(__CLASS__ . " dispatcher is final and can't chain another dispatcher");
        }
    }

    /**
     * @return mixed[]
     */
    protected function getApplicationConfig()
    {
        $appConfig = static::$applicationConfig;

        if (!$appConfig) {
            $appConfig = include 'config/application.config.php';

            if (file_exists('config/development.config.php')) {
                $appConfig = ArrayUtils::merge(
                    $appConfig,
                    include 'config/development.config.php'
                );
            }

            // Some OS/Web Server combinations do not glob properly for paths unless they
            // are fully qualified (e.g., IBM i). The following prefixes the default glob
            // path with the value of the current working directory to ensure configuration
            // globbing will work cross-platform.
            if (isset($appConfig['module_listener_options']['config_glob_paths'])) {
                foreach ($appConfig['module_listener_options']['config_glob_paths'] as $index => $path) {
                    if ($path !== 'config/autoload/{,*.}{global,local}.php') {
                        continue;
                    }
                    $appConfig['module_listener_options']['config_glob_paths'][$index] = getcwd() . '/' . $path;
                }
            }

            static::setApplicationConfig($appConfig);
        }

        return $appConfig;
    }

    /**
     * @return $this
     */
    protected function callGarbageCollector()
    {
        $gcEnabled = gc_enabled();
        gc_enable();
        gc_collect_cycles();
        if (!$gcEnabled) {
            gc_disable();
        }

        return $this;
    }

    /**
     * @param string $name
     * @param mixed $value
     */
    protected function setEnvVariable($name, $value)
    {
        $_SERVER[$name] = $value;
    }

    /**
     * @param Request $request
     * @return Response
     */
    public function dispatch(Request $request, Response $response)
    {
        /** @var Application $app */
        static $app;
        // Run the application!
        Console::overrideIsConsole(false);

        $config = Module::getOverrideConfig();
        $config['service_manager']['factories']['Request'] = RequestFactory::class;
        //$app = Application::init($this->getApplicationConfig());
        $config['zeus_process_manager']['services']['Request'] = $request;

        $host = $request->getUri()->getHost();
        $port = $request->getUri()->getPort();
        $this->setEnvVariable('HTTP_HOST', sprintf("%s:%d", $host, $port));
        $this->setEnvVariable('HTTP_PORT', $port);
        $this->setEnvVariable('SERVER_PORT', $port);
        $this->setEnvVariable('SERVER_NAME', $host);

        Module::setOverrideConfig($config);
        if (!$app) {
            $app = ApplicationProxy::init($this->getApplicationConfig());
        }

        $event = $app->getMvcEvent();
        $event->setName(MvcEvent::EVENT_BOOTSTRAP);
        $event->setTarget($app);
        $event->setApplication($app);
        $event->setRequest($request);
        $event->setResponse($app->getServiceManager()->get('Response'));
        $event->setRouter($app->getServiceManager()->get('Router'));
        $event->setRequest($request);

        $app->run();

        Console::overrideIsConsole(true);
        /** @var Response $zendResponse */
        $zendResponse = $app->getResponse();
        $response->setContent($zendResponse->getContent());
        $response->setStatusCode($zendResponse->getStatusCode());
        $response->setContent($zendResponse->getContent());
        $response->setHeaders($zendResponse->getHeaders());
        $app = null;

        $this->callGarbageCollector();
    }
}