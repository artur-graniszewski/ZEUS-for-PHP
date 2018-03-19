<?php

namespace Zeus\ServerService\Memcache;

use Zeus\ServerService\Memcache\Message\Message;
use Zeus\ServerService\Shared\AbstractSocketServerService;

class Service extends AbstractSocketServerService
{
    /** @var Message */
    protected $message;

    public function start()
    {
        $config = $this->getConfig();
        $config['logger'] = get_class();

        $config = new Config($config);
        $this->getServer($this->message, $config);

        parent::start();
    }

    public function setMessageComponent(Message $message)
    {
        $this->message = $message;
    }
}