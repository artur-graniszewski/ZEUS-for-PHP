<?php

namespace Zeus\ServerService\Memcache\Message;

use Zend\Cache\Storage\AvailableSpaceCapableInterface;
use Zend\Cache\Storage\FlushableInterface;
use Zend\Cache\Storage\StorageInterface;
use Zend\Cache\Storage\TotalSpaceCapableInterface;
use Zeus\IO\Stream\NetworkStreamInterface;
use Zeus\Module;
use Zeus\ServerService\Shared\Exception\PrerequisitesNotMetException;
use Zeus\ServerService\Shared\Networking\HeartBeatMessageInterface;
use Zeus\ServerService\Shared\Networking\MessageComponentInterface;

use function strpos;
use function strlen;
use function ltrim;
use function sha1;
use function call_user_func_array;
use function is_int;
use function ctype_digit;
use function time;
use function getmypid;
use function crc32;
use function explode;
use function preg_match;

/**
 * Class Message
 * @package Zeus\ServerService\Memcache\Message
 * @internal
 */
final class Message implements MessageComponentInterface, HeartBeatMessageInterface
{
    const COMMAND_LINE = 1;
    const DATA_LINE = 2;

    /** @var bool */
    private $storeFlags = true;

    /** @var bool */
    private $trackStats = true;

    /** @var bool */
    private $useNativeCas = false;

    /** @var string */
    private $buffer = '';

    /** @var int */
    private $expectedPayloadSize = 0;

    /** @var int */
    private $lineType = self::COMMAND_LINE;

    /** @var mixed[] */
    private $activeCommand = null;

    /** @var bool */
    private $noReply = false;

    /** @var NetworkStreamInterface */
    private $connection;

    /** @var StorageInterface */
    private $cache;

    /** @var StorageInterface */
    private $status;

    /** @var int */
    private $connectionTime = 0;

    /** @var int */
    private $connectionTimeout = 10000;

    /**
     * Message constructor.
     * @param StorageInterface $internalCache
     * @param StorageInterface $userCache
     */
    public function __construct(StorageInterface $internalCache, StorageInterface $userCache)
    {
        if (!$userCache instanceof FlushableInterface) {
            throw new PrerequisitesNotMetException('User storage must implement FlushableInterface');
        }
        $this->cache = $userCache;
        $this->status = $internalCache;
        $this->initItemsCount();
        $this->setCasMode();
    }

    public function onHeartBeat(NetworkStreamInterface $connection, $data = null)
    {
        $this->connectionTime++;

        if ($this->connectionTime >= $this->connectionTimeout) {
            $connection->close();
        }
    }

    public function setConnectionTimeout(int $connectionTimeout)
    {
        $this->connectionTimeout = $connectionTimeout;
    }

    public function getConnectionTimeout() : int
    {
        return $this->connectionTimeout;
    }

    /**
     * @param NetworkStreamInterface $connection
     * @throws \Exception
     */
    public function onOpen(NetworkStreamInterface $connection)
    {
        $this->connectionTime = 0;
        $this->connection = $connection;
        $this->connection->setWriteBufferSize(0);
    }

    /**
     * @param NetworkStreamInterface $connection
     * @throws \Exception
     */
    public function onClose(NetworkStreamInterface $connection)
    {
        $connection->close();
    }

    /**
     * @param NetworkStreamInterface $connection
     * @param \Throwable $exception
     * @throws \Exception
     */
    public function onError(NetworkStreamInterface $connection, \Throwable $exception)
    {
        if (!$connection->isClosed()) {
            $connection->close();
        }
    }

    /**
     * @param NetworkStreamInterface $connection
     * @param string $message
     * @throws \Exception
     */
    public function onMessage(NetworkStreamInterface $connection, string $message)
    {
        $this->connectionTime = 0;
        $this->buffer .= $message;

        if ($this->lineType === static::COMMAND_LINE) {
            if (!strpos(ltrim($this->buffer), "\r\n")) {
                return;
            }

            $this->parseCommand();
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
            $this->activeCommand = null;
        }
    }

