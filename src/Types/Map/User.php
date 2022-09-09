<?php

namespace Botify\Types\Map;

use Amp\Producer;
use Amp\Promise;
use Botify\Traits\Actionable;
use Botify\Traits\Notifiable;
use Botify\Utils\LazyJsonMapper;
use function Amp\call;
use function Botify\collect;
use function Botify\concat;
use function Botify\config;
use function Botify\escape_markdown;
use function Botify\gather;
use function Botify\value;

/**
 * User
 *
 * @method Int getIsAdmin()
 * @method Int getIsSuperAdmin()
 * @method Int getId()
 * @method bool getIsBot()
 * @method bool getIsSelf()
 * @method string getFirstName()
 * @method string getFullName()
 * @method string getLastName()
 * @method string getUsername()
 * @method string getLanguageCode()
 * @method string getMention()
 * @method bool getCanJoinGroups()
 * @method bool getCanReadAllGroupMessages()
 * @method bool getSupportsInlineQueries()
 *
 * @method bool isIsAdmin()
 * @method bool isIsSuperAdmin()
 * @method bool isId()
 * @method bool isIsBot()
 * @method bool isIsSelf()
 * @method bool isFirstName()
 * @method bool isFullName()
 * @method bool isLastName()
 * @method bool isUsername()
 * @method bool isLanguageCode()
 * @method bool isMention()
 * @method bool isCanJoinGroups()
 * @method bool isCanReadAllGroupMessages()
 * @method bool isSupportsInlineQueries()
 *
 * @method $this setIsAdmin(int $value)
 * @method $this setIsSuperAdmin(int $value)
 * @method $this setId(int $value)
 * @method $this setIsBot(bool $value)
 * @method $this setIsSelf(bool $value)
 * @method $this setFirstName(string $value)
 * @method $this setFullName(string $value)
 * @method $this setLastName(string $value)
 * @method $this setUsername(string $value)
 * @method $this setLanguageCode(string $value)
 * @method $this setMention(string $value)
 * @method $this setCanJoinGroups(bool $value)
 * @method $this setCanReadAllGroupMessages(bool $value)
 * @method $this setSupportsInlineQueries(bool $value)
 *
 * @method $this unsetIsAdmin()
 * @method $this unsetIsSuperAdmin()
 * @method $this unsetId()
 * @method $this unsetIsBot()
 * @method $this unsetIsSelf()
 * @method $this unsetFirstName()
 * @method $this unsetFullName()
 * @method $this unsetLastName()
 * @method $this unsetUsername()
 * @method $this unsetLanguageCode()
 * @method $this unsetMention()
 * @method $this unsetCanJoinGroups()
 * @method $this unsetCanReadAllGroupMessages()
 * @method $this unsetSupportsInlineQueries()
 *
 * @property bool $is_admin
 * @property bool $is_super_admin
 * @property Int $id
 * @property bool $is_bot
 * @property bool $is_self
 * @property string $first_name
 * @property string $full_name
 * @property string $last_name
 * @property string $username
 * @property string $language_code
 * @property string $mention
 * @property bool $can_join_groups
 * @property bool $can_read_all_group_messages
 * @property bool $supports_inline_queries
 */
class User extends LazyJsonMapper
{
    use Actionable, Notifiable;

    const JSON_PROPERTY_MAP = [
        Chat::class,
        'id' => 'int',
        'is_bot' => 'bool',
        'first_name' => 'string',
        'full_name' => 'string',
        'last_name' => 'string',
        'mention' => 'string',
        'username' => 'string',
        'language_code' => 'string',
        'can_join_groups' => 'bool',
        'can_read_all_group_messages' => 'bool',
        'supports_inline_queries' => 'bool',
        'is_super_admin' => 'bool',
        'is_admin' => 'bool',
        'is_self' => 'bool',
    ];

    public function _init()
    {
        parent::_init();

        $this->_setProperty('is_admin', in_array(
            $this->id, config('telegram.admins', []),
        ));

        $this->_setProperty(
            'is_super_admin', $this->getId() === (int)config('telegram.super_admin')
        );

        $this->_setProperty(
            'is_self', $this->getId() === config('telegram.bot_user_id')
        );

        if (config('telegram.cache_peers', true) && $username = $this->getUsername()) {
            $this->getAPI()->getRedis()?->getMap('users')->setValue(strtolower($username), $this->getId());
        }

        $this->_setProperty('full_name', concat($this->getFirstName(), ' ', $this->getLastName()));

        $this->_setProperty('mention', match (strtolower(config('telegram.parse_mode', 'html'))) {
            'html' => value(function () {
                return '<a href="tg://user?id=' . $this->id . '">' . htmlspecialchars($this->getFullName()) . '</a>';
            }),
            'markdown' => value(function () {
                return '[' . escape_markdown($this->getFullName()) . '](tg://user?id=' . $this->getId() . ')';
            }),
            default => $this->isUsername() ? concat('@', $this->getUsername()) : '',
        });
    }

    /**
     * Downloading current user profile photos
     *
     * @param int $offset
     * @param int $limit
     * @return Producer
     */
    public function downloadProfilePhotos(int $offset = 0, int $limit = 10): Producer
    {
        return $this->getProfilePhotos($offset, $limit, true);
    }

    /**
     * @param int $offset
     * @param int $limit
     * @param bool $download
     * @return Producer
     */
    public function getProfilePhotos(int $offset = 0, int $limit = 10, bool $download = false): Producer
    {
        return new Producer(function (callable $emit) use ($offset, $download, $limit) {
            $current = 0;
            $total = abs($limit) ?: (1 << 31) - 1;
            $limit = min(100, $total);

            while (true) {
                if (($chunk = yield $this->getChunk($offset, $limit)) && [$totalCount, $photos] = $chunk) {
                    $offset += count($photos);

                    if ($download === true) {
                        $photos = yield gather($photos->map(fn($photos) => end($photos)->download())->toArray());
                    }

                    foreach ($photos as $photo) {
                        yield $emit($photo);

                        $current++;

                        if ($current >= $totalCount || $current >= $total) {
                            return;
                        }
                    }
                } else {
                    return;
                }
            }
        });
    }

    private function getChunk($offset, $limit): Promise
    {
        return call(function () use ($limit, $offset) {
            $profile = yield $this->getAPI()->getUserProfilePhotos([
                'user_id' => $this->id,
                'offset' => $offset,
                'limit' => $limit,
            ]);

            if ($profile->isSuccess() && $totalCount = $profile->total_count) {
                return [$totalCount, collect($profile->photos)];
            }

            return false;
        });
    }

    private function getNotifiableId()
    {
        return $this->id;
    }
}