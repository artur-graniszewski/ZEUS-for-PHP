<?php

namespace Zeus\Kernel\Scheduler\MultiProcessingModule;

use Zeus\Kernel\Scheduler\Exception\SchedulerException;
use Zeus\Kernel\Scheduler\MultiProcessingModule\ProcessOpen\ProcessOpenBridge;
use Zeus\Kernel\Scheduler\MultiProcessingModule\ProcessOpen\ProcessOpenBridgeInterface;
use Zeus\Kernel\Scheduler\SchedulerEvent;
use Zeus\Kernel\Scheduler\WorkerEvent;

use function escapeshellarg;
use function basename;
use function fclose;

/**
 * Class DirectProcessOpen
 * @package Zeus\Kernel\Scheduler\MultiProcessingModule
 * @codeCoverageIgnore
 */
final class DirectProcessOpen extends AbstractProcessModule implements SeparateAddressSpaceInterface
{
    private $stdout;

    private $stderr;

    /** @var ProcessOpenBridgeInterface */
    private static $procOpenBridge;

    protected static function getProcessBridge() : ProcessOpenBridgeInterface
    {
        if (!isset(static::$procOpenBridge)) {
            static::$procOpenBridge = new ProcessOpenBridge();
        }

        return static::$procOpenBridge;
    }


    public static function setProcessBridge(ProcessOpenBridgeInterface $bridge)
    {
        static::$procOpenBridge = $bridge;
    }

    public static function isSupported(& $errorMessage = '') : bool
    {
        $isSupported = static::getProcessBridge()->isSupported();

        if (!$isSupported) {
            $className = basename(str_replace('\\', '/', static::class));

            $errorMessage = sprintf("proc_open() and proc_get_status() are required by %s but disabled in PHP",
                $className);
        }

        return $isSupported;
    }

    /**
     * ProcessOpen constructor.
     */
    public function __construct()
    {
        $this->stdout = static::getProcessBridge()->getStdOut();
        $this->stderr = static::getProcessBridge()->getStdErr();
    }

    public function __destruct()
    {
        @fclose($this->stdout);
        @fclose($this->stderr);
    }

    public function onWorkerTerminated(WorkerEvent $event)
    {
        $this->cleanProcessPipes($event->getWorker()->getUid());
    }

    public function onWorkersCheck(SchedulerEvent $event)
    {
        parent::onWorkersCheck($event);

        foreach ($this->workers as $pid => $worker) {
            $status = static::getProcessBridge()->getProcStatus($worker['resource']);

            if (!$status['running']) {
                $this->cleanProcessPipes($pid);
                $this->getWrapper()->raiseWorkerExitedEvent($pid, $pid, 1);
            }
        }
    }

    private function cleanProcessPipes($uid)
    {
        if (!isset($this->workers[$uid])) {
            return;
        }

        @fclose($this->workers[$uid]['resource']);
        unset ($this->workers[$uid]);
    }

    protected function createProcess(WorkerEvent $event) : int
    {
        $descriptors = [
            0 => ['pipe', 'r'], // stdin
            1 => ['file', $this->stdout, 'a'], // stdout
            2 => ['file', $this->stderr, 'a'], // stderr
        ];

        $pipes = [];

        $phpExecutable = isset($_SERVER['_']) ? $_SERVER['_'] : PHP_BINARY;

        $applicationPath = $_SERVER['PHP_SELF'];

        $type = $event->getParam('server') ? 'scheduler' : 'worker';
        $serviceName = escapeshellarg($event->getTarget()->getConfig()->getServiceName());
        $startParams = escapeshellarg(json_encode($event->getParams()));

        $command = sprintf("exec %s %s zeus %s %s %s", $phpExecutable, $applicationPath, $type, $serviceName, $startParams);
        $process = static::getProcessBridge()->procOpen($command, $descriptors, $pipes, getcwd(), $_ENV, []);
        if ($process === false) {
            throw new SchedulerException("Could not create a descendant process", SchedulerException::WORKER_NOT_STARTED);
        }

        $status = static::getProcessBridge()->getProcStatus($process);
        $pid = $status['pid'];

        $this->workers[$pid] = [
            'resource' => $process,
        ];

        return $pid;
    }

    public function onKernelStart(SchedulerEvent $event)
    {
        // TODO: Implement onKernelStart() method.
    }

    public function onKernelLoop(SchedulerEvent $event)
    {
        // TODO: Implement onKernelLoop() method.
    }

    public function onSchedulerStop(SchedulerEvent $event)
    {
        // TODO: Implement onSchedulerStop() method.
    }

    public function onWorkerExit(WorkerEvent $event)
    {
        // TODO: Implement onWorkerExit() method.
    }

    public function onSchedulerInit(SchedulerEvent $event)
    {
        // TODO: Implement onSchedulerInit() method.
    }

    public function onSchedulerLoop(SchedulerEvent $event)
    {
        // TODO: Implement onSchedulerLoop() method.
    }

    public function onWorkerLoop(WorkerEvent $event)
    {
        // TODO: Implement onWorkerLoop() method.
    }

    public function onKernelStop(SchedulerEvent $event)
    {
        // TODO: Implement onKernelStop() method.
    }
}