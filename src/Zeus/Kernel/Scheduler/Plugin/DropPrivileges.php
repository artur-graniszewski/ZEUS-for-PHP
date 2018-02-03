<?php

namespace Zeus\Kernel\Scheduler\Plugin;

use RuntimeException;
use Zend\EventManager\EventManagerInterface;
use Zend\EventManager\ListenerAggregateInterface;
use Zeus\Kernel\Scheduler\WorkerEvent;

use function posix_getegid;
use function posix_geteuid;
use function posix_getgrnam;
use function posix_getpwnam;
use function posix_setegid;
use function posix_setgid;
use function posix_setuid;
use function posix_seteuid;

class DropPrivileges implements ListenerAggregateInterface
{
    /** @var mixed[] */
    private $eventHandles = [];

    /** @var mixed[] */
    private $options;

    /** @var int */
    private $uid;

    /** @var int */
    private $gid;

    /**
     * EffectiveUser constructor.
     * @param mixed[] $options
     */
    public function __construct(array $options)
    {
        $this->options = $options;

        if (!isset($options['user']) || !isset($options['group'])) {
            throw new RuntimeException("Both user name and group name must be specified");
        }
        $user = posix_getpwnam($options['user']);

        if ($user === false) {
            throw new RuntimeException("Invalid user name: " . $options['user']);
        }
        $this->uid = (int) $user["uid"];

        $group = posix_getgrnam($options['group']);

        if ($group === false) {
            throw new RuntimeException("Invalid group name: " . $options['user']);
        }
        $this->gid = (int) $group["gid"];

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
        $this->eventHandles[] = $events->attach(WorkerEvent::EVENT_INIT, function() {
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
            $events->detach($handle);
        }
    }

    protected function onWorkerInit()
    {
        $this->setGroup($this->gid, false);
        $this->setUser($this->uid, false);
    }

    protected function setGroup(int $gid, bool $asEffectiveUser)
    {
        $result = $asEffectiveUser ? $this->posixSetEgid($gid) : $this->posixSetGid($gid);

        if ($result !== true) {
            throw new RuntimeException("Failed to switch to the group ID: " . $gid);
        }
    }

    protected function setUser(int $uid, bool $asEffectiveUser)
    {
        $result = $asEffectiveUser ? $this->posixSetEuid($uid) : $this->posixSetUid($uid);

        if ($result !== true) {
            throw new RuntimeException("Failed to switch to the user ID: " . $uid);
        }
    }

    // @codeCoverageIgnoreStart

    protected function posixSetEuid(int $uid) : bool
    {
        // HHVM seems to lie to us that it changed the effective group, lets do some double checks in all posix calls then
        return posix_seteuid($uid) && posix_geteuid() === $uid;
    }

    protected function posixSetUid(int $uid) : bool
    {
        return posix_setuid($uid) && posix_getuid() === $uid;
    }

    protected function posixSetEgid(int $gid) : bool
    {
        return posix_setegid($gid) && posix_getegid() === $gid;
    }

    protected function posixSetGid(int $gid) : bool
    {
        return posix_setgid($gid) && posix_getgid() === $gid;
    }

    // @codeCoverageIgnoreEnd
}