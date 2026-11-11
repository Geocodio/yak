<?php

use App\Providers\AppServiceProvider;
use App\Providers\ChannelServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\ThreadSandboxServiceProvider;

return [
    AppServiceProvider::class,
    ChannelServiceProvider::class,
    FortifyServiceProvider::class,
    ThreadSandboxServiceProvider::class,
];
