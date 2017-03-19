<?php

namespace Zeus\ServerService\Memcache\Message;

use Zend\Cache\Storage\Adapter\Apcu;
use Zend\Cache\Storage\StorageInterface;
use Zeus\Module;
use Zeus\ServerService\Shared\React\ConnectionInterface;
use Zeus\ServerService\Shared\React\HeartBeatMessageInterface;
use Zeus\ServerService\Shared\React\MessageComponentInterface;

/**
 * Class Message
 * @package Zeus\ServerService\Memcache\Message
 * @internal
 */
final class Message implements MessageComponentInterface, HeartBeatMessageInterface
{
    const COMMAND_LINE = 1;
    const DATA_LINE = 2;

    /** @var string */
    protected $buffer = '';

    /** @var int */
    protected $expectedPayloadSize = 0;

    /** @var int */
    protected $lineType = self::COMMAND_LINE;

    /** @var mixed[] */
    protected $activeCommand = null;

    /** @var bool */
    protected $noReply = false;

    /** @var ConnectionInterface */
    protected $connection;

    /** @var StorageInterface */
    protected $cache;

    /** @var int */
    protected $ttl = 5;

    public function __construct(StorageInterface $internalCache, StorageInterface $userCache)
    {
        $this->cache = new Apcu();
        $this->initItemsCount();
    }

    public function onHeartBeat(ConnectionInterface $connection, $data = null)
    {
        $this->ttl++;

        if ($this->ttl > 10000) {
            $connection->end();
        }
    }

    /**
     * @param ConnectionInterface $connection
     * @throws \Exception
     */
    public function onOpen(ConnectionInterface $connection)
    {
        $this->ttl = 0;
        $this->connection = $connection;
    }

    /**
     * @param ConnectionInterface $connection
     * @throws \Exception
     */
    public function onClose(ConnectionInterface $connection)
    {
        $connection->end();
    }

    /**
     * @param ConnectionInterface $connection
     * @param \Exception $exception
     * @throws \Exception
     */
    public function onError(ConnectionInterface $connection, $exception)
    {
        $connection->end();
    }

    /**
     * @param ConnectionInterface $connection
     * @param string $message
     * @throws \Exception
     */
    public function onMessage(ConnectionInterface $connection, $message)
    {
        $this->ttl = 0;
        $this->buffer .= $message;
        if ($this->lineType === static::COMMAND_LINE) {
            if (!strpos(ltrim($this->buffer), "\r\n")) {
                return;
            }

            $this->parseCommand($connection);
        }

        if ($this->lineType === static::DATA_LINE) {
            if (strlen($this->buffer) < $this->expectedPayloadSize + 2) {
                return;
            }

            if ("\r\n" !== substr($this->buffer, $this->expectedPayloadSize, $this->expectedPayloadSize + 2)) {
                $this->sendError();

                return;
            }

            $this->executeCommand($this->activeCommand);
        }
    }

    protected function sendError($message = null)
    {
        $this->connection->write("ERROR" . ($message ? ' ' . $message : ''). "\r\n");
        $this->buffer = '';
    }

    protected function getCommandRules()
    {
        return [
            'store' => [
                'regexp' => '~^(set|add|replace) (\S{1,250}) ([0-9]+) ([0-9]+) (?<bytes>[0-9]+)(?<noreply> noreply)?' . "\r\n~",
                'immediate' => false,
                ],
            'concatenate' => [
                'regexp' => '~^(append|prepend) (\S{1,250}) (?<bytes>[0-9]+)(?<noreply>noreply)?' . "\r\n~",
                'immediate' => false,
                ],
            'modify' => [
                'regexp' => '~^(cas) (\S{1,250}) ([0-9]+) ([0-9]+) (?<bytes>[0-9]+) ([0-9]+)(?<noreply> noreply)?' . "\r\n~",
                'immediate' => false,
            ],
            'fetch' => [
                'regexp' => '~^(get|gets) ([^\t\n\r]{1,250})' . "\r\n~",
                'immediate' => true,
            ],
            'delete' => [
                'regexp' => '~^(delete) (\S{1,250})(?<noreply> noreply)?' . "\r\n~",
                'immediate' => true,
            ],
            'math' => [
                'regexp' => '~^(incr|decr) (\S{1,250}) ([0-9]+)(?<noreply> noreply)?' . "\r\n~",
                'immediate' => true,
            ],
            'touch' => [
                'regexp' => '~^(touch) (\S{1,250}) ([0-9]+)(?<noreply> noreply)?' . "\r\n~",
                'immediate' => true,
            ],
            'stats' => [
                'regexp' => '~^(stats)' . "\r\n~",
                'immediate' => true,
            ],
        ];
    }

