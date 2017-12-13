<?php

namespace Zeus\Kernel\Scheduler\Helper;

/**
 * Class GarbageCollector
 * @package Zeus\Kernel\Scheduler\Helper
 * @internal
 */
trait GarbageCollector
{
    protected function collectCycles()
    {
        $enabled = gc_enabled();
        if ($enabled) {
            return $this;
        }

        gc_enable();
        if (function_exists('gc_mem_caches')) {
            // @codeCoverageIgnoreStart
            gc_mem_caches();
            // @codeCoverageIgnoreEnd
        }
        gc_collect_cycles();

        // @codeCoverageIgnoreStart
        gc_disable();
        // @codeCoverageIgnoreEnd
    }
}