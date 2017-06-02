<?php

namespace Zeus\Helper;

trait GarbageCollector
{
    /**
     * @return $this
     */
    protected function collectCycles()
    {
        $enabled = gc_enabled();
        gc_enable();
        if (function_exists('gc_mem_caches')) {
            // @codeCoverageIgnoreStart
            gc_mem_caches();
            // @codeCoverageIgnoreEnd
        }
        gc_collect_cycles();


        if (!$enabled) {
            // @codeCoverageIgnoreStart
            gc_disable();
            // @codeCoverageIgnoreEnd
        }

        return $this;
    }
}