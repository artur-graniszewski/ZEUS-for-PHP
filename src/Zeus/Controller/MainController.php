<?php

namespace Zeus\Controller;

use InvalidArgumentException;
use Throwable;
use Zend\Console\Console;
use Zend\Log\LoggerInterface;
use Zend\Mvc\Controller\AbstractActionController;
use Zend\Stdlib\RequestInterface;
use Zend\Stdlib\ResponseInterface;
use Zeus\Kernel\Scheduler\Status\SchedulerStatusView;
use Zeus\ServerService\Manager;
use Zend\Console\Request as ConsoleRequest;
use Zeus\ServerService\ServerServiceInterface;

class MainController extends AbstractActionController
{
    /** @var mixed[] */
    private $config;

    /** @var Manager */
    private $manager;

    /** @var ServerServiceInterface[] */
    private $services = [];

    /** @var LoggerInterface */
    private $logger;

    /**
     * ZeusController constructor.
     * @param mixed[] $config
     */
    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function setManager(Manager $manager)
    {
        $this->manager = $manager;
    }

    /**
     * {@inheritdoc}
     */
    public function dispatch(RequestInterface $request, ResponseInterface $response = null)
    {
        if (!$request instanceof ConsoleRequest) {
            throw new InvalidArgumentException(sprintf(
                '%s can only dispatch requests in a console environment',
                get_called_class()
            ));
        }

        // @todo: remove pcnt_signal dependency
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, [$this, 'stopApplication']);
            pcntl_signal(SIGINT, [$this, 'stopApplication']);
            pcntl_signal(SIGTSTP, [$this, 'stopApplication']);
        }

        /** @var \Zend\Stdlib\Parameters $params */
        $params = $request->getParams();

        $action = $params->get(1);
        $serviceName = $params->get(2);

        try {
            switch ($action) {
                case 'start':
                    $this->startServicesCommand($serviceName);
                    break;

                case 'list':
                    $this->listServicesCommand($serviceName);
                    break;

                case 'status':
                    $this->getStatusCommand($serviceName);
                    break;

                case 'stop':
                    $this->stopServicesCommand($serviceName);
                    break;
            }
        } catch (Throwable $exception) {
            $this->getLogger()->err(sprintf("Exception (%d): %s in %s on line %d",
                $exception->getCode(),
                addcslashes($exception->getMessage(), "\t\n\r\0\x0B"),
                $exception->getFile(),
                $exception->getLine()
            ));
            $this->getLogger()->debug(sprintf("Stack Trace:\n%s", $exception->getTraceAsString()));
            $this->doExit($exception->getCode() > 0 ? $exception->getCode() : 500);
        }
    }

    private function doExit(int $code)
    {
        exit($code);
    }

    /**
     * @param string $serviceName
     * @param bool $autoStartOnly
     * @return string[]
     */
    private function getServices(string $serviceName = null, bool $autoStartOnly = false) : array
    {
        if ($this->reportBrokenServices($serviceName)) {
            return [];
        }

        return $serviceName
            ?
            [$serviceName]
            :
            $this->manager->getServiceList($autoStartOnly);
    }

    /**
     * @param string $serviceName
     * @return bool
     */
    private function reportBrokenServices($serviceName)
    {
        $result = false;
        $brokenServices = $this->manager->getBrokenServices();

        $services = $serviceName !== null ? [$serviceName] : array_keys($brokenServices);

        foreach ($services as $serviceName) {
            if ($serviceName && isset($brokenServices[$serviceName])) {
                /** @var \Exception $exception */
                $exception = $brokenServices[$serviceName];
                $exceptionMessage = $exception->getPrevious() ? $exception->getPrevious()->getMessage() : $exception->getMessage();
                $this->getLogger()->err("Service \"$serviceName\" is broken: " . $exceptionMessage);
                $result = true;
            }
        }

        return $result;
    }

    /**
     * @param string $serviceName
     */
    private function getStatusCommand($serviceName)
    {
        $services = $this->getServices($serviceName, false);

        foreach ($services as $serviceName) {
            $status = $this->manager->getServiceStatus($serviceName, new SchedulerStatusView(Console::getInstance()));

            if ($status) {
                $this->getLogger()->info($status);

                return;

            }

            $this->getLogger()->err("Service \"$serviceName\" is offline or too busy to respond");
        }
    }

    /**
     * @param string $serviceName
     */
    private function listServicesCommand($serviceName)
    {
        $services = $this->getServices($serviceName, false);

        $output = null;
        foreach ($services as $serviceName) {
            $serviceConfig = $this->manager->getServiceConfig($serviceName);
            $config = array_slice(
                explode("\n", print_r($serviceConfig, true)), 1, -1);

            $output .= PHP_EOL . 'Service configuration for "' . $serviceName . '"":' . PHP_EOL . implode(PHP_EOL, $config) . PHP_EOL;
        }

        if ($output) {
            $this->getLogger()->info('Configuration details:' . $output);

            return;
        }

        $this->getLogger()->err('No Server Service found');
    }

    private function startServicesCommand(string $serviceName = null)
    {
        $services = $this->getServices($serviceName, true);

        $this->services = $services;
        $this->manager->startServices($services);
    }

    /**
     * @param ServerServiceInterface[] $services
     * @param bool $mustBeRunning
     * @throws \Exception
     */
    private function stopServices($services, bool $mustBeRunning)
    {
        $servicesLeft = $this->manager->stopServices($services, $mustBeRunning);

        if ($servicesLeft === 0) {
            $this->doExit(0);
        }

        $this->doExit(417);
    }

    public function stopApplication()
    {
        $this->stopServices($this->services, false);
    }

    private function stopServicesCommand($serviceName)
    {
        $services = $this->getServices($serviceName, false);
        $this->stopServices($services, false);
    }

    public function getLogger() : LoggerInterface
    {
        return $this->logger;
    }

    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }
}