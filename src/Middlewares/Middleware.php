<?php

namespace Botify\Middlewares;

use Botify\Utils\Bag;

abstract class Middleware
{
    private $handler;
    public Bag $bag;

    final public function __construct($handler = null)
    {
        $this->setHandler($handler);
    }

    final public function setHandler($handler)
    {
        $this->handler = $handler;
    }

    final public function getHandler()
    {
        return $this->handler->bindTo($this) ?: [$this, 'handle'];
    }

    final public function setBag(Bag $bag): Middleware
    {
        $this->bag = $bag;

        return $this;
    }

    public function __set($name, $value)
    {
        if (is_null($name)) {
            $this->bag[] = $value;
        } else {
            $this->bag[$name] = $value;
        }
    }

    public function __get($name)
    {
        return $this->bag[$name];
    }
}