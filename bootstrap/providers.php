<?php

use App\Providers\AppServiceProvider;
use App\Providers\ChannelServiceProvider;
use App\Providers\E2eServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\MultiRepoServiceProvider;
use App\Providers\PushServiceProvider;
use App\Providers\RefreshServiceProvider;
use App\Providers\StackServiceProvider;
use App\Providers\ThreadSandboxServiceProvider;

return [
    AppServiceProvider::class,
    ChannelServiceProvider::class,
    E2eServiceProvider::class,
    FortifyServiceProvider::class,
    MultiRepoServiceProvider::class,
    PushServiceProvider::class,
    RefreshServiceProvider::class,
    StackServiceProvider::class,
    ThreadSandboxServiceProvider::class,
];