    protected function parseCommand(ConnectionInterface $connection)
    {
        $command = explode(" ", ltrim($this->buffer), 2);
        $command = $command[0];
        $commandRules = $this->getCommandRules();

        if ($command === "quit\r\n") {
            $this->buffer = '';
            $connection->end();
            return;
        }

        $found = false;
        foreach ($commandRules as $methodName => $rules) {
            if (preg_match($rules['regexp'], ltrim($this->buffer), $matches)) {
                array_shift($matches);
                $commandDetails = [[$this, $methodName], $matches];
                if ($rules['immediate']) {
                    $this->executeCommand($commandDetails);
                    $found = true;
                    break;
                }

                $this->noReply = isset($matches['noreply']) && $matches['noreply'] === ' noreply';
                $this->lineType = self::DATA_LINE;
                $this->expectedPayloadSize = $matches['bytes'];
                $this->activeCommand = $commandDetails;
                $found = true;
                break;
            }
        }

        if (!$found) {
            $this->sendError();
        }

        $this->buffer = substr($this->buffer, strpos($this->buffer, "\r\n") + 2);
        return;

    }

    protected function getValue()
    {
        $value = substr($this->buffer, 0, $this->expectedPayloadSize);
        $this->lineType = self::COMMAND_LINE;
        $this->buffer = substr($this->buffer, $this->expectedPayloadSize + 2);
        $this->expectedPayloadSize = 0;

        if (ctype_digit($value)) {
            $value = (int) $value;
        }
        return $value;
    }

    protected function executeCommand(array $commandDetails)
    {
        call_user_func_array($commandDetails[0], $commandDetails[1]);
        $this->activeCommand = null;
    }

    protected function delete($command, $key)
    {
        $success = $this->cache->removeItem($key);
        if ($success) {
            $this->decrementItemsCount();
        }

        $this->sendStatus($success ? "DELETED" : "NOT_FOUND");
    }

    protected function fetch($command, $key)
    {
        $keys = (false !== strpos($key, ' ')) ? explode(' ', $key) : [$key];

        foreach ($keys as $key) {
            $success = false;
            $cass = null;
            $value = $this->cache->getItem($key, $success, $cass);

            if ($success) {
                $flags = $this->cache->getItem('zeus_flags:' . $key, $success);
                $cas = $command === 'gets' ? ' ' . crc32(serialize($value)) : '';
                $bytes = strlen($value);
                $this->connection->write("VALUE $key $flags $bytes$cas\r\n$value\r\n");
            }
        }
        $this->connection->write("END\r\n");
    }

    protected function touch($command, $key, $expTime)
    {
        $this->cache->getOptions()->setTtl($expTime < 2592000 ? $expTime : $expTime - time());
        $result = $this->cache->touchItem($key);

        if (!$result) {
            $this->sendStatus("NOT_FOUND");

            return;
        }

        $this->sendStatus("TOUCHED");
    }

    protected function math($command, $key, $amount)
    {
        $success = false;
        $this->cache->getItem($key, $success);

        if (!$success) {
            $this->sendStatus("NOT_FOUND");

            return;
        }

        $result = 0;
        try {
            switch ($command) {
                case 'incr':
                    $result = $this->cache->incrementItem($key, $amount);
                    break;

                case 'decr':
                    $result = $this->cache->decrementItem($key, $amount);
                    if ($result < 0) {
                        $this->cache->setItem($key, 0);
                        $result = 0;
                    }
                    break;
            }
        } catch (\Exception $e) {
            $this->sendError();

            return;
        }

        if (false !== $result) {
            $this->sendStatus("$result");
            return;
        }

        $this->sendStatus("NOT_FOUND");
    }

