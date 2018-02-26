<?php

namespace Zeus\Controller;

use Throwable;
use Zend\Console\Console;
use Zend\Stdlib\RequestInterface;
use Zend\Stdlib\ResponseInterface;
use Zeus\Kernel\Scheduler\Status\SchedulerStatusView;
use Zeus\Kernel\System\Runtime;
use Zeus\ServerService\ServerServiceInterface;
use Zeus\ServerService\Shared\Logger\ExceptionLoggerTrait;

class MainController extends AbstractController
{
    use ExceptionLoggerTrait;

    /** @var ServerServiceInterface[] */
    private $services = [];

    private function catchSignals()
    {
        $oldShutdownHook = Runtime::getShutdownHook();
        $newShutdownHook = function(int $signalNumber, bool $isSignal) use ($oldShutdownHook) {
            if ($oldShutdownHook) {
                return $oldShutdownHook($signalNumber, $isSignal);
            }

            if (!$isSignal) {
                return false;
            }

            $this->stopApplication();
            return false;
        };
        Runtime::setShutdownHook($newShutdownHook);
    }

    /**
     * {@inheritdoc}
     */
    public function dispatch(RequestInterface $request, ResponseInterface $response = null)
    {
        $this->checkIfConsole($request);

        // @todo: remove pcnt_signal dependency
        $this->catchSignals();

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
            $this->logException($exception, $this->getLogger());
            Runtime::exit($exception->getCode() > 0 ? $exception->getCode() : 500);
        }
    }

    /**
     * @param string $serviceName
     * @param bool $autoStartOnly
     * @return string[]
     */
    private function getServices(string $serviceName = null, bool $autoStartOnly) : array
    {
        if ($this->reportBrokenServices($serviceName)) {
            return [];
        }

        return $serviceName
            ?
            [$serviceName]
            :
            $this->getServiceManager()->getServiceList($autoStartOnly);
    }

    /**
     * @param string $serviceName
     * @return bool
     */
    private function reportBrokenServices($serviceName) : bool
    {
        $result = false;
        $brokenServices = $this->getServiceManager()->getBrokenServices();

        $services = $serviceName !== null ? [$serviceName] : array_keys($brokenServices);

        foreach ($services as $serviceName) {
            if ($serviceName && isset($brokenServices[$serviceName])) {
                /** @var Throwable $exception */
                $exception = $brokenServices[$serviceName];
                $exception = $exception->getPrevious() ? $exception->getPrevious() : $exception;
                $this->getLogger()->err("Service \"$serviceName\" is broken");
                $this->logException($exception, $this->getLogger());
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
            $status = $this->getServiceManager()->getServiceStatus($serviceName, new SchedulerStatusView(Console::getInstance()));

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
            $serviceConfig = $this->getServiceManager()->getServiceConfig($serviceName);
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
        $this->getServiceManager()->startServices($services);
    }

    /**
     * @param ServerServiceInterface[] $services
     * @param bool $mustBeRunning
     * @throws \Exception
     */
    private function stopServices($services, bool $mustBeRunning)
    {
        $servicesLeft = $this->getServiceManager()->stopServices($services, $mustBeRunning);

        Runtime::exit($servicesLeft === 0 ? 0 : 417);
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
}