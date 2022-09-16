<?php

namespace Botify\Utils\Plugins;

use ArrayAccess;
use Botify\TelegramAPI;
use Botify\Traits\Accessible;
use Botify\Types\Update;
use Botify\Utils\Bag;
use Botify\Utils\Plugins\Exceptions\ContinuePropagation;
use Botify\Utils\Plugins\Exceptions\StopPropagation;
use Closure;

abstract class Pluggable implements ArrayAccess
{
    use Accessible;

    public ?Update $update;
    private ?TelegramAPI $api;
    private ?Bag $bag;
    private $fn;
    private array $filters;
    private int $priority;

    final public function __construct(array $filters = [], ?callable $fn = null, int $priority = 0)
    {
        $this->filters = array_filter($filters, 'is_callable');
        $this->fn = $fn;
        $this->priority = $priority;
    }

    final public function __call($name, array $arguments = [])
    {
        return [$this->update->getAPI(), $name](... $arguments);
    }

    /**
     * Add new filter
     *
     * @param callable $filter
     * @return Pluggable
     */
    public function addFilter(callable $filter): Pluggable
    {
        $this->filters[] = $filter;

        return $this;
    }

    public function continuePropagation()
    {
        throw new ContinuePropagation();
    }

    public function getAPI(): ?TelegramAPI
    {
        return $this->api;
    }

    public function setAPI(TelegramAPI $api)
    {
        $self = clone $this;
        $self->api = $api;
        return $self;
    }

    public function getAccessibles(): array
    {
        return [$this->api, $this->update, $this->bag];
    }

    final public function getCallback(): callable
    {
        $callback = $this->fn ?? [$this, 'handle'];

        return $callback instanceof Closure
            ? $callback->bindTo($this)
            : $callback;
    }

    /**
     * @return array
     */
    public function getFilters(): array
    {
        return $this->filters;
    }

    public function getPriority(): int
    {
        return $this->priority;
    }

    public function setPriority(int $priority): Pluggable
    {
        $self = clone $this;
        $self->priority = $priority;
        return $self;
    }

    public function setBag(Bag $bag): Pluggable
    {
        $self = clone $this;
        $self->bag = $bag;
        return $self;
    }

    /**
     * @param Update $update
     * @return Pluggable
     */
    public function setUpdate(Update $update): Pluggable
    {
        $self = clone $this;
        $self->update = $update;
        return $self;
    }

    public function stopPropagation()
    {
        throw new StopPropagation();
    }
}