    protected function store($command, $key, $flags, $expTime, $bytes, $noReply = '')
    {
        $result = false;
        $this->cache->getOptions()->setTtl($expTime < 2592000 ? $expTime : $expTime - time());
        $value = $this->getValue();
        $cas = crc32(serialize($value));

        switch ($command) {
            case 'set':
                $result = $this->cache->setItem($key, $value);
                break;
            case 'add':
                $result = $this->cache->addItem($key, $value);
                break;
            case 'replace':
                $result = $this->cache->replaceItem($key, $value);
                break;
        }

        if ($result) {
            $this->cache->setItem('zeus_cas:' . $key, $cas);
            $this->cache->setItem('zeus_flags:' . $key, $flags);
            $this->incrementItemsCount();
            $this->sendStatus("STORED");
        } else {
            $this->sendStatus("NOT_STORED");
        }
    }

    protected function decrementItemsCount()
    {
        $this->cache->decrementItem('zeus_items', 1);
    }

    protected function incrementItemsCount()
    {
        try {
            $this->cache->incrementItem('zeus_items', 1);
            $this->cache->incrementItem('zeus_total_items', 1);
        } catch (\Exception $e) {
            $this->initItemsCount();
        }
    }

    protected function initItemsCount()
    {
        $this->cache->getOptions()->setTtl(0);
        $this->cache->addItem('zeus_items', 0);
        $this->cache->addItem('zeus_total_items', 0);
    }

    protected function getItemsCount()
    {
        return $this->cache->getItem('zeus_items');
    }

    protected function getTotalItemsCount()
    {
        return $this->cache->getItem('zeus_total_items');
    }

    protected function concatenate($command, $key, $bytes, $noReply = '')
    {
        $success = false;
        $oldValue = $this->cache->getItem($key, $success);

        if (!$success) {
            $this->sendStatus("NOT_FOUND");

            return;
        }

        $value = $this->getValue();
        $this->cache->setItem($key, $command === 'append' ? $oldValue . $value : $value . $oldValue);
        $this->sendStatus("STORED");
    }

    protected function modify($command, $key, $flags, $expTime, $bytes, $cas, $noReply = '')
    {
        $exists = false;
        $this->cache->getItem($key, $exists);
        if (!$exists) {
            $this->sendStatus("NOT_FOUND");
        }

        $this->cache->getOptions()->setTtl($expTime - time());
        $value = $this->getValue();

        $cas = crc32(serialize($value));
        $result = $this->cache->checkAndSetItem($cas, 'zeus_cas:' . $key, $cas);

        if (!$result) {
            $this->sendStatus("EXISTS");

            return;
        }

        $this->cache->setItem($key, $value);
        $this->sendStatus("STORED");
    }

