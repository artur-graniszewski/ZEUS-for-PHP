<?php

namespace Zeus\ServerService\Http;

use Zend\Http\Request;
use Zend\Log\LoggerInterface;
use Zend\Stdlib\RequestInterface;
use Zend\Stdlib\ResponseInterface;
use Zend\Uri\Uri;
use Zeus\Kernel\SchedulerInterface;
use Zeus\ServerService\Http\Dispatcher\StaticFileDispatcher;
use Zeus\ServerService\Http\Message\Message;
use Zeus\ServerService\Http\Dispatcher\ZendFrameworkDispatcher;
use Zeus\ServerService\Http\Message\Request as HttpRequest;
use Zeus\ServerService\Shared\AbstractSocketServerService;

use function sprintf;

class Service extends AbstractSocketServerService
{
    public function __construct(array $config = [], SchedulerInterface $scheduler, LoggerInterface $logger)
    {
        parent::__construct($config, $scheduler, $logger);

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
    }

    public function start()
    {
        $config = $this->getConfig();
        $config['logger'] = get_class();
        $this->setConfig($config);

        parent::start();
    }

    public function logRequest(RequestInterface $httpRequest, ResponseInterface $httpResponse)
    {
        $priority = $httpResponse->getStatusCode() >= 400 ? 'err' : 'info';

        $responseSize = $httpResponse->getMetadata('dataSentInBytes');

        $uri = $httpRequest->getUri();
        $uriString = Uri::encodePath($uri->getPath() ? $uri->getPath() : '') . ($uri->getQuery() ? '?' . Uri::encodeQueryFragment($uri->getQuery()) : '');
        //$defaultPorts = ['http' => 80, 'https' => 443];
        //$port = isset($defaultPorts[$uri->getScheme()]) && $defaultPorts[$uri->getScheme()] == $uri->getPort() ? '' : ':' . $uri->getPort();
        //$hostString = sprintf("%s%s", $uri->getHost(), $port);

        $this->getLogger()->$priority(sprintf('%s - - "%s %s HTTP/%s" %d %d "%s" "%s"',
            $httpRequest->getMetadata('remoteAddress'),
            $httpRequest->getMethod(),
            $uriString,
            $httpRequest->getVersion(),
            $httpResponse->getStatusCode(),
            $responseSize,
            $this->getHeader($httpRequest, 'Referer'),
            $this->getHeader($httpRequest, 'User-Agent')
            )
            , ['extra' => ['logger' => 'aaaa']]
            , ['logger' => 'aaaa']
        );
    }

    /**
     * @param RequestInterface|Request $request
     * @param string $headerName
     * @param null|string $defaultValue
     * @return string
     */
    private function getHeader(RequestInterface $request, string $headerName, string $defaultValue = '-') : string
    {
        if ($request instanceof HttpRequest) {
            $value = $request->getHeaderOverview($headerName, false);
            return $value ? $value : $defaultValue;
        }

        $headers = $request->getHeaders();
        return $headers->has($headerName) ? $headers->get($headerName)->getFieldValue() : $defaultValue;
    }
}