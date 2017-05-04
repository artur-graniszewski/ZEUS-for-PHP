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
        $this->config['logger'] = get_class();

        $config = new Config($this->getConfig());
        $this->getServer($this->message, $config);

        parent::start();

        return $this;
    }

    /**
     * @param Message $message
     * @return $this
     */
    public function setMessageComponent($message)
    {
        $this->message = $message;

        return $this;
    }
}