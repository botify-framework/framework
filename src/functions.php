<?php

namespace Botify;

use Amp\Delayed;
use Amp\Promise;
use ArrayAccess;
use Botify\Exceptions\RetryException;
use Botify\Utils\ReplyMarkup;
use Botify\Utils\Collection;
use Botify\Utils\Config;
use Botify\Utils\Dotty;
use Closure;
use Exception;
use function Amp\call;
use function Amp\coroutine;
use function Amp\File\read;
use function Amp\File\write;

if (!function_exists('Botify\\retry')) {
    /**
     * Retry an operation a given number of times.
     *
     * @param int|array $times
     * @param callable $callback
     * @param int|Closure $sleepMilliseconds
     * @param callable|null $when
     * @return mixed
     *
     * @throws Exception
     */
    function retry($times, callable $callback, $sleepMilliseconds = 0, $when = null): mixed
    {
        return call(function () use ($times, $callback, $when, $sleepMilliseconds) {
            $attempts = 0;

            $backoff = [];

            $callback = coroutine($callback);

            if (is_array($times)) {
                $backoff = $times;

                $times = count($times) + 1;
            }

            beginning:
            $attempts++;
            $times--;

            try {
                return yield $callback($attempts);
            } catch (RetryException $e) {
                if ($times < 1 || ($when && !$when($e))) {
                    throw $e;
                }

                $sleepMilliseconds = $backoff[$attempts - 1] ?? $sleepMilliseconds;

                if ($sleepMilliseconds) {
                    yield ausleep(value($sleepMilliseconds, $attempts, $e) * 1000);
                }

                goto beginning;
            }
        });
    }
}

if (!function_exists('Botify\\tap')) {
    /**
     * @param $value
     * @param $callback
     * @return mixed
     */
    function tap($value, $callback): mixed
    {
        $callback($value);

        return $value;
    }
}

if (!function_exists('Botify\\gather')) {

    function gather($promises): Promise
    {
        return Promise\all($promises);
    }
}

if (!function_exists('Botify\\ausleep')) {
    /**
     * @param $microseconds
     * @return Delayed
     */
    function ausleep($microseconds): Delayed
    {
        return new Delayed($microseconds);
    }
}

if (!function_exists('Botify\\asleep')) {
    /**
     * @param $time
     * @return Promise
     */
    function asleep($time): Promise
    {
        return ausleep($time * 1000);
    }
}

if (!function_exists('Botify\\collect')) {
    /**
     * @param array $items
     * @param bool $recursive
     * @return Collection
     */
    function collect(array $items, bool $recursive = false): Collection
    {
        if ($recursive === true) {
            foreach ($items as &$item) {
                if (is_array($item)) {
                    $item = collect($item);
                }
            }
        }
        return new Collection($items);
    }
}

if (!function_exists('Botify\\is_collection')) {
    /**
     * Check the $value is a collection
     *
     * @param $value
     * @return bool
     */
    function is_collection($value): bool
    {
        return $value instanceof Collection;
    }
}

if (!function_exists('Botify\\base_path')) {
    /**
     * Resolve base path
     *
     * @param string $path
     * @return string
     */
    function base_path(string $path = ''): string
    {
        return abs_path(rtrim(__BASE_DIR__, '/') . '/' . ltrim($path, '/'));
    }
}

if (!function_exists('Botify\\storage_path')) {
    /**
     * Resolve storage path
     *
     * @param string $path
     * @return string
     */
    function storage_path(string $path = ''): string
    {
        return base_path('/storage/' . trim($path, '/'));
    }
}

if (!function_exists('Botify\\config_path')) {
    function config_path($path = ''): string
    {
        return base_path('/config/' . trim($path, '/'));
    }
}

if (!function_exists('Botify\\static_path')) {
    function static_path($path = ''): string
    {
        return config('app.static_folder', base_path('static')) . '/' . trim($path, '/');
    }
}

if (!function_exists('Botify\\plugin_path')) {
    function plugin_path($path = ''): string
    {
        return config('telegram.plugins_dir') . '/' . trim($path, '/');
    }
}

if (!function_exists('Botify\\env')) {
    function env($key, $default = null)
    {
        return ($value = getenv($key)) ? value(function () use ($value) {
            return match (strtolower($value)) {
                'true', '(true)' => true,
                'false', '(false)' => false,
                'empty', '(empty)' => '',
                'null', '(null)' => null,
                default => $value
            };
        }) : value($default);
    }
}

if (!function_exists('Botify\\value')) {
    function value($value, ...$args)
    {
        return $value instanceof Closure ? $value(... $args) : $value;
    }
}

if (!function_exists('Botify\\array_exists')) {

    function array_exists($array, $key): bool
    {
        if ($array instanceof ArrayAccess) {
            return $array->offsetExists($key);
        }

        return array_key_exists($key, $array);
    }
}

if (!function_exists('Botify\\array_accessible')) {

    function array_accessible($value): bool
    {
        return is_array($value) || $value instanceof ArrayAccess;
    }
}

