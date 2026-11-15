<?php

namespace App\Http\Requests\Api\App;

use App\Http\Requests\Threads\ReorderMergeOrderRequest;

/**
 * Same body and rights as the web request: `order` lists at most 50 pull request row ids, and only a person who can drive the thread may save it.
 */
class ReorderAppMergeOrderRequest extends ReorderMergeOrderRequest {}
