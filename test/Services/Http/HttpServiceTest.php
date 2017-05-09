<?php

namespace ZeusTest\Services\Http;

use PHPUnit_Framework_TestCase;
use Zend\Http\Request;
use Zend\Http\Response;
use Zend\Log\Logger;
use Zend\Log\Writer\Mock;
use Zeus\Kernel\ProcessManager\MultiProcessingModule\PosixProcess;
use Zeus\Kernel\ProcessManager\SchedulerEvent;
use Zeus\ServerService\Http\Service;
use ZeusTest\Helpers\ZeusFactories;

class HttpServiceTest extends PHPUnit_Framework_TestCase
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
        $events->attach(
            SchedulerEvent::EVENT_PROCESS_CREATE, function (SchedulerEvent $event) use ($events) {
            $event->setName(SchedulerEvent::EVENT_PROCESS_CREATED);
            $event->setParam("uid", 123456789);
            $events->triggerEvent($event);
        }
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
        $service->start();
        $service->stop();
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