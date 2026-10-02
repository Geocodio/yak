<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

#[Fillable(['name', 'email', 'password', 'slack_user_id', 'direct_messages_enabled'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /** @var array<string, mixed> */
    protected $attributes = [
        'direct_messages_enabled' => true,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'has_seen_task_detail_intro_at' => 'datetime',
            'has_seen_setup_card_at' => 'datetime',
            'has_seen_pr_review_intro_at' => 'datetime',
            'direct_messages_enabled' => 'boolean',
            'password' => 'hashed',
        ];
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        return Str::of($this->name)
            ->explode(' ')
            ->take(2)
            ->map(fn ($word) => Str::substr($word, 0, 1))
            ->implode('');
    }

    /**
     * The user whose email matches, ignoring case and surrounding spaces. Every
     * miss is logged, because no match means that person gets no direct
     * messages.
     */
    public static function findByEmail(?string $email): ?self
    {
        $normalizedEmail = mb_strtolower(trim((string) $email));

        $user = $normalizedEmail === ''
            ? null
            : self::query()->whereRaw('lower(email) = ?', [$normalizedEmail])->first();

        if ($user === null) {
            Log::channel('yak')->info('No Yak user matches email', ['email' => $email]);
        }

        return $user;
    }
}
