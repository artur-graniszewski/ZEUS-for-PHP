<?php

namespace Zeus\ServerService\Http\Message;

use Zend\Stdlib\Parameters;
use Zend\Http\Request as ZendRequest;

class Request extends ZendRequest
{
    protected $headersOverview = [];

    /**
     * Base Path of the application.
     *
     * @var string
     */
    protected $basePath;

    /**
     * @return string
     */
    public function getBasePath()
    {
        return $this->basePath;
    }

    /**
     * @param string $basePath
     * @return $this
     */
    public function setBasePath($basePath)
    {
        $this->basePath = $basePath;

        return $this;
    }

    /**
     * A factory that produces a Request object from a well-formed Http Request string
     *
     * @param  string $buffer
     * @param  bool $allowCustomMethods
     * @throws \InvalidArgumentException
     * @return $this
     */
    public static function fromStringOfHeaders($buffer, $allowCustomMethods)
    {
        if ("\r\n\r\n" !== substr($buffer, -4)) {
            throw new \InvalidArgumentException(
                'An EOM was not found at the end of request buffer'
            );
        }

        $request = new static();
        $request->setAllowCustomMethods($allowCustomMethods);

        // first line must be Method/Uri/Version string
        $matches   = null;
        $methods   = $allowCustomMethods
            ? '[\w-]+'
            : 'OPTIONS|GET|HEAD|POST|PUT|DELETE|TRACE|CONNECT|PATCH';

        $regex = '#^(?P<method>' . $methods . ')\s(?P<uri>[^ ]*)(?:\sHTTP\/(?P<version>\d+\.\d+)){1}\r\n#S';
        if (!preg_match($regex, $buffer, $matches)) {
            throw new \InvalidArgumentException(
                'A valid request line was not found in the provided string '
            );
        }

        $request->setMethod($matches['method']);
        $request->setUri($matches['uri']);
        $request->setVersion($matches['version']);

        $parsedUri = parse_url($matches['uri']);
        if (isset($parsedUri['query'])) {
            $parsedQuery = [];
            parse_str($parsedUri['query'], $parsedQuery);
            $request->setQuery(new Parameters($parsedQuery));
        }

        // remove first line
        $buffer = substr($buffer, strlen($matches[0]));

        // no headers in request
        if ($buffer === "\r\n") {
            return $request;
        }

        $request->headers = $buffer;

        if (preg_match_all('/(?P<name>[^()><@,;:\"\\/\[\]?=}{ \t]+):[\s]*(?P<value>[^\r\n]*)\r\n/S', $buffer, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $request->headersOverview[strtolower($match['name'])][] = $match['value'];
            }
        }

        return $request;
    }

    /**
     * @param string $name
     * @param bool $toLower
     * @return null|string
     */
    public function getHeaderOverview($name, $toLower)
    {
        $name = strtolower($name);
        if (!isset($this->headersOverview[$name])) {
            return null;
        }

        if (false === isset($this->headersOverview[$name][1])) {
            return $toLower ? strtolower($this->headersOverview[$name][0]) : $this->headersOverview[$name][0];
        }

        return $this->headersOverview[$name];
    }

    /**
     * @return string "keep-alive" or "close"
     */
    public function getConnectionType()
    {
        $connectionType = $this->getHeaderOverview('Connection', true);

        return ($this->getVersion() === Request::VERSION_11 && $connectionType !== 'close') ? 'keep-alive' : $connectionType;
    }
}