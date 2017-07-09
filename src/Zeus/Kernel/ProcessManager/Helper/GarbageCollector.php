<?php

namespace Zeus\Kernel\ProcessManager\Helper;

/**
 * Class GarbageCollector
 * @package Zeus\Kernel\ProcessManager\Helper
 * @internal
 */
trait GarbageCollector
{
    /**
     * @return $this
     */
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

        return $this;
    }
}