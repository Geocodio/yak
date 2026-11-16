<?php

namespace App\Models;

use Database\Factories\ThreadStarFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A thread a person starred. Stars are personal: nobody else sees them.
 *
 * @property int $user_id
 * @property int $thread_id
 */
class ThreadStar extends Model
{
    /** @use HasFactory<ThreadStarFactory> */
    use HasFactory;

    protected $guarded = [];
}