    /**
     * Some cache implementations use different type of CAS algorithms, we need to operate on integers
     */
    protected function setCasMode()
    {
        $this->cache->setItem('zeus_memcache', 'cas_test');
        $this->cache->getItem('zeus_memcache', $success, $casToken);

        if ($success) {
            $this->useNativeCas = ctype_digit((string) $casToken);
        }
    }

    /**
     * Sends error to the client.
     *
     * @param string $message
     */
    protected function sendError(string $message = null)
    {
        $this->connection->write("ERROR" . ($message ? ' ' . $message : ''). "\r\n");
        $this->buffer = '';
    }

    /**
     * Provides memcached lexical rules to command parser.
     *
     * @return mixed[]
     */
    protected function getCommandRules() : array
    {
        $eol = "[\\t\\s]*\r\n~S";
        return [
            'fetch' => [
                'regexp' => '~^(?<command>get|gets) (?<key>[^\t\n\r]{1,250})' . $eol,
                'immediate' => true,
            ],
            'store' => [
                'regexp' => '~^(?<command>set|add|replace) (?<key>\S{1,250}) (?<flags>[0-9]+) (?<exp>[0-9]+) (?<bytes>[0-9]+)(?<noreply> noreply)?' . $eol,
                'immediate' => false,
            ],
            'delete' => [
                'regexp' => '~^(?<command>delete) (?<key>\S{1,250})(?<noreply> noreply)?' . $eol,
                'immediate' => true,
            ],
            'math' => [
                'regexp' => '~^(?<command>incr|decr) (?<key>\S{1,250}) (?<amount>[0-9]+)(?<noreply> noreply)?' . $eol,
                'immediate' => true,
            ],
            'touch' => [
                'regexp' => '~^(?<command>touch) (?<key>\S{1,250}) (?<exp>[0-9]+)(?<noreply> noreply)?' . $eol,
                'immediate' => true,
            ],
            'concatenate' => [
                'regexp' => '~^(?<command>append|prepend) (?<key>\S{1,250}) (?<bytes>[0-9]+)(?<noreply>noreply)?' . $eol,
                'immediate' => false,
            ],
            'modify' => [
                'regexp' => '~^(?<command>cas) (?<key>\S{1,250}) (?<flags>[0-9]+) (?<exp>[0-9]+) (?<bytes>[0-9]+) (?<cas>[0-9]+)(?<noreply> noreply)?' . $eol,
                'immediate' => false,
            ],
            'stats' => [
                'regexp' => '~^(?<command>stats)' . $eol,
                'immediate' => true,
            ],
            'flush' => [
                'regexp' => '~^(?<command>flush_all)(?<noreply> noreply)?' . $eol,
                'immediate' => true,
            ],
            'flushBefore' => [
                'regexp' => '~^(?<command>flush_all) (?<ttl>[0-9]+)(?<noreply> noreply)?' . $eol,
                'immediate' => true,
            ],
            'version' => [
                'regexp' => '~^(?<command>version)' . $eol,
                'immediate' => true,
            ],
        ];
    }

    /**
     * Increases hit count for the given operation.
     *
     * @param string $operation
     * @param int $hits
     */
    protected function markHit(string $operation, int $hits = 1)
    {
        if ($this->trackStats && $hits > 0) {
            $this->status->incrementItem('zeus_hits_' . $operation, $hits);
        }
    }

    /**
     * Increases miss count for the given operation.
     *
     * @param string $operation
     * @param int $misses
     */
    protected function markMiss(string $operation, int $misses = 1)
    {
        if ($this->trackStats && $misses > 0) {
            $this->status->incrementItem('zeus_misses_' . $operation, $misses);
        }
    }


    /**
     * Increases cas bad value count for the given operation.
     * @internal param string $operation
     * @internal param int $misses
     */
    protected function markCasBadValue()
    {
        if ($this->trackStats) {
            $this->status->incrementItem('zeus_cas_badval', 1);
        }
    }

    /**
     * Returns hits counter
     *
     * @param string $operation
     * @return int
     */
    protected function getHits(string $operation) : int
    {
        return (int) $this->status->getItem('zeus_hits_' . $operation);
    }

    /**
     * Returns miss counter
     *
     * @param string $operation
     * @return int
     */
    protected function getMisses(string $operation) : int
    {
        return (int) $this->status->getItem('zeus_misses_' . $operation);
    }

