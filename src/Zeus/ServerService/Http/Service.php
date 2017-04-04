<?php

namespace Zeus\ServerService\Http;

use React\EventLoop\StreamSelectLoop;
use Zend\Http\Request;
use Zend\Http\Response;
use Zend\Stdlib\RequestInterface;
use Zend\Stdlib\ResponseInterface;
use Zend\Uri\Uri;
use Zeus\Kernel\ProcessManager\Process;
use Zeus\Kernel\ProcessManager\SchedulerEvent;
use Zeus\ServerService\Http\Dispatcher\DispatcherWrapper;
use Zeus\ServerService\Http\Dispatcher\StaticFileDispatcher;
use Zeus\ServerService\Http\Message\Message;
use Zeus\ServerService\Http\Dispatcher\ZendFrameworkDispatcher;
use Zeus\ServerService\Shared\AbstractServerService;
use Zeus\ServerService\Shared\React\ReactEventSubscriber;
use Zeus\ServerService\Shared\React\ReactIoServer;
use Zeus\ServerService\Shared\React\ReactServer;
use Zeus\Kernel\ProcessManager\Scheduler;

class Service extends AbstractServerService
{
    /** @var Process */
    protected $process;

    public function start()
    {
        $this->getScheduler()->getEventManager()->attach(SchedulerEvent::EVENT_PROCESS_INIT, function(SchedulerEvent $event) {
            $this->process = $event->getProcess();
        });
        $messageComponent = Message::class;
        $this->config['logger'] = get_class();

        $this->createReactLoop($messageComponent);
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
     * @param object $messageComponent
     * @return $this
     * @throws \React\Socket\ConnectionException
     */
    protected function createReactLoop($messageComponent)
    {
        $httpConfig = new Config($this->getConfig());

        $this->logger->info(sprintf('Launching HTTP server on %s%s', $httpConfig->getListenAddress(), $httpConfig->getListenPort() ? ':' . $httpConfig->getListenPort(): ''));
        $loop = new StreamSelectLoop();
        $reactServer = new ReactServer($loop);
        $reactServer->listen($httpConfig->getListenPort(), $httpConfig->getListenAddress());
        $loop->removeStream($reactServer->master);

        $dispatcherConfig = $this->getConfig();
        $dispatcherConfig['service'] = $this;
        $dispatchers =
            new DispatcherWrapper(
                $dispatcherConfig,
                new StaticFileDispatcher(
                    $dispatcherConfig,
                    new ZendFrameworkDispatcher(
                        $dispatcherConfig
                    )
                )
            );

        $messageComponent =
            new $messageComponent(
                [$dispatchers, 'dispatch'],
                null,
                [$this, 'logRequest']
            );

        $server = new ReactIoServer($messageComponent, $reactServer, $loop);
        $reactSubscriber = new ReactEventSubscriber($loop, $server);
        $reactSubscriber->attach($this->scheduler->getEventManager());

        return $this;
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