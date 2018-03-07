<?php

namespace Zeus\ServerService\Shared\Networking\Service;

use InvalidArgumentException;
use Zeus\Exception\NoSuchElementException;

use function current;

class BackendPool
{
    /** @var WorkerIPC[] */
    private $workers = [];

    public function addWorker(WorkerIPC $worker)
    {
        $uid = $worker->getUid();
        if (isset($this->workers[$uid])) {
            throw new InvalidArgumentException("Worker $uid already added");
        }

        $this->workers[$worker->getUid()] = $worker;
    }

    public function removeWorker(WorkerIPC $worker) {
        $uid = $worker->getUid();
        if (!isset($this->workers[$uid])) {
            throw new NoSuchElementException("Worker $uid not found");
        }

        unset ($this->workers[$worker->getUid()]);
    }

    public function getAnyWorker()
    {
        if (!$this->workers) {
            throw new NoSuchElementException("No backend workers available");
        }

        return current($this->workers);
    }
}