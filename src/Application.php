<?php

namespace Botify;

use Dotenv\Dotenv;
use Dotenv\Repository\Adapter\EnvConstAdapter;
use Dotenv\Repository\Adapter\PutenvAdapter;
use Dotenv\Repository\RepositoryBuilder;

class Application
{
    public const VERSION = 'v1.0.7';

    public function __construct()
    {
        $this->bootDotEnv();
        $this->setTimezone();
    }

    public function setTimezone()
    {
        date_default_timezone_set(config('app.timezone'));
    }

    public function bootDotEnv()
    {
        $repository = RepositoryBuilder::createWithNoAdapters()
            ->addAdapter(EnvConstAdapter::class)
            ->addWriter(PutenvAdapter::class)
            ->immutable()
            ->make();

        $dotenv = Dotenv::create($repository, __BASE_DIR__, ['.env']);
        $dotenv->load();
        $dotenv->ifPresent('BOT_TOKEN')->allowedRegexValues('/^\d{6,12}\:[[:alnum:]\-_]{35}$/');
    }
}