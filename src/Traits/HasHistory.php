<?php

namespace Botify\Traits;

use Amp\Producer;
use Amp\Promise;

trait HasHistory
{

    public function getHistory(callable $filter = null, int $limit = 100): Promise
    {
        return $this->getAPI()->getHistory(
            $this->id, $filter, $limit
        );
    }
}