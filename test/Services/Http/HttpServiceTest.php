<?php

namespace ZeusTest\Services\Http;

use \PHPUnit\Framework\TestCase;
use Zend\Http\Request;
use Zend\Http\Response;
use Zend\Log\Logger;
use Zend\Log\Writer\Mock;
use Zeus\Kernel\Scheduler\WorkerEvent;
use Zeus\ServerService\Http\Service;
use ZeusTest\Helpers\ZeusFactories;

class HttpServiceTest extends \PHPUnit\Framework\TestCase
{
    use ZeusFactories;

    /**
     * @return Service
     */
    protected function getService()
    {
        $sm = $this->getServiceManager();
        $scheduler = $this->getScheduler();
        $logger = $scheduler->getLogger();
        $events = $scheduler->getEventManager();
        $events->getSharedManager()->attach(
            '*',
            WorkerEvent::EVENT_CREATE, function (WorkerEvent $event) use ($events) {
                $event->setParam("uid", 123456789);
            }, 100
        );

        $service = $sm->build(Service::class,
            [
                'scheduler_adapter' => $scheduler,
                'logger_adapter' => $logger,
                'config' =>
                [
                    'service_settings' => [
                    'listen_port' => 0,
                    'listen_address' => '0.0.0.0',
                    'keep_alive_enabled' => true,
                    'keep_alive_timeout' => 5,
                    'max_keep_alive_requests_limit' => 100,
                    'blocked_file_types' => [
                        'php',
                        'phtml'
                    ]
                ]
            ]
        ]);

        return $service;
    }

    public function testServiceCreation()
    {
        $service = $this->getService();
        $this->assertFalse($service->getScheduler()->isTerminating());
        $service->start();
        $service->stop();
        $this->assertTrue($service->getScheduler()->isTerminating());
    }

    public function testLogger()
    {
        $request = Request::fromString("GET /test?foo=bar HTTP/1.1\r\nHost: localhost\r\nUser-Agent: PHPUNIT\r\nReferer: http://foo.bar\r\n\r\n");
        $response = new Response();
        $response->setVersion('1.1');
        $response->setMetadata('dataSentInBytes', 1234);
        $response->setStatusCode(201);
        $request->setMetadata('remoteAddress', '192.168.1.2');

        $service = $this->getService();

        $mockWriter = new Mock();
        $nullLogger = new Logger();
        $nullLogger->addWriter($mockWriter);
        $service->setLogger($nullLogger);

        $service->logRequest($request, $response);
        $this->assertEquals('192.168.1.2 - - "GET /test?foo=bar HTTP/1.1" 201 1234 "http://foo.bar" "PHPUNIT"', $mockWriter->events[0]['message']);
    }
}