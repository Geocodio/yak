<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $slug
 * @property string|null $content
 * @property bool $is_customized
 */
class Prompt extends Model
{
    protected $fillable = [
        'slug',
        'content',
        'is_customized',
    ];

    protected $casts = [
        'is_customized' => 'boolean',
    ];

    /**
     * @return HasMany<PromptVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(PromptVersion::class)->orderByDesc('version');
    }
}
