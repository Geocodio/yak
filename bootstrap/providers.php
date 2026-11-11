<?php

use App\Providers\AppServiceProvider;
use App\Providers\ChannelServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\StackServiceProvider;
use App\Providers\ThreadSandboxServiceProvider;

return [
    AppServiceProvider::class,
    ChannelServiceProvider::class,
    FortifyServiceProvider::class,
    StackServiceProvider::class,
    ThreadSandboxServiceProvider::class,
];