    /**
     * Returns cas bad value counter
     *
     * @return int
     */
    protected function getCasBadValues() : int
    {
        return (int) $this->status->getItem('zeus_cas_badval');
    }

    /**
     * Returns command usage counter
     *
     * @param string $operation
     * @return int
     */
    protected function getCommandUsage(string $operation) : int
    {
        return (int) $this->status->getItem('zeus_cmd_' . $operation);
    }

    /**
     * Increases command usage counter
     *
     * @param string $operation
     */
    protected function markCommandUsage(string $operation)
    {
        if ($this->trackStats) {
            $this->status->incrementItem('zeus_cmd_' . $operation, 1);
        }
    }

    /**
     * Parses memcache commands
     */
    protected function parseCommand()
    {
        $command = explode(" ", ltrim($this->buffer), 2);
        $command = $command[0];
        $commandRules = $this->getCommandRules();

        if ($command === "quit\r\n") {
            $this->buffer = '';
            $this->connection->close();
            return;
        }

        $found = false;
        $command = ltrim($this->buffer);

        foreach ($commandRules as $methodName => $rules) {
            if (preg_match($rules['regexp'], $command, $matches)) {
                $matches = array_intersect_key($matches, array_flip(array_filter(array_keys($matches), function($value) { return !is_int($value);})));

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

        $this->buffer = substr($this->buffer, strpos($this->buffer, "\r\n") + 2);

        if (!$found) {
            $this->sendError();
        }
    }

    /**
     * Gets value from the incoming data block
     *
     * @return int|string
     */
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

    /**
     * Executes memcached command
     *
     * @param mixed[] $commandDetails
     */
    protected function executeCommand(array $commandDetails)
    {
        try {
            call_user_func_array($commandDetails[0], $commandDetails[1]);
        } catch (\Exception $e) {
            $this->sendError($e->getMessage());
        }
    }

    /**
     * Deletes memcache key if it exists.
     *
     * @param string $command
     * @param string $key
     */
    protected function delete(string $command, string $key)
    {
        $key = sha1($key);
        $success = $this->cache->removeItem($key);
        if ($success) {
            $this->decrementItemsCount();
        }

        $success ? $this->markHit($command) : $this->markMiss($command);
        $this->sendStatus($success ? "DELETED" : "NOT_FOUND");
    }

    /**
     * Sends server version to the client.
     */
    protected function version()
    {
        $this->connection->write("VERSION " . Module::MODULE_VERSION . "\r\n");
    }

    /**
     * Flushes cache
     */
    protected function flush()
    {
        $backup = $this->backupServerStats();
        $success = $this->cache->flush();
        $this->restoreServerStats($backup);
        $this->markCommandUsage('flush');

        $this->sendStatus($success ? "OK" : "ERROR");
    }

    /**
     * Flushes all entries which would expire in given TTL parameter
     *
     * @todo: right now TTL is ignored for this command, full flush is performed instead
     */
    protected function flushBefore()
    {
        $backup = $this->backupServerStats();
        $success = $this->cache->flush();
        $this->restoreServerStats($backup);
        $this->markCommandUsage('flush');

        $this->sendStatus($success ? "OK" : "ERROR");
    }

    /**
     * Fetches cache entry.
     *
     * @param string $command
     * @param string $key
     */
    protected function fetch(string $command, string $key)
    {
        $keys = (false !== strpos($key, ' ')) ? explode(' ', $key) : [$key];
        $hits = 0;
        $misses = 0;

        foreach ($keys as $key) {
            $originalKey = $key;
            $key = sha1($key);
            $success = false;
            $cas = null;
            $value = $this->cache->getItem($key, $success, $cass);

            if ($success) {
                $flags = $this->storeFlags ? $this->cache->getItem('zeus_flags_' . $key, $success, $cas) : 0;
                $cas = $command === 'gets' ? ' ' . ($this->useNativeCas ? $cass : crc32(sha1(serialize($value)))) : '';

                $bytes = strlen($value);
                $this->connection->write("VALUE $originalKey $flags $bytes$cas\r\n$value\r\n");
                $hits++;
            }

            if (!$success) {
                $misses++;
            }
        }

        $this->markCommandUsage('get');
        $this->markHit('get', $hits);
        $this->markMiss('get', $misses);

        $this->connection->write("END\r\n");
    }

    /**
     * Touches cache entry
     *
     * @param string $command
     * @param string $key
     * @param int $expTime
     */
    protected function touch(string $command, string $key, int $expTime)
    {
        $key = sha1($key);
        $this->markCommandUsage($command);
        $this->cache->getOptions()->setTtl($expTime < 2592000 ? $expTime : $expTime - time());
        $result = $this->cache->touchItem($key);

        if (!$result) {
            $this->markMiss('touch');
            $this->sendStatus("NOT_FOUND");

            return;
        }

        $this->markHit('touch');
        $this->sendStatus("TOUCHED");
    }

    /**
     * Handles incr/decr operations.
     *
     * @param string $command
     * @param string $key
     * @param int $amount
     */
    protected function math(string $command, string $key, int $amount)
    {
        $key = sha1($key);
        $this->markCommandUsage('set');
        $success = false;
        $value = $this->cache->getItem($key, $success);

        if (!$success) {
            $this->markMiss($command);
            $this->sendStatus("NOT_FOUND");

            return;
        }

        if (!is_int($value) && !ctype_digit($value)) {
            $this->sendStatus("ERROR");
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
            $this->markHit($command);
            return;
        }

        $this->markMiss($command);
        $this->sendStatus("NOT_FOUND");
    }

    /**
     * Stores client data.
     *
     * @param string $command
     * @param string $key
     * @param int $flags
     * @param int $expTime
     */
    protected function store(string $command, string $key, int $flags, int $expTime)
    {
        $key = sha1($key);
        $this->markCommandUsage('set');
        $result = false;
        $this->cache->getOptions()->setTtl($expTime < 2592000 ? $expTime : $expTime - time());
        $value = $this->getValue();
        $cas = crc32(sha1(serialize($value)));

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

        $this->cache->setItem('zeus_cas_' . $key, $cas);
        if ($result) {

            if ($this->storeFlags) {
                $this->cache->setItem('zeus_flags_' . $key, $flags);
            }
            $this->incrementItemsCount();
            $this->sendStatus("STORED");

            return;
        }

        $this->sendStatus("NOT_STORED");
    }

    /**
     * Handles append/prepend operations
     *
     * @param string $command
     * @param string $key
     */
    protected function concatenate(string $command, string $key)
    {
        $key = sha1($key);
        $this->markCommandUsage('set');
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

    /**
     * Handles cas operation.
     *
     * @param string $command
     * @param string $key
     * @param int $flags
     * @param int $expTime
     * @param int $bytes
     * @param int $requestedCas
     */
    protected function modify(string $command, string $key, int $flags, int $expTime, int $bytes, int $requestedCas)
    {
        $cas = null;
        $key = sha1($key);
        $exists = false;
        $this->cache->getItem($key, $exists);
        if (!$exists) {
            $this->markMiss($command);
            $this->sendStatus("NOT_FOUND");

            return;
        }

        $this->cache->getOptions()->setTtl($expTime - time());
        $value = $this->getValue();

        if ($this->useNativeCas && !$this->cache->checkAndSetItem($requestedCas, $key, $value)) {
            $this->markCasBadValue();
            $this->sendStatus("EXISTS");

            return;
        }

        if (!$this->useNativeCas) {
            $cas = $this->cache->getItem('zeus_cas_' . $key);
            if ((int) $cas !== (int) $requestedCas) {
                $this->markCasBadValue();
                $this->sendStatus("EXISTS");

                return;
            }

            $cas2 = crc32(sha1(serialize($value)));
            $this->cache->setItem($key, $value);
            $this->cache->setItem('zeus_cas_' . $key, $cas2);
        }

        if ($this->storeFlags) {
            $this->cache->setItem('zeus_flags_' . $key, $flags);
        }

        $this->markHit('cas');
        $this->sendStatus("STORED");
    }

    /**
     * Returns server statistics
     */
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
            'curr_items' => (int) $this->getItemsCount(),
            'total_items' => (int) $this->getTotalItemsCount(),

            'bytes' =>
                $this->cache instanceof TotalSpaceCapableInterface && $this->cache instanceof AvailableSpaceCapableInterface ?
                    ($this->cache->getTotalSpace() - $this->cache->getAvailableSpace()) : 0,

            'available_bytes' => $this->cache instanceof AvailableSpaceCapableInterface ? $this->cache->getAvailableSpace() : 0,

            'curr_connections' => 1, // @todo: make this detectable
            'total_connections' => 1, // @todo: make this detectable
            'rejected_connections' => 0, // @todo: make this detectable
            'connection_structures' => 0, // @todo: make this detectable
            'reserved_fds' => 0, // @todo: make this detectable

            'cmd_get' => $this->getCommandUsage('get'),
            'cmd_set' => $this->getCommandUsage('set'),
            'cmd_flush' => $this->getCommandUsage('flush'),
            'cmd_touch' => $this->getCommandUsage('touch'),

            'get_flushed' => 0, // @todo: make this detectable
            'cas_badval' => $this->getCasBadValues(),
            'get_expired' => 0, // @todo: make this detectable

            'auth_cmds' => 0, // @todo: make this detectable
            'auth_errors' => 0, // @todo: make this detectable
            'idle_kicks' => 0, // @todo: make this detectable
            'evictions' => 0, // @todo: make this detectable
            'reclaimed' => 0, // @todo: make this detectable
            'bytes_read' => 0, // @todo: make this detectable
            'bytes_written' => 0, // @todo: make this detectable
            'limit_maxbytes' => $this->cache instanceof TotalSpaceCapableInterface ? (int) $this->cache->getTotalSpace() : 0, // @todo: make this detectable
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

        foreach (['incr', 'decr', 'get', 'touch', 'delete', 'cas'] as $commandName) {
            $stats[$commandName . '_hits'] = $this->getHits($commandName);
            $stats[$commandName . '_misses'] = $this->getMisses($commandName);
        }

        foreach ($stats as $name => $stat) {
            $this->connection->write("STAT $name $stat\r\n");
        }

        $this->connection->write("END\r\n");
    }

    protected function decrementItemsCount()
    {
        $this->status->decrementItem('zeus_items', 1);
    }

    protected function incrementItemsCount()
    {
        try {
            $this->status->incrementItem('zeus_items', 1);
            $this->status->incrementItem('zeus_total_items', 1);
        } catch (\Exception $e) {
            $this->initItemsCount();
        }
    }

    protected function initItemsCount()
    {
        $this->status->getOptions()->setTtl(0);
        foreach (['get', 'set', 'flush', 'touch'] as $commandName) {
            $this->status->addItem('zeus_cmd_' . $commandName, 0);
        }

        foreach (['get', 'delete', 'incr', 'decr', 'cas', 'touch'] as $commandName) {
            $this->status->addItem('zeus_hits_' . $commandName, 0);
            $this->status->addItem('zeus_misses_' . $commandName, 0);
        }

        $this->status->addItem('zeus_items', 0);
        $this->status->addItem('zeus_total_items', 0);
    }

    /**
     * @return int[]
     */
    protected function backupServerStats() : array
    {
        $backup = [];
        foreach (['get', 'set', 'flush', 'touch'] as $commandName) {
            $backup['zeus_cmd_' . $commandName] = (int) $this->status->getItem('zeus_cmd_' . $commandName);
        }

        foreach (['get', 'delete', 'incr', 'decr', 'cas', 'touch'] as $commandName) {
            $backup['zeus_hits_' . $commandName] = (int) $this->status->getItem('zeus_hits_' . $commandName);
            $backup['zeus_misses_' . $commandName] = (int) $this->status->getItem('zeus_misses_' . $commandName);
        }

        $backup['zeus_items'] = (int) $this->status->getItem('zeus_items');
        $backup['zeus_total_items'] = (int) $this->status->getItem('zeus_total_items');

        return $backup;
    }

    /**
     * @param int[] $backup
     */
    protected function restoreServerStats(array $backup)
    {
        $this->status->getOptions()->setTtl(0);

        foreach ($backup as $key => $value) {
            $this->status->setItem($key, $value);
        }
    }

    protected function getItemsCount()
    {
        return $this->status->getItem('zeus_items');
    }

    protected function getTotalItemsCount()
    {
        return $this->status->getItem('zeus_total_items');
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