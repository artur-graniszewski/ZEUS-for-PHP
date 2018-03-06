<?php

namespace ZeusTest\Services\Shared;

use PHPUnit\Framework\TestCase;
use Zeus\Exception\NoSuchElementException;
use Zeus\ServerService\Shared\Networking\Service\BackendPool;
use Zeus\ServerService\Shared\Networking\Service\WorkerIPC;

class BackendPoolTest extends TestCase
{
    public function testAddNewWorker()
    {
        $worker = new WorkerIPC(1, "aaa");
        $pool = new BackendPool();
        $pool->addWorker($worker);
        $this->assertEquals($worker, $pool->getAnyWorker());
    }

    /**
     * @expectedException \Zeus\Exception\NoSuchElementException
     */
    public function testGetWorkerFromEmptyPool()
    {
        $pool = new BackendPool();
        $pool->getAnyWorker();
    }

    public function testRemoveWorker()
    {
        $ex = null;
        $worker = new WorkerIPC(1, "aaa");
        $pool = new BackendPool();
        $pool->addWorker($worker);
        $this->assertEquals($worker, $pool->getAnyWorker());
        $pool->removeWorker($worker);
        try {
            $pool->getAnyWorker();
        } catch (NoSuchElementException $ex) {

        }

        $this->assertInstanceOf(NoSuchElementException::class, $ex);
    }
}