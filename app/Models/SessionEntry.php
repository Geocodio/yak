<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SessionEntry extends Model
{
    protected $guarded = [];

    /**
     * @return BelongsTo<SessionFile, $this>
     */
    public function sessionFile(): BelongsTo
    {
        return $this->belongsTo(SessionFile::class);
    }
}
