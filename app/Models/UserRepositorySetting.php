<?php

namespace App\Models;

use Database\Factories\UserRepositorySettingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserRepositorySetting extends Model
{
    /** @use HasFactory<UserRepositorySettingFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<ClaudeAccount, $this>
     */
    public function claudeAccount(): BelongsTo
    {
        return $this->belongsTo(ClaudeAccount::class);
    }
}
