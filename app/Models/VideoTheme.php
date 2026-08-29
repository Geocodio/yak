<?php

namespace App\Models;

use Database\Factories\VideoThemeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The installation-wide walkthrough theme (spec §9). Exactly one row, id 1.
 *
 * @property array<string, mixed> $theme
 * @property string|null $logo_path
 * @property int|null $updated_by
 */
class VideoTheme extends Model
{
    /** @use HasFactory<VideoThemeFactory> */
    use HasFactory;

    protected $fillable = ['theme', 'logo_path', 'updated_by'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['theme' => 'array'];
    }

    /** The one row, created from the spec defaults on first read. */
    public static function current(): self
    {
        return static::firstOrCreate(
            ['id' => 1],
            ['theme' => (array) config('yak.video.theme')],
        );
    }

    /** @return BelongsTo<User, $this> */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
