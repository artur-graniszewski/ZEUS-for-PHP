<?php

namespace Zeus\ServerService\Http;

use Zend\Http\Request;
use Zend\Http\Response;
use Zend\Stdlib\RequestInterface;
use Zend\Stdlib\ResponseInterface;
use Zend\Uri\Uri;
use Zeus\Kernel\ProcessManager\Process;
use Zeus\Kernel\ProcessManager\SchedulerEvent;
use Zeus\ServerService\Http\Dispatcher\StaticFileDispatcher;
use Zeus\ServerService\Http\Message\Message;
use Zeus\ServerService\Http\Dispatcher\ZendFrameworkDispatcher;
use Zeus\ServerService\Shared\AbstractReactServerService;
use Zeus\Kernel\ProcessManager\Scheduler;

class Service extends AbstractReactServerService
{
    /** @var Process */
    protected $process;

    public function start()
    {
        $this->getScheduler()->getEventManager()->attach(SchedulerEvent::EVENT_PROCESS_INIT, function(SchedulerEvent $event) {
            $this->process = $event->getProcess();
        });

        $this->config['logger'] = get_class();

        $dispatcherConfig = $this->getConfig();
        $dispatcherConfig['service'] = $this;
        $dispatchers =
            new StaticFileDispatcher(
                $dispatcherConfig,
                new ZendFrameworkDispatcher(
                    $dispatcherConfig
                )
            );

        $messageComponent =
            new Message(
                [$dispatchers, 'dispatch'],
                null,
                [$this, 'logRequest']
            );

        $config = new Config($this->getConfig());
        $this->getServer($messageComponent, $config);
        parent::start();

        return $this;
    }

    /**
     * @return Process
     */
    public function getProcess()
    {
        return $this->process;
    }

    /**
     * @param RequestInterface|Request $httpRequest
     * @param ResponseInterface|Response $httpResponse
     */
    public function logRequest(RequestInterface $httpRequest, ResponseInterface $httpResponse)
    {
        $priority = $httpResponse->getStatusCode() >= 400 ? 'err' : 'info';

        $responseSize = $httpResponse->getMetadata('dataSentInBytes');

        $uri = $httpRequest->getUri();
        $uriString = Uri::encodePath($uri->getPath() ? $uri->getPath() : '') . ($uri->getQuery() ? '?' . Uri::encodeQueryFragment($uri->getQuery()) : '');
        //$defaultPorts = ['http' => 80, 'https' => 443];
        //$port = isset($defaultPorts[$uri->getScheme()]) && $defaultPorts[$uri->getScheme()] == $uri->getPort() ? '' : ':' . $uri->getPort();
        //$hostString = sprintf("%s%s", $uri->getHost(), $port);
        $referrer = $httpRequest->getHeaders()->has('Referer') ? $httpRequest->getHeaders()->get('Referer')->getFieldValue() : '-';

        $this->logger->$priority(sprintf('%s - - "%s %s HTTP/%s" %d %d "%s" "%s"',
            $httpRequest->getMetadata('remoteAddress'),
            $httpRequest->getMethod(),
            $uriString,
            $httpRequest->getVersion(),
            $httpResponse->getStatusCode(),
            $responseSize,
            $referrer, //$hostString,
            $httpRequest->getHeaders()->has('User-Agent') ? $httpRequest->getHeaders()->get('User-Agent')->getFieldValue() : '-'
            )
        );
    }
}