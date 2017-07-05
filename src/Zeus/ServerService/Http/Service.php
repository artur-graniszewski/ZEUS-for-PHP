<?php

namespace Zeus\ServerService\Http;

use Zend\Http\Request;
use Zend\Http\Response;
use Zend\Stdlib\RequestInterface;
use Zend\Stdlib\ResponseInterface;
use Zend\Uri\Uri;
use Zeus\Kernel\ProcessManager\Process;
use Zeus\Kernel\ProcessManager\ProcessEvent;

use Zeus\ServerService\Http\Dispatcher\StaticFileDispatcher;
use Zeus\ServerService\Http\Message\Message;
use Zeus\ServerService\Http\Dispatcher\ZendFrameworkDispatcher;
use Zeus\ServerService\Http\Message\Request as HttpRequest;
use Zeus\ServerService\Shared\AbstractSocketServerService;

class Service extends AbstractSocketServerService
{
    /** @var Process */
    protected $process;

    public function start()
    {
        $this->getScheduler()->getEventManager()->getSharedManager()->attach('*', ProcessEvent::EVENT_PROCESS_INIT, function(ProcessEvent $event) {
            $this->process = $event->getTarget();
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

        $this->logger->$priority(sprintf('%s - - "%s %s HTTP/%s" %d %d "%s" "%s"',
            $httpRequest->getMetadata('remoteAddress'),
            $httpRequest->getMethod(),
            $uriString,
            $httpRequest->getVersion(),
            $httpResponse->getStatusCode(),
            $responseSize,
            $this->getHeader($httpRequest, 'Referer', '-'),
            $this->getHeader($httpRequest, 'User-Agent', '-')
            )
        );
    }

    /**
     * @param RequestInterface|Request $request
     * @param $headerName
     * @param null|string $defaultValue
     * @return null|string
     */
    protected function getHeader(RequestInterface $request, $headerName, $defaultValue = null)
    {
        if ($request instanceof HttpRequest) {
            $value = $request->getHeaderOverview($headerName, false);
            return $value ? $value : $defaultValue;
        }

        return $request->getHeaders()->has($headerName) ? $request->getHeaders()->get($headerName)->getFieldValue() : $defaultValue;
    }
}