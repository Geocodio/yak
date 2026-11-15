<?php

namespace App\Http\Requests\Api\App;

use App\Http\Requests\Threads\OpenThreadPullRequestRequest;

/**
 * Same body and rights as the web request: only `acknowledged_private_publish`, and only a person who can drive the thread.
 */
class OpenAppPullRequestRequest extends OpenThreadPullRequestRequest {}
