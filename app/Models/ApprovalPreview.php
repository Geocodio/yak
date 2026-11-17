<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ApprovalPreviewFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The dry-run approval result of one open pull request at one head commit.
 *
 * @property int $pr_number
 * @property string $head_sha
 * @property string $title
 * @property string $author
 * @property string $url
 * @property string|null $error
 * @property string|null $fingerprint
 * @property string|null $concerns_version
 * @property 'pending'|'done'|'failed' $status
 * @property CarbonImmutable|null $updated_at
 * @property array{holds: list<string>, changeKind: ?string, hasTestChange: bool, waiting: bool, classification: array<string, mixed>|null}|null $result
 */
class ApprovalPreview extends Model
{
    /** @use HasFactory<ApprovalPreviewFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['result' => 'array'];
    }

    /**
     * @return BelongsTo<Repository, $this>
     */
    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class);
    }
}
