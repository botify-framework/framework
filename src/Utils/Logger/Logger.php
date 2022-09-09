<?php

namespace Botify\Utils\Logger;

use Amp\Promise;
use Botify\Utils\Logger\Colorize\Colorize;
use ErrorException;
use Exception;
use Psr\Log\{AbstractLogger, LoggerTrait, LogLevel};
use Throwable;
use function Amp\{ByteStream\getOutputBufferStream,
    call,
    coroutine,
    File\createDirectoryRecursively,
    File\getSize,
    File\isDirectory,
    File\isFile,
    File\openFile};
use function Botify\{array_some, base_path, config, env, gather, sprintln};

class Logger extends AbstractLogger
{
    use LoggerTrait;

    /**
     * Logger types
     */
    const ECHO_TYPE = 1;
    const FILE_TYPE = 2;
    const DEFAULT_TYPE = self::ECHO_TYPE;

    protected static array $levels = [
        LogLevel::DEBUG => 0,
        LogLevel::INFO => 1,
        LogLevel::NOTICE => 2,
        LogLevel::WARNING => 3,
        LogLevel::ERROR => 4,
        LogLevel::CRITICAL => 5,
        LogLevel::ALERT => 6,
        LogLevel::EMERGENCY => 7,
    ];

    protected array $excepts = [
        'stream_socket_accept(): Accept failed: Connection timed out',
    ];

    protected int $minLevel;

    protected int $type = self::DEFAULT_TYPE;

    protected string $logFile;
    protected int $maxSize;
    private array $handlers = [];
    private string $name = 'botify';

    /**
     * @throws Exception
     */
    public function __construct(int $level = 0, $type = self::DEFAULT_TYPE)
    {
        $minLevel = !is_null($level) ? $level : match ((int)env('SHELL_VERBOSITY')) {
            -1 => static::$levels[LogLevel::ERROR],
            1 => static::$levels[LogLevel::NOTICE],
            2 => static::$levels[LogLevel::INFO],
            3 => static::$levels[LogLevel::DEBUG]
        };

        if (!isset(array_flip(static::$levels)[$minLevel])) {
            throw new Exception(sprintf(
                'There is no logger level [%s]', $minLevel
            ));
        }

        $this->minLevel = $minLevel;
        $this->type = $type;
        $this->logFile = config('app.logger_file', base_path('botify.log'));
        $this->maxSize = (int)config('app.logger_max_size');

        set_error_handler(function ($code, $message, $file, $line) {
            if (!array_some($this->excepts, fn($error) => str_contains($message, $error))) {
                $this->error(new ErrorException($message, 0, $code, $file, $line));
            }
        });

        set_exception_handler(function ($e) {
            $message = $e->getMessage();

            if (!array_some($this->excepts, fn($error) => str_contains($message, $error))) {
                $this->critical($e);
            }
        });
    }

    public function setName($name)
    {
        $this->name = $name;
    }

    public function log($level, $message, array $context = []): void
    {
        if ((static::$levels[$level] > $this->minLevel) || strtolower(config('app.environment')) === 'production') {
            return;
        }

        $log = $this->interpolate($level, $message, $context);

        if ($this->type & static::ECHO_TYPE) {
            getOutputBufferStream()->write(Colorize::log($level, $log));
        }

        if ($this->type & static::FILE_TYPE) {
            $this->writeLogs($log);
        }

        gather(array_map(function ($handler) use ($level, $message, $context) {
            return $handler($message, static::$levels[$level], $context);
        }, $this->handlers));
    }

    public function interpolate($level, $message, array $context = []): string
    {
        $replace = [];

        if (str_contains($message, '{')) {
            foreach ($context as $key => $value) {
                if ($value instanceof Throwable) {
                    $replace['{' . $key . '}'] = json_encode($this->exceptionToArray($value));
                    unset($context[$key]);
                } else {
                    if (!is_array($value) && (!is_object($value) || method_exists($value, '__toString'))) {
                        $replace['{' . $key . '}'] = $value;
                        unset($context[$key]);
                    } else {
                        if (is_array($value)) {
                            $replace['{' . $key . '}'] = json_encode($value, 448);
                            unset($context[$key]);
                        }
                    }
                }
            }

            $message = strtr($message, $replace);
        }

        return sprintf(
            '[%s] [%s] [%s] %s %s',
            $this->name,
            date('Y/m/d H:i:s'),
            $level,
            $message,
            $context ? sprintln(var_export($context)) : null
        );
    }

    public function exceptionToArray(Throwable $e): array
    {
        return [
            'exception' => get_class($e),
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'code' => $e->getCode(),
            'backtrace' => array_slice($e->getTrace(), 0, 3),
        ];
    }

    private function writeLogs(string $log): Promise
    {
        return call(function () use ($log) {
            $file = yield openFile($logFile = yield $this->getLoggerFile(), 'a+');

            if ($this->maxSize < yield getSize($logFile)) {
                yield $file->write('');
            }

            yield $file->write(sprintln($log));
        });
    }

    private function getLoggerFile(): Promise
    {
        return call(function () {
            if (!yield isDirectory($logsDir = dirname($logFile = $this->logFile))) {
                yield createDirectoryRecursively($logsDir);
            }

            if (!yield isFile($logFile)) {
                \Amp\File\touch($logFile);
            }

            return $logFile;
        });
    }

    public function addHandler(callable $handler)
    {
        $this->handlers[] = coroutine($handler);
    }
}