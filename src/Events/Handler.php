<?php

namespace Botify\Events;

use Amp\Promise;
use Amp\Success;
use Botify\TelegramAPI;
use Botify\Types\Update;
use Botify\Utils\Bag;
use Closure;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionUnionType;
use Throwable;
use function Amp\call;
use function Amp\coroutine;
use function Botify\{array_first, array_sole, gather};

class Handler
{
    const UPDATE_TYPE_WEBHOOK = 1;
    const UPDATE_TYPE_POLLING = 2;
    const UPDATE_TYPE_HTTP_SERVER = 3;

    public static array $eventHandlers = [];

    public static function addHandler()
    {
        [$listeners, $handler] = array_pad(func_get_args(), -2, null);

        if (!is_null($listeners) && $handler instanceof Closure) {
            foreach ((array)$listeners as $listener) {
                static::$eventHandlers[strtolower($listener)] = $handler;
            }
        } else {
            if ($handler instanceof Closure) {
                static::$eventHandlers['any'] = $handler;
            } else {
                static::$eventHandlers[] = $handler;
            }
        }
    }

    public static function dispatch(Update $update): Promise
    {
        return call(function () use ($update) {
            try {
                $reflector = new class($update) {

                    public function __construct(private Update $update)
                    {
                    }

                    public function bindCallback($callback, array $arguments = [])
                    {
                        $reflection = is_object($callback) && is_callable($callback)
                            ? new ReflectionMethod($callback, '__invoke')
                            : (is_array($callback)
                                ? new ReflectionMethod(... $callback)
                                : new ReflectionFunction($callback));

                        $parameters = $reflection->getParameters();

                        foreach ($parameters as $index => $parameter) {
                            $types = $parameter->getType() instanceof ReflectionUnionType
                                ? $parameter->getType()->getTypes()
                                : [$parameter->getType()];

                            if ($value = array_sole($types, function ($type) {
                                if (! is_null($type)) {
                                    $name = $type->getName();

                                    $isEqual = function () use ($name) {
                                        foreach (Update::JSON_PROPERTY_MAP as $index => $item) {
                                            if (str_ends_with($name, $item) && isset($this->update[$index])) {
                                                return $this->update[$index];
                                            }
                                        }

                                        return false;
                                    };

                                    if ($name === get_class($this->update)) {
                                        return $this->update;
                                    } elseif ($name === TelegramAPI::class) {
                                        return $this->update->getAPI();
                                    } elseif ($value = $isEqual()) {
                                        return $value;
                                    }
                                }
                            })) {
                                $arguments[$index] = $value;
                            } else {
                                if (isset($arguments[$parameter->getName()])) {
                                    $arguments[$index] = $arguments[$parameter->getName()];
                                    unset($arguments[$parameter->getName()]);
                                } else {
                                    unset($parameters[$index]);
                                }
                            }
                        }

                        if ($reflection->getNumberOfParameters() === count($parameters)) {
                            $callback = coroutine($callback);

                            return $callback(... $arguments);
                        }
                        $callback = coroutine(static function () {
                            return false;
                        });

                        return $callback();
                    }
                };
                $bag = new Bag();
                $bag->setAPI($update->getAPI());
                $middlewares = $update->getAPI()->getMiddlewares();
                $promises = [];

                foreach ($middlewares as $middleware) {
                    $middleware = clone $middleware;
                    $promises[] = $reflector->bindCallback($middleware->setBag($bag)->getHandler());
                }

                yield gather($promises);

                $privateHandler = new class extends EventHandler {
                };
                $privateHandler = $privateHandler->register($update, $bag);
                $plugins = $update->getAPI()
                    ->getPlugin()
                    ->withUpdate($update)
                    ->withBag($bag)
                    ->withReflector($reflector);
                $promises = [$plugins->wait()];

                foreach (static::$eventHandlers as $listener => $handler) {
                    if ($handler instanceof Closure) {
                        if ($listener === 'any') {
                            $privateHandler = clone $privateHandler;
                            $promises[] = $reflector->bindCallback($handler->bindTo($privateHandler));
                        } elseif ($listener === 'mention') {
                            $privateHandler = clone $privateHandler;
                            $promises[] = $privateHandler->handleMention($handler);
                        } elseif (isset($update[$listener])) {
                            $privateHandler = clone $privateHandler;
                            $privateHandler->current = $update[$listener];
                            $promises[] = call($handler->bindTo($privateHandler), $privateHandler->current);
                        }
                    } elseif ($handler instanceof EventHandler) {
                        $handler = $handler->register($update, $bag);

                        $promises[] = call(function () use ($update, $handler, $reflector) {
                            if (!$handler->tapStarted()) {
                                yield $reflector->bindCallback([$handler, 'onStart']);
                            }

                            yield gather([
                                call([$handler, 'onAny'], $update),
                                $handler->fire()
                            ]);
                        });
                    }
                }

                yield gather($promises);
                unset($bag, $privateHandler, $plugins);
                gc_collect_cycles();
            } catch (Throwable $e) {
                $update->getAPI()->getLogger()->critical($e);
            }
        });
    }
}