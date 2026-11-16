<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $session_id
 * @property string $uuid
 * @property ?string $parent_uuid
 * @property int $session_file_id
 * @property int $offset
 */
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