if (!function_exists('Botify\\array_collapse')) {

    function array_collapse(iterable $array): array
    {
        $results = [];

        foreach ($array as $values) {
            if (!is_array($values)) {
                continue;
            }

            $results[] = $values;
        }

        return array_merge([], ...$results);
    }
}

if (!function_exists('Botify\\data_get')) {

    function data_get($target, $key, $default = null)
    {
        if (is_null($key)) {
            return value($default ?? $target);
        }

        $key = is_array($key) ? $key : explode('.', $key);

        foreach ($key as $i => $segment) {
            unset($key[$i]);

            if (is_null($segment)) {
                return $target;
            }

            if ($segment === '*') {
                if (!is_array($target)) {
                    return value($default);
                }

                $result = [];

                foreach ($target as $item) {
                    $result[] = data_get($item, $key);
                }

                return in_array('*', $key) ? array_collapse($result) : $result;
            }

            if (array_accessible($target) && array_exists($target, $segment)) {
                $target = $target[$segment];
            } elseif (is_object($target) && isset($target->{$segment})) {
                $target = $target->{$segment};
            } else {
                return value($default);
            }
        }

        return $target;
    }
}

if (!function_exists('Botify\\array_set')) {

    function array_set(array &$array, ?string $key, $value): array
    {
        if (is_null($key)) {
            return $array = $value;
        }

        $keys = explode('.', $key);

        foreach ($keys as $i => $key) {
            if (count($keys) === 1) {
                break;
            }

            unset($keys[$i]);

            if (!isset($array[$key]) || !is_array($array[$key])) {
                $array[$key] = [];
            }

            $array = &$array[$key];
        }

        $array[array_shift($keys)] = $value;

        return $array;
    }
}

if (!function_exists('Botify\\data_set')) {
    function data_set(&$target, $key, $value, bool $overwrite = true)
    {
        $segments = is_array($key) ? $key : explode('.', $key);

        if (($segment = array_shift($segments)) === '*') {
            if (!array_accessible($target)) {
                $target = [];
            }

            if ($segments) {
                foreach ($target as &$inner) {
                    data_set($inner, $segments, $value, $overwrite);
                }
            } elseif ($overwrite) {
                foreach ($target as &$inner) {
                    $inner = $value;
                }
            }
        } elseif (array_accessible($target)) {
            if ($segments) {
                if (!array_exists($target, $segment)) {
                    $target[$segment] = [];
                }

                data_set($target[$segment], $segments, $value, $overwrite);
            } elseif ($overwrite || !array_exists($target, $segment)) {
                $target[$segment] = $value;
            }
        } elseif (is_object($target)) {
            if ($segments) {
                if (!isset($target->{$segment})) {
                    $target->{$segment} = [];
                }

                data_set($target->{$segment}, $segments, $value, $overwrite);
            } elseif ($overwrite || !isset($target->{$segment})) {
                $target->{$segment} = $value;
            }
        } else {
            $target = [];

            if ($segments) {
                data_set($target[$segment], $segments, $value, $overwrite);
            } elseif ($overwrite) {
                $target[$segment] = $value;
            }
        }

        return $target;
    }
}

if (!function_exists('Botify\\config')) {

    function config($key = null, $default = null)
    {
        static $config = null;
        $config ??= Config::make();

        if (is_null($key)) {
            return $config;
        }

        if (is_array($key)) {
            return $config->set($key);
        }

        return $config->get($key, $default);
    }
}

if (!function_exists('Botify\\abs_path')) {
    /**
     * Get absolute path of a path
     * Unlike the realpath function, the output will not be false if it does not exist
     *
     * @param string $path
     * @return string
     */
    function abs_path(string $path): string
    {
        $segments = explode(DIRECTORY_SEPARATOR, $path);
        $absolutes = [];

        foreach ($segments as $segment) {
            if ('.' === $segment) continue;

            elseif ('..' === $segment) {
                array_pop($absolutes);
            } else {
                $absolutes[] = $segment;
            }
        }

        return implode(DIRECTORY_SEPARATOR, $absolutes);
    }
}

if (!function_exists('Botify\\sprintln')) {
    function sprintln(...$vars): string
    {
        return implode(PHP_EOL, $vars) . PHP_EOL;
    }
}

if (!function_exists('Botify\\println')) {
    function println(...$vars)
    {
        echo sprintln(... $vars);
    }
}

if (!function_exists('Botify\\is_json')) {
    function is_json($value): bool
    {
        return is_string($value) && is_array(json_decode($value, true));
    }
}