    protected function stats()
    {
        $stats = [
            'pid' => getmypid(),
            'uptime' => time() - $_SERVER['REQUEST_TIME'],
            'time' => time(),
            'version' => Module::MODULE_VERSION,
            'pointer_size' => 64, // @todo: make this detectable
            'rusage_user' => '0.0', // @todo: make this detectable
            'rusage_system' => '0.0', // @todo: make this detectable
            'curr_items' => $this->getItemsCount(),
            'total_items' => $this->getTotalItemsCount(),
            'bytes' => $this->cache->getTotalSpace() - $this->cache->getAvailableSpace(),
            'curr_connections' => 1, // @todo: make this detectable
            'total_connections' => 1, // @todo: make this detectable
            'rejected_connections' => 0, // @todo: make this detectable
            'connection_structures' => 0, // @todo: make this detectable
            'reserved_fds' => 0, // @todo: make this detectable

            'cmd_get' => 0, // @todo: make this detectable
            'cmd_set' => 0, // @todo: make this detectable
            'cmd_flush' => 0, // @todo: make this detectable
            'cmd_touch' => 0, // @todo: make this detectable

            'get_hits' => 0, // @todo: make this detectable
            'get_misses' => 0, // @todo: make this detectable
            'get_expired' => 0, // @todo: make this detectable
            'get_flushed' => 0, // @todo: make this detectable
            'delete_misses' => 0, // @todo: make this detectable
            'delete_hits' => 0, // @todo: make this detectable
            'incr_misses' => 0, // @todo: make this detectable
            'incr_hits' => 0, // @todo: make this detectable
            'decr_misses' => 0, // @todo: make this detectable
            'decr_hits' => 0, // @todo: make this detectable
            'cas_misses' => 0, // @todo: make this detectable
            'cas_hits' => 0, // @todo: make this detectable
            'cas_badval' => 0, // @todo: make this detectable
            'touch_hits' => 0, // @todo: make this detectable
            'touch_misses' => 0, // @todo: make this detectable

            'auth_cmds' => 0, // @todo: make this detectable
            'auth_errors' => 0, // @todo: make this detectable
            'idle_kicks' => 0, // @todo: make this detectable
            'evictions' => 0, // @todo: make this detectable
            'reclaimed' => 0, // @todo: make this detectable
            'bytes_read' => 0, // @todo: make this detectable
            'bytes_written' => 0, // @todo: make this detectable
            'limit_maxbytes' => $this->cache->getTotalSpace(), // @todo: make this detectable
            'accepting_conns' => 1, // @todo: make this detectable
            'listen_disabled_num' => 0, // @todo: make this detectable
            'time_in_listen_disabled_us' => 1000, // @todo: make this detectable
            'threads' => 10, // @todo: make this detectable
            'conn_yields' => 0, // @todo: make this detectable
            'hash_power_level' => 1, // @todo: make this detectable
            'hash_bytes' => 8, // @todo: make this detectable
            'hash_is_expanding' => 0, // @todo: make this detectable
            'expired_unfetched' => 0, // @todo: make this detectable
            'evicted_unfetched' => 0, // @todo: make this detectable
            'evicted_active' => 0, // @todo: make this detectable
            'slab_reassign_running' => 0, // @todo: make this detectable
            'slabs_moved' => 0, // @todo: make this detectable
            'slab_global_page_pool' => 0, // @todo: make this detectable
            'slab_reassign_rescues' => 0, // @todo: make this detectable
            'slab_reassign_evictions_nomem' => 0, // @todo: make this detectable
            'slab_reassign_chunk_rescues' => 0, // @todo: make this detectable
            'slab_reassign_inline_reclaim' => 0, // @todo: make this detectable
            'slab_reassign_busy_items' => 0, // @todo: make this detectable
            'crawler_reclaimed' => 0, // @todo: make this detectable
            'crawler_items_checked' => 0, // @todo: make this detectable
            'lrutail_reflocked' => 0, // @todo: make this detectable
            'moves_to_cold' => 0, // @todo: make this detectable
            'moves_to_warm' => 0, // @todo: make this detectable
            'moves_within_lru' => 0, // @todo: make this detectable
            'direct_reclaims' => 0, // @todo: make this detectable
            'lru_crawler_starts' => 0, // @todo: make this detectable
            'lru_maintainer_juggles' => 0, // @todo: make this detectable
            'log_worker_dropped' => 0, // @todo: make this detectable
            'log_worker_written' => 0, // @todo: make this detectable
            'log_watcher_skipped' => 0, // @todo: make this detectable
            'log_watcher_sent' => 0, // @todo: make this detectable
        ];

        foreach ($stats as $name => $stat) {
            $this->connection->write("STAT $name $stat\r\n");
        }

        $this->connection->write("END\r\n");
    }

    protected function sendStatus($value)
    {
        if ($this->noReply) {
            return;
        }

        $this->noReply = false;
        $this->connection->write("$value\r\n");
    }
}