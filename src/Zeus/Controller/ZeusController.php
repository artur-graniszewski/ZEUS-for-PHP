<?php

namespace Zeus\Controller;

use Zend\Log\LoggerInterface;
use Zend\Mvc\Controller\AbstractActionController;
use Zend\Stdlib\RequestInterface;
use Zend\Stdlib\ResponseInterface;
use Zend\Log\Writer;
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
        date_default_timezone_set("UTC");
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

        /** @var \Zend\Stdlib\Parameters $params */
        $params = $request->getParams();

        $action = $params->get(1);
        $serviceName = $params->get(2);

        try {
            switch ($action) {
                case 'start':
                    $this->startApplication($serviceName);
                    break;

                case 'list':
                    $this->listServices($serviceName);
                    break;

                case 'status':
                    $this->getStatus($serviceName);
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
        }
    }

    /**
     * @param string $serviceName
     * @param bool $autoStartOnly
     * @return ServerServiceInterface[]
     */
    protected function getServices($serviceName = null, $autoStartOnly = false)
    {
        return $serviceName
            ?
            [$serviceName => $this->manager->getService($serviceName)]
            :
            $this->manager->getServices($autoStartOnly);
    }

    /**
     * @param string $serviceName
     */
    protected function getStatus($serviceName)
    {
        $services = $this->getServices($serviceName, false);

        foreach ($services as $serviceName => $service) {
            $schedulerStatus = new SchedulerStatusView($service->getScheduler());
            $status = $schedulerStatus->getStatus();

            if (!$status) {
                $this->logger->err("Service \"$serviceName\" is offline or too busy to respond");
            } else {
                $this->logger->info($status);
            }
        }
    }

    /**
     * @param string $serviceName
     */
    protected function listServices($serviceName)
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
    protected function startApplication($serviceName)
    {
        $startTime = microtime(true);

        $services = $this->getServices($serviceName, true);

        $this->services = $services;

        foreach ($services as $service) {
            $service->start();
        }

        $now = microtime(true);
        $phpTime = $now - $_SERVER['REQUEST_TIME_FLOAT'];
        $managerTime = $now - $startTime;

        $this->logger->info(sprintf("Started %d services in %.2f seconds (PHP running for %.2f)", count($services), $managerTime, $phpTime));
        if (count($services) === 0) {
            $this->logger->err('No Server Service found');

            return;
        }

        while (true) {
            pcntl_signal_dispatch();
            sleep(1);
        }
    }

    /**
     *
     */
    protected function stopApplication()
    {
        foreach ($this->services as $service) {
            $service->stop();
        }

        $servicesAmount = count($this->services);
        $servicesLeft = $servicesAmount;

        $signalInfo = [];

        while ($servicesLeft > 0 && pcntl_sigtimedwait([SIGCHLD], $signalInfo, 1)) {
            $servicesLeft--;
        }

        if ($servicesLeft === 0) {
            $this->logger->info(sprintf("Stopped %d service(s)", $servicesAmount));
            exit(0);
        }

        $this->logger->warn(sprintf("Only %d out of %d services were stopped gracefully", $servicesAmount -  $servicesLeft, $servicesAmount));
        exit(1);
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
     * @return ZeusController
     */
    public function setLogger($logger)
    {
        $this->logger = $logger;
        return $this;
    }
}