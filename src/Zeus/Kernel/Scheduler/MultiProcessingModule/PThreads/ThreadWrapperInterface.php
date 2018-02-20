<?php

namespace Zeus\Kernel\Scheduler\MultiProcessingModule\PThreads;

/**
 * @internal
 */
interface ThreadWrapperInterface
{
    public function setServerVariables(array $variables);

    public function getServerVariables() : array;

    public function getApplicationArguments() : array;

    public function setApplicationArguments(array $args);

    public function setWorkerId(int $id);

    public function getWorkerId() : int;

    public function setIpcAddress(string $address);

    public function getIpcAddress() : string;

    public function isStarted();
    public function isJoined();
    public function isTerminated();

    public static function getCurrentThreadId();
    public function getThreadId();

    public function start();
    public function join();
}