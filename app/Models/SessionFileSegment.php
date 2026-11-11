<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $session_file_id
 * @property int $offset
 * @property int $length
 * @property string $sha256
 * @property string $path
 */
class SessionFileSegment extends Model
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
