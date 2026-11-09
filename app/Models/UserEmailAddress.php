<?php

namespace App\Models;

use Database\Factories\UserEmailAddressFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An extra address a user sends email from, beyond their sign-in address.
 * It counts as theirs for the email channel only once confirmed.
 *
 * @property int $id
 * @property int $user_id
 * @property string $email
 * @property Carbon|null $confirmed_at
 * @property Carbon|null $confirmation_sent_at
 */
#[Fillable(['user_id', 'email', 'confirmed_at', 'confirmation_sent_at'])]
class UserEmailAddress extends Model
{
    /** @use HasFactory<UserEmailAddressFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'confirmed_at' => 'datetime',
            'confirmation_sent_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isConfirmed(): bool
    {
        return $this->confirmed_at !== null;
    }

    /**
     * @return Attribute<string, string>
     */
    protected function email(): Attribute
    {
        return Attribute::set(fn (string $value): string => self::normalize($value));
    }

    public static function normalize(?string $email): string
    {
        return mb_strtolower(trim((string) $email));
    }
}
