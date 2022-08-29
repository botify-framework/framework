<?php

namespace Botify\Types\Map;

use Botify\Utils\LazyJsonMapper;

/**
 * @mixin ReplyKeyboardMarkup
 * @mixin KeyboardButton
 * @mixin KeyboardButtonPollType
 * @mixin ReplyKeyboardRemove
 * @mixin InlineKeyboardMarkup
 * @mixin InlineKeyboardButton
 */
class ReplyMarkup extends LazyJsonMapper
{
    const JSON_PROPERTY_MAP = [
        ReplyKeyboardMarkup::class,
        KeyboardButton::class,
        KeyboardButtonPollType::class,
        ReplyKeyboardRemove::class,
        InlineKeyboardMarkup::class,
        InlineKeyboardButton::class,
    ];
}