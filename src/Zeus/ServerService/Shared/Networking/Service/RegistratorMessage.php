<?php

namespace Zeus\ServerService\Shared\Networking\Service;

use InvalidArgumentException;
use RuntimeException;

class RegistratorMessage
{
    const REGISTER_ENDPOINT = 'ready';
    const GET_ENDPOINT = 'lock';
    const SET_ENDPOINT_BUSY_STATUS = 'busy';
    const SET_ENDPOINT_GONE_STATUS = 'gone';
    const SET_ENDPOINT_FAILED_STATUS = 'failed';
    const CONNECT_TO_ENDPOINT = 'connect';
    const RETRY_LATER = 'retry';
    const IGNORE_MESSAGE = 'noop';

    private $allowedCommands = [
        self::REGISTER_ENDPOINT,
        self::GET_ENDPOINT,
        self::SET_ENDPOINT_GONE_STATUS,
        self::SET_ENDPOINT_BUSY_STATUS,
        self::SET_ENDPOINT_FAILED_STATUS,
        self::CONNECT_TO_ENDPOINT,
        self::RETRY_LATER,
        self::IGNORE_MESSAGE,
    ];

    /** @var WorkerIPC */
    private $worker;

    /** @var string */
    private $command;

    public function __construct(string $command, WorkerIPC $worker = null)
    {
        $this->setCommand($command);
        if ($worker) {
            $this->setWorker($worker);
        }
    }

    public function getWorker() : WorkerIPC
    {
        if (!$this->worker) {
            throw new RuntimeException("Worker not set");
        }

        return $this->worker;
    }

    public function setWorker(WorkerIPC $worker)
    {
        $this->worker = $worker;
    }

    public function getCommand() : string
    {
        if (!$this->command) {
            throw new RuntimeException("Command not set");
        }
        return $this->command;
    }

    public function setCommand(string $command)
    {
        if (!in_array($command, $this->allowedCommands)) {
            throw new InvalidArgumentException("Unknown command: " . json_encode($command));
        }
        $this->command = $command;
    }


}