<?php

namespace Zeus\ServerService\Shared\Logger;

use Zend\Log\Filter\FilterInterface;
use Zend\Log\Filter\Priority;

/**
 * Class DynamicPriorityFilter
 * @package Zeus\ServerService\Shared\Logger
 * @internal
 */
final class DynamicPriorityFilter implements FilterInterface
{
    /** @var Priority */
    protected static $filter;

    protected static $priority = 0;

    public function __construct(int $priority)
    {
        static::$priority = $priority;
        if (!static::$filter) {
            static::$filter = new Priority($priority);
        }
    }

    public static function overridePriority(int $priority)
    {
        static::$filter = new Priority($priority);
    }

    public static function resetPriority()
    {
        static::$filter = new Priority(static::$priority);
    }

    /**
     * Returns TRUE to accept the message, FALSE to block it.
     *
     * @param array $event event data
     * @return bool accepted?
     */
    public function filter(array $event)
    {
        return static::$filter->filter($event);
    }
}