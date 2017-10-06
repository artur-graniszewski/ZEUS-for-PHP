<?php

namespace Zeus\Kernel\Scheduler\Plugin;

use Zend\EventManager\EventManagerInterface;
use Zend\EventManager\ListenerAggregateInterface;
use Zeus\Kernel\Scheduler\WorkerEvent;

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
        $this->eventHandles[] = $events->getSharedManager()->attach('*', WorkerEvent::EVENT_WORKER_INIT, function() {
            $this->onWorkerInit();
        }, $priority);
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
            //$events->getSharedManager()->detach($handle);
        }
    }

    protected function onWorkerInit()
    {
        $this->setGroup($this->gid, false);
        $this->setUser($this->uid, false);
    }

    /**
     * @param int $gid
     * @param bool $asEffectiveUser
     * @return $this
     */
    protected function setGroup($gid, $asEffectiveUser)
    {
        $result = $asEffectiveUser ? $this->posixSetEgid($gid) : $this->posixSetGid($gid);

        if ($result !== true) {
            throw new \RuntimeException("Failed to switch to the group ID: " . $gid);
        }

        return $this;
    }

    /**
     * @param int $uid
     * @param bool $asEffectiveUser
     * @return $this
     */
    protected function setUser($uid, $asEffectiveUser)
    {
        $result = $asEffectiveUser ? $this->posixSetEuid($uid) : $this->posixSetUid($uid);

        if ($result !== true) {
            throw new \RuntimeException("Failed to switch to the user ID: " . $uid);
        }

        return $this;
    }

    // @codeCoverageIgnoreStart

    /**
     * @param int $uid
     * @return bool
     */
    protected function posixSetEuid($uid)
    {
        // HHVM seems to lie to us that it changed the effective group, lets do some double checks in all posix calls then
        return posix_seteuid($uid) && posix_geteuid() === $uid;
    }

    /**
     * @param int $uid
     * @return bool
     */
    protected function posixSetUid($uid)
    {
        return posix_setuid($uid) && posix_getuid() === $uid;
    }

    /**
     * @param int $gid
     * @return bool
     */
    protected function posixSetEgid($gid)
    {
        return posix_setegid($gid) && posix_getegid() === $gid;
    }

    /**
     * @param int $gid
     * @return bool
     */
    protected function posixSetGid($gid)
    {
        return posix_setgid($gid) && posix_getgid() === $gid;
    }

    // @codeCoverageIgnoreEnd
}