if (!function_exists('Botify\\array_some')) {
    function array_some(array $array, ?callable $fn = null): bool
    {
        foreach ($array as $index => $item) {
            if (is_callable($fn) ? $fn($item, $index) : $item) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('Botify\\array_every')) {
    function array_every(array $array, ?callable $fn = null): bool
    {
        foreach ($array as $index => $item) {
            if (!(is_callable($fn) ? $fn($item, $index) : $item)) {
                return false;
            }
        }

        return true;
    }
}

if (!function_exists('Botify\\keyboard')) {
    /**
     * @param null $key
     * @param mixed ...$args
     * @return mixed
     */
    function keyboard($key = null, ...$args): mixed
    {
        return ReplyMarkup::generate($key, ... $args);
    }
}

if (!function_exists('Botify\\array_first')) {
    /**
     * Getting first element of array
     * @param array $array
     * @param callable|null $fn
     * @param mixed|null $default
     * @return mixed
     * @example array_first(some_function_returns_array());
     */
    function array_first(array $array, callable $fn = null, mixed $default = null): mixed
    {

        if (is_null($fn)) {
            return empty($array) ? value($default) : reset($array);
        }

        foreach ($array as $key => $value) {
            if ($fn($value, $key)) {
                return $value;
            }
        }

        return value($default);
    }
}

if (!function_exists('Botify\\array_last')) {
    /**
     * Getting last element of array
     * @param array $array
     * @param callable|null $fn
     * @param mixed|null $default
     * @return mixed
     * @example array_last(some_function_returns_array());
     */
    function array_last(array $array, callable $fn = null, mixed $default = null): mixed
    {
        if (is_null($fn)) {
            return empty($array) ? value($default) : end($array);
        }

        return array_first(array_reverse($array, false), $fn, $default);
    }
}

if (!function_exists('Botify\\concat')) {
    function concat(...$vars): string
    {
        return trim(implode($vars));
    }
}

if (!function_exists('Botify\\str_splice')) {
    function str_splice($haystack, ?int $offset, ?int $length, ?string $replacement = ''): string
    {
        $search = substr($haystack, $offset, $length);

        return implode($replacement, explode($search, $haystack, 2));
    }
}

if (!function_exists('Botify\\mb_str_splice')) {
    function mb_str_splice($haystack, ?int $offset, ?int $length, ?string $replacement = '', ?string $encoding = null): string
    {
        $search = mb_substr($haystack, $offset, $length, $encoding);

        return implode($replacement, explode($search, $haystack, 2));
    }
}

if (!function_exists('Botify\\array_map_recursive')) {
    /**
     * Recursive map into an array
     *
     * @param callable $callback
     * @param $array
     * @return array
     */
    function array_map_recursive(callable $callback, $array): array
    {
        $fn = function ($item) use (&$fn, &$callback) {
            return is_array($item) ? array_map($fn, $item) : $callback($item);
        };

        return array_map($fn, $array);
    }
}

if (!function_exists('Botify\\dotty')) {
    function dotty(array $items = [], bool $parse = false): Dotty
    {
        return new Dotty($items, $parse);
    }
}

if (!function_exists('Botify\\str_snake')) {
    /**
     * Convert string to snake_case
     *
     * @param string $string
     * @return string
     */
    function str_snake(string $string): string
    {
        if (!ctype_lower($string)) {
            return strtolower(preg_replace(
                '/(.)(?=[A-Z])/u', '$1_', preg_replace(
                    '/\s+/u', '', ucwords($string)
                )
            ));
        }

        return $string;
    }
}

if (!function_exists('Botify\\array_sole')) {
    /**
     * @param array $array
     * @param callable $fn
     * @return mixed
     */
    function array_sole(array $array, callable $fn): mixed
    {
        foreach ($array as $index => $item) {
            if ($value = $fn($item, $index)) {
                return $value;
            }
        }

        return false;
    }
}

if (! function_exists('Botify\\escape_markdown')) {
    /**
     * Escape markdown special chars
     *
     * @param $text
     * @return string
     */
    function escape_markdown($text): string
    {
        return str_replace([
            '\\', '-', '#', '*', '+', '`', '.', '[', ']', '(', ')', '!', '&', '<', '>', '_', '{', '}',
        ], [
            '\\\\', '\-', '\#', '\*', '\+', '\`', '\.', '\[', '\]', '\(', '\)', '\!', '\&', '\<', '\>', '\_', '\{', '\}',
        ], $text);
    }
}

if (! function_exists('Botify\\split_keys')) {
    /**
     * Separation of an array into two parts of string and numeric keys
     *
     * @param array $source
     * @return array
     */
    function split_keys(array $source): array
    {
        $integerIndexes = array_filter($source, 'is_int', ARRAY_FILTER_USE_KEY);

        return [array_diff($source, $integerIndexes), array_values($integerIndexes)];
    }
}

if (! function_exists('Botify\\format_bytes')) {
    function format_bytes($bytes, $precision = 2): string
    {
        $units = array('B', 'KB', 'MB', 'GB', 'TB');

        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);

        return round($bytes, $precision) . ' ' . $units[$pow];
    }
}
if (! function_exists('Botify\\memory_usage')) {
    function memory_usage(bool $real_usage = false): string
    {
        return format_bytes(memory_get_usage($real_usage));
    }
}