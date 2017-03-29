<?php

namespace Zeus\Controller;

use Zend\Console\Console;
use Zend\Log\LoggerInterface;
use Zend\Mvc\Controller\AbstractActionController;
use Zend\Stdlib\RequestInterface;
use Zend\Stdlib\ResponseInterface;
use Zeus\Kernel\ProcessManager\Status\SchedulerStatusView;
use Zeus\ServerService\Manager;
use Zend\Console\Request as ConsoleRequest;
use Zeus\ServerService\ServerServiceInterface;

class ZeusController extends AbstractActionController
{
    /** @var mixed[] */
    protected $config;

    /** @var Manager */
    protected $manager;

    /** @var ServerServiceInterface[] */
    protected $services = [];

    /** @var LoggerInterface */
    protected $logger;

    /** @var int */
    protected $servicesRunning = 0;

    /**
     * ZeusController constructor.
     * @param mixed[] $config
     * @param Manager $manager
     * @param LoggerInterface $logger
     */
    public function __construct(array $config, Manager $manager, LoggerInterface $logger)
    {
        $this->config = $config;
        $this->manager = $manager;
        $this->logger = $logger;
    }

    /**
     * {@inheritdoc}
     */
    public function dispatch(RequestInterface $request, ResponseInterface $response = null)
    {
        if (!$request instanceof ConsoleRequest) {
            throw new \InvalidArgumentException(sprintf(
                '%s can only dispatch requests in a console environment',
                get_called_class()
            ));
        }

        pcntl_signal(SIGTERM, [$this, 'stopApplication']);
        pcntl_signal(SIGINT, [$this, 'stopApplication']);
        pcntl_signal(SIGTSTP, [$this, 'stopApplication']);
        pcntl_signal(SIGCHLD, [$this, 'serviceStopped']);

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
        } catch (\Exception $exception) {
            $this->logger->err(sprintf("Exception (%d): %s in %s on line %d",
                $exception->getCode(),
                addcslashes($exception->getMessage(), "\t\n\r\0\x0B"),
                $exception->getFile(),
                $exception->getLine()
            ));
            $this->logger->debug(sprintf("Stack Trace:\n%s", $exception->getTraceAsString()));
            $this->doExit($exception->getCode() > 0 ? $exception->getCode() : 500);
        }
    }

    public function serviceStopped()
    {
        $this->servicesRunning--;

        if ($this->servicesRunning === 0) {
            $this->logger->info("All services exited");
            $this->doExit(0);
        }
    }

    /**
     * @param int $code
     */
    protected function doExit($code)
    {
        exit($code);
    }

    /**
     * @param string $serviceName
     * @param bool $autoStartOnly
     * @return ServerServiceInterface[]
     */
    protected function getServices($serviceName = null, $autoStartOnly = false)
    {
        if ($this->reportBrokenServices($serviceName)) {
            return [];
        }

        return $serviceName
            ?
            [$serviceName => $this->manager->getService($serviceName)]
            :
            $this->manager->getServices($autoStartOnly);
    }

    /**
     * @param string $serviceName
     * @return bool
     */
    protected function reportBrokenServices($serviceName)
    {
        $result = false;
        $brokenServices = $this->manager->getBrokenServices();

        $services = $serviceName !== null ? [$serviceName] : array_keys($brokenServices);

        foreach ($services as $serviceName) {
            if ($serviceName && isset($brokenServices[$serviceName])) {
                /** @var \Exception $exception */
                $exception = $brokenServices[$serviceName];
                $this->logger->err("Service \"$serviceName\" is broken: " . $exception->getPrevious()->getMessage());
                $result = true;
            }
        }

        return $result;
    }

    /**
     * @param string $serviceName
     */
    protected function getStatusCommand($serviceName)
    {
        $services = $this->getServices($serviceName, false);

        foreach ($services as $serviceName => $service) {
            $schedulerStatus = new SchedulerStatusView($service->getScheduler(), Console::getInstance());
            $status = $schedulerStatus->getStatus();

            if ($status) {
                $this->logger->info($status);

                return;

            }

            $this->logger->err("Service \"$serviceName\" is offline or too busy to respond");
        }
    }

    /**
     * @param string $serviceName
     */
    protected function listServicesCommand($serviceName)
    {
        $services = $this->getServices($serviceName, false);

        $output = null;
        foreach ($services as $serviceName => $service) {
            $serviceConfig = $service->getConfig();
            $config = array_slice(
                explode("\n", print_r($serviceConfig, true)), 1, -1);

            $output .= PHP_EOL . 'Service configuration for "' . $serviceName . '"":' . PHP_EOL . implode(PHP_EOL, $config) . PHP_EOL;
        }

        if ($output) {
            $this->logger->info('Configuration details:' . $output);

            return;
        }

        $this->logger->err('No Server Service found');
    }

    /**
     * @param string $serviceName
     */
    protected function startServicesCommand($serviceName)
    {
        $startTime = microtime(true);

        $services = $this->getServices($serviceName, true);

        $this->services = $services;

        foreach ($services as $service) {
            $service->start();
        }

        $now = microtime(true);
        $phpTime = $now - (float) $_SERVER['REQUEST_TIME_FLOAT'];
        $managerTime = $now - $startTime;

        $this->servicesRunning = count($services);

        $engine = defined("HHVM_VERSION") ? 'HHVM' : 'PHP';
        $this->logger->info(sprintf("Started %d services in %.2f seconds ($engine running for %.2f)", $this->servicesRunning, $managerTime, $phpTime));
        if (count($services) === 0) {
            $this->logger->err('No server service started');

            return;
        }

        while (true) {
            pcntl_signal_dispatch();
            sleep(1);
        }
    }

    /**
     * @param ServerServiceInterface[] $services
     * @param bool $mustBeRunning
     * @throws \Exception
     */
    protected function stopServices($services, $mustBeRunning)
    {
        $servicesAmount = 0;
        foreach ($services as $service) {
            try {
                $service->stop();
                $servicesAmount++;
            } catch (\Exception $exception) {
                if ($mustBeRunning) {
                    throw $exception;
                }
            }
        }

        $servicesLeft = $servicesAmount;

        $signalInfo = [];

        if (function_exists('pcntl_sigtimedwait')) {
            while ($servicesLeft > 0 && pcntl_sigtimedwait([SIGCHLD], $signalInfo, 1)) {
                $servicesLeft--;
            }
        }

        $this->logger->info(sprintf("Stopped %d service(s)", $servicesAmount - $servicesLeft));

        if ($servicesLeft === 0) {
            $this->doExit(0);
        }

        $this->logger->warn(sprintf("Only %d out of %d services were stopped gracefully", $servicesAmount -  $servicesLeft, $servicesAmount));
        $this->doExit(417);
    }

    public function stopApplication()
    {
        $this->stopServices($this->services, false);
    }

    protected function stopServicesCommand($serviceName)
    {
        $services = $this->getServices($serviceName, false);
        $this->stopServices($services, false);
    }

    /**
     * @return LoggerInterface
     */
    public function getLogger()
    {
        return $this->logger;
    }

    /**
     * @param LoggerInterface $logger
     * @return $this
     */
    public function setLogger($logger)
    {
        $this->logger = $logger;
        return $this;
    }
}