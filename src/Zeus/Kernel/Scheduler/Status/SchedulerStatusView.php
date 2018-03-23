<?php

namespace Zeus\Kernel\Scheduler\Status;

use Zend\Console\ColorInterface;
use Zeus\Kernel\Scheduler\Helper\AddUnitsToNumbers;
use Zeus\ServerService\Plugin\SchedulerStatus;
use Zeus\Kernel\Scheduler;
use Zend\Console\Adapter\AdapterInterface;
use Zeus\ServerService\ServerServiceInterface;

/**
 * Class SchedulerStatusView
 * @package Zeus\Kernel\Scheduler\Status
 * @internal
 */
class SchedulerStatusView
{
    use AddUnitsToNumbers;

    /**
     * @var Scheduler
     */
    protected $scheduler;

    /** @var AdapterInterface */
    protected $console;

    protected $statusToCharMapping = [
        WorkerState::WAITING => '_',
        WorkerState::RUNNING => 'R',
        WorkerState::EXITING => 'E',
        WorkerState::TERMINATED => 'T',
    ];

    /**
     * SchedulerStatusView constructor.
     * @param AdapterInterface $console
     */
    public function __construct(AdapterInterface $console)
    {
        $this->console = $console;
    }

    /**
     * @return Scheduler
     */
    public function getScheduler()
    {
        return $this->scheduler;
    }

    public function setScheduler(Scheduler $scheduler)
    {
        $this->scheduler = $scheduler;
    }

    public function getStatus(ServerServiceInterface $service) : string
    {
        $this->scheduler = $service->getScheduler();

        $console = $this->console;
        $output = $console->colorize("Service Status: " . PHP_EOL . PHP_EOL, ColorInterface::GREEN);

        $payload = SchedulerStatus::getStatus($this->scheduler);

        if (!$payload) {
            return '';
        }

        $output .= $console->colorize('Service: ' . $this->getScheduler()->getConfig()->getServiceName() . PHP_EOL . PHP_EOL, ColorInterface::LIGHT_BLUE);
        $workerList = $payload['process_status'];
        $schedulerStatus = $payload['scheduler_status'];

        $idleChildren = 0;
        $busyChildren = 0;
        $allChildren = 0;
        $currentCpuUsage = 0;
        $currentUserCpuUsage = 0;
        $currentSysCpuUsage = 0;

        $processStatusChars = [];

        foreach ($workerList as $workerStatus) {
            ++$allChildren;
            $workerStatus = WorkerState::fromArray($workerStatus);
            $processStatusCode = $workerStatus->getCode();

            $currentCpuUsage += $workerStatus->getCpuUsage();
            $currentUserCpuUsage += $workerStatus->getCurrentUserCpuTime();
            $currentSysCpuUsage += $workerStatus->getCurrentSystemCpuTime();

            $processStatusChars[$workerStatus->getProcessId()] =
                isset($this->statusToCharMapping[$processStatusCode]) ?
                    $this->statusToCharMapping[$processStatusCode] : '?';

            $processStatusCode === WorkerState::WAITING ? ++$idleChildren : ++$busyChildren;
        }

        $statusTab = str_pad(implode($processStatusChars), $this->getScheduler()->getConfig()->getMaxProcesses(), '.', STR_PAD_RIGHT);

        $statusLines = str_split($statusTab, 64);
        $uptime = floor(microtime(true) - $schedulerStatus['start_timestamp']);

        $output .= sprintf("Current time: %s" . PHP_EOL, $this->getDate(time()));
        $output .= sprintf("Restart time: %s" . PHP_EOL, $this->getDate($schedulerStatus['start_timestamp']));
        $output .= sprintf("Service uptime: %s" . PHP_EOL, $this->getDateDiff($schedulerStatus['start_timestamp'], microtime(true)));
        $output .= sprintf("Total tasks finished: %d, ",
            $schedulerStatus['requests_finished']
        );

        $output .= sprintf("%s requests/sec" . PHP_EOL,
            $this->addUnitsToNumber($schedulerStatus['requests_finished'] / max($uptime, 0.01))
        );

        $output .= sprintf("%d tasks currently being processed, %d idle processes" . PHP_EOL . PHP_EOL, $busyChildren, $idleChildren);

        foreach ($statusLines as $line) {
            $output .= $line . PHP_EOL;
        }

        $output .= PHP_EOL;

        $output .= "Scoreboard Key:" . PHP_EOL . '"_" Waiting for task, "R" Currently running, "E" Exiting,' . PHP_EOL;
        $output .= '"T" Terminated, "." Open slot with no current process' . PHP_EOL . PHP_EOL;

        $output .= $this->listProcessDetails($workerList, $processStatusChars, $schedulerStatus);

        return $output;
    }

    /**
     * @param mixed[] $workersList
     * @param string[] $processStatusChars
     * @param mixed[] $schedulerStatus
     * @return string
     */
    protected function listProcessDetails($workersList, $processStatusChars, $schedulerStatus) : string
    {
        $output = '';
        $console = $this->console;

        $lastElement = end($workersList);
        $lastElementKey = key($workersList);

        $output .= $console->colorize(sprintf('Service %s' . PHP_EOL, $lastElement['service_name']), ColorInterface::LIGHT_YELLOW);
        $output .= sprintf(' └─┬ Scheduler %s, CPU: %d%%' . PHP_EOL, $schedulerStatus['uid'], $schedulerStatus['cpu_usage']);

        foreach ($workersList as $key => $workerStatus) {
            $workerStatus = WorkerState::fromArray($workerStatus);
            $color = $workerStatus->isIdle() ? ColorInterface::WHITE : ColorInterface::LIGHT_WHITE;

            $connector = ($key === $lastElementKey ? '└' : '├');
            /** @var WorkerState $workerStatus */
            $output .= $console->colorize(
                sprintf("   %s── Process %s [%s] CPU: %d%%, RPS: %s, REQ: %s%s" . PHP_EOL,
                    $connector,
                    $workerStatus->getProcessId(),
                    $processStatusChars[$workerStatus->getProcessId()],
                    $workerStatus->getCpuUsage(),
                    $this->addUnitsToNumber($workerStatus->getNumberOfTasksPerSecond()),
                    $this->addUnitsToNumber($workerStatus->getNumberOfFinishedTasks()),
                    $workerStatus->getStatusDescription() ? ': ' . $workerStatus->getStatusDescription() : ''
                ),
                $color
            );
        }

        return $output;
    }

    /**
     * @param int $time
     * @return string
     */
    protected function getDate($time)
    {
        $timeZone = date_default_timezone_get();
        $timeZone = new \DateTimeZone(!empty($timeZone) ? $timeZone : 'GMT');
        $dateTime = new \DateTime();
        $dateTime->setTimeZone($timeZone);

        return sprintf("%s %s", date('l, d-M-Y H:i:s', $time), $dateTime->format('T'));
    }

    /**
     * @param int $startTime
     * @param int $endTime
     * @return string
     */
    protected function getDateDiff($startTime, $endTime)
    {
        $startTime = (int) $startTime;
        $endTime = (int) $endTime;

        $dateTime1 = new \DateTime("@$startTime");
        $dateTime2 = new \DateTime("@$endTime");

        $interval = date_diff($dateTime1, $dateTime2);

        $date = [
            'years' => $this->formatDateSegment($interval->format('%y'), 'year', 'years'),
            'months' => $this->formatDateSegment($interval->format('%m'), 'month', 'months'),
            'days' => $this->formatDateSegment($interval->format('%d'), 'day', 'days'),
            'hours' => $this->formatDateSegment($interval->format('%h'), 'hour', 'hours'),
            'minutes' => $this->formatDateSegment($interval->format('%i'), 'minute', 'minutes'),
            'seconds' => $this->formatDateSegment($interval->format('%s'), 'second', 'seconds'),
        ];

        $date = array_filter($date);

        return implode(" ", $date);
    }

    /**
     * @param int $value
     * @param string $singularForm
     * @param string $pluralForm
     * @return string
     */
    private function formatDateSegment($value, $singularForm, $pluralForm)
    {
        return $value = $value ? ($value > 1 ? "$value $pluralForm" : "$value $singularForm") : "";
    }
}