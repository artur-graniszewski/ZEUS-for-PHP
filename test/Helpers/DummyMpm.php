<?php

namespace ZeusTest\Helpers;


use Zend\EventManager\EventManagerInterface;
use Zeus\Kernel\ProcessManager\MultiProcessingModule\MultiProcessingModuleCapabilities;
use Zeus\Kernel\ProcessManager\MultiProcessingModule\MultiProcessingModuleInterface;

class DummyMpm implements MultiProcessingModuleInterface
{

    /**
     * @param EventManagerInterface $events
<<<<<<< HEAD
     * @return $this
=======
     * @return mixed
>>>>>>> 2371fdb1db521ecdd3c747d939f597de711fcb0e
     */
    public function attach(EventManagerInterface $events)
    {
        // TODO: Implement attach() method.
<<<<<<< HEAD

        return $this;
=======
>>>>>>> 2371fdb1db521ecdd3c747d939f597de711fcb0e
    }

    /**
     * @return MultiProcessingModuleCapabilities
     */
    public function getCapabilities()
    {
        // TODO: Implement getCapabilities() method.
<<<<<<< HEAD

        return new MultiProcessingModuleCapabilities();
=======
>>>>>>> 2371fdb1db521ecdd3c747d939f597de711fcb0e
    }
}