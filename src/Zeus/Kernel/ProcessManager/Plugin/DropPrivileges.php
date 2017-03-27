<?php

namespace Zeus\Kernel\ProcessManager\Plugin;

use Zend\EventManager\EventManagerInterface;
use Zend\EventManager\ListenerAggregateInterface;
use Zeus\Kernel\ProcessManager\SchedulerEvent;

class DropPrivileges implements ListenerAggregateInterface
{
    /** @var mixed[] */
    protected $eventHandles = [];

    /** @var mixed[] */
    protected $options;

    /** @var int */
    protected $uid;

    /** @var int */
    protected $gid;

    /**
     * EffectiveUser constructor.
     * @param mixed[] $options
     */
    public function __construct(array $options)
    {
        $this->options = $options;

        if (!isset($options['user']) || !isset($options['group'])) {
            throw new \RuntimeException("Both user name and group name must be specified");
        }
        $user = posix_getpwnam($options['user']);

        if ($user === false) {
            throw new \RuntimeException("Invalid user name: " . $options['user']);
        }
        $this->uid = $user["uid"];

        $group = posix_getgrnam($options['group']);

        if ($group === false) {
            throw new \RuntimeException("Invalid group name: " . $options['user']);
        }
        $this->gid = $group["gid"];

        $gid = posix_getegid();
        $uid = posix_geteuid();

        $this->setGroup($this->gid, true);
        $this->setUser($this->uid, true);
        $this->setUser($uid, true);
        $this->setGroup($gid, true);
    }

    /**
     * Attach one or more listeners
     *
     * Implementors may add an optional $priority argument; the EventManager
     * implementation will pass this to the aggregate.
     *
     * @param EventManagerInterface $events
     * @param int $priority
     * @return void
     */
    public function attach(EventManagerInterface $events, $priority = 1)
    {
        $this->eventHandles[] = $events->attach(SchedulerEvent::EVENT_PROCESS_INIT, function(SchedulerEvent $e) {
            $this->onProcessInit($e);
        });
    }

    /**
     * Detach all previously attached listeners
     *
     * @param EventManagerInterface $events
     * @return void
     */
    public function detach(EventManagerInterface $events)
    {
        foreach ($this->eventHandles as $handle) {
            $events->detach($handle);
        }
    }

    /**
     * @param SchedulerEvent $event
     */
    protected function onProcessInit(SchedulerEvent $event)
    {
        $this->setGroup($this->gid, false);
        $this->setUser($this->uid, false);
    }

    protected function setGroup($gid, $asEffectiveUser)
    {
        $result = $asEffectiveUser ? posix_setegid($gid) : posix_setgid($gid);

        if ($result === false) {
            throw new \RuntimeException("Failed to switch to the group ID: " . $gid);
        }
    }

    protected function setUser($uid, $asEffectiveUser)
    {
        $result = $asEffectiveUser ? posix_seteuid($uid) : posix_setuid($uid);

        if ($result === false) {
            throw new \RuntimeException("Failed to switch to the user ID: " . $uid);
        }
    }
}