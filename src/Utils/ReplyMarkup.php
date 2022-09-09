<?php

namespace Botify\Utils;

use function Botify\{array_last, array_some, config, data_get, split_keys, value};

final class ReplyMarkup
{
    private const ButtonColumn = 10;
    private const InlineButtonColumn = 20;

    const ButtonText = 1;
    const ButtonContact = 2;
    const ButtonLocation = 3;
    const ButtonPoll = 4;
    const ButtonWebApp = 5;

    const ButtonCallback = 1;
    const ButtonUrl = 2;
    const ButtonSwitchQuery = 3;
    const ButtonSwitchQueryCurrentChat = 4;
    const ButtonGame = 5;
    const ButtonPay = 6;
    const ButtonLoginUrl = 6;


    private static array $keyboards = [];

    /**
     * @var array
     */
    private array $defaults = [
        'resize_keyboard' => true,
        'one_time_keyboard' => false,
    ];


    /**
     * Keyboard constructor.
     * @param array $options
     */
    public function __construct(array $options = [])
    {
        $this->defaults = array_merge($this->defaults, $options);
    }

    /**
     * Generate reply markup keyboards from specified files.
     * Access in the form of dot notation.
     *
     * @param string|null $key
     * @param ...$args
     * @return mixed|string|null
     */
    public static function generate(?string $key = null, ...$args): mixed
    {
        self::$keyboards ??= require_once config('telegram.keyboards_path', function () {
            throw new \Exception('You must set keyboards_path key in config/telegram.php');
        });

        if (isset($args['remove']) && $args['remove'] === true) {
            return self::remove();
        }

        $json = $args['json'] ?? true;
        $options = $args['options'] ?? [];
        $default = $args['default'] ?? null;
        unset($args['json'], $args['options'], $args['default']);

        if (is_array($value = value(data_get(self::$keyboards, $key, $default), ... $args))) {
            return ReplyMarkup::make($value, $options, $json);
        }

        return $default;
    }

    /**
     * Get an object for ReplyMarkupRemove
     *
     * @return string
     */
    public static function remove(): string
    {
        return json_encode([
            'remove_keyboard' => true,
        ]);
    }

    /**
     * @param $rows
     * @param array $options
     * @param bool $json
     * @return mixed
     */
    public static function make($rows, array $options = [], bool $json = true): mixed
    {
        $keyboard = new self($options);

        $array = $keyboard->isInlineKeyboard($rows)
            ? $keyboard->inlineKeyboard($rows)
            : $keyboard->keyboard($rows);

        return $json ? json_encode($array) : $array;
    }

    /**
     * @param array $rows
     * @return bool
     */
    private function isInlineKeyboard(array $rows): bool
    {
        return !empty($rows) && array_some($rows[0], fn($item) => isset($item[1]) && is_string($item[1]));
    }

    /**
     * Create inline keyboard button
     *
     * @param array $rows
     * @return array
     */
    public function inlineKeyboard(array $rows): array
    {
        $inline_keyboard = [];

        foreach ($rows as $row)
            $inline_keyboard[] = array_map(fn($column) => self::createColumn($column, self::InlineButtonColumn), $row);

        return array_merge($this->defaults, compact(
            'inline_keyboard'
        ));
    }

    /**
     * Create row column based on the structure of the framework
     *
     * @param $column
     * @param $type
     * @return array
     */
    private static function createColumn($column, $type = self::ButtonColumn): array
    {
        [$a, $b] = split_keys($column);
        $colType = $a['type'] ?? array_last($b);
        unset($a['type']);

        return match ((is_int($colType) ? $colType : 1) ^ $type) {
            self::ButtonText ^ self::ButtonColumn => ['text' => $b[0]] + $a,
            self::ButtonContact ^ self::ButtonColumn => ['text' => $b[0], 'request_contact' => true] + $a,
            self::ButtonLocation ^ self::ButtonColumn => ['text' => $b[0], 'request_location' => true] + $a,
            self::ButtonPoll ^ self::ButtonColumn => ['text' => $b[0], 'request_poll' => $b[1]] + $a,
            self::ButtonWebApp ^ self::ButtonColumn => ['text' => $b[0], 'web_app' => $b[1]] + $a,
            self::ButtonCallback ^ self::InlineButtonColumn => ['text' => $b[0], 'callback_data' => $b[1]] + $a,
            self::ButtonUrl ^ self::InlineButtonColumn => ['text' => $b[0], 'url' => $b[1]] + $a,
            self::ButtonSwitchQuery ^ self::InlineButtonColumn => ['text' => $b[0], 'switch_inline_query' => $b[1]] + $a,
            self::ButtonSwitchQueryCurrentChat & self::InlineButtonColumn => ['text' => $b[0], 'switch_inline_query_current_chat' => $b[1]] + $a,
            self::ButtonGame ^ self::InlineButtonColumn => ['text' => $b[0], 'callback_game' => $b[1]] + $a,
            self::ButtonPay ^ self::InlineButtonColumn => ['text' => $b[0], 'pay' => true],
            self::ButtonLoginUrl ^ self::InlineButtonColumn => ['text' => $b[0], 'login_url' => $b[1]],
            default => [],
        };
    }

    /**
     * Create keyboard button
     *
     * @param array $rows
     * @return array
     */
    public function keyboard(array $rows): array
    {
        $keyboard = [];

        foreach ($rows as $row) {
            $keyboard[] = array_map([self::class, 'createColumn'], $row);
        }

        return array_merge($this->defaults, compact(
            'keyboard'
        ));
    }
}