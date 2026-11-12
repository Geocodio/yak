<?php

namespace App\Models;

use App\Services\Push\PushEvent;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use NotificationChannels\WebPush\HasPushSubscriptions;

/**
 * @property array<string, bool>|null $push_event_preferences
 */
#[Fillable(['name', 'email', 'password', 'slack_user_id', 'direct_messages_enabled', 'github_id', 'github_login', 'github_linked_at'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasPushSubscriptions, Notifiable;

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
            'is_admin' => 'boolean',
            'github_linked_at' => 'datetime',
            'password' => 'hashed',
            'push_event_preferences' => 'array',
        ];
    }

    /**
     * Whether this person manages Yak-wide settings: the stored flag, or a
     * listed id in YAK_SYSTEM_ACCOUNT_ADMIN_IDS.
     */
    public function isAdmin(): bool
    {
        return $this->is_admin || $this->isAdminByConfig();
    }

    public function isAdminByConfig(): bool
    {
        return in_array($this->id, config('claude-accounts.admin_user_ids'), true);
    }

    /**
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    #[Scope]
    protected function admins(Builder $query): Builder
    {
        return $query->where(fn (Builder $admins) => $admins
            ->where('is_admin', true)
            ->orWhereIn($query->getModel()->getQualifiedKeyName(), config('claude-accounts.admin_user_ids')));
    }

    public function hasLinkedGitHub(): bool
    {
        return $this->github_id !== null;
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

    /**
     * @return HasMany<UserEmailAddress, $this>
     */
    public function emailAddresses(): HasMany
    {
        return $this->hasMany(UserEmailAddress::class);
    }

    /**
     * The user who may send email to Yak from this address: the owner of the
     * sign-in address, or of a confirmed extra address. Case and surrounding
     * spaces are ignored. Unconfirmed extra addresses never match.
     */
    public static function findBySenderEmail(?string $email): ?self
    {
        $normalizedEmail = UserEmailAddress::normalize($email);

        if ($normalizedEmail === '') {
            return null;
        }

        return self::query()->whereRaw('lower(email) = ?', [$normalizedEmail])->first()
            ?? self::query()
                ->whereHas('emailAddresses', fn ($query) => $query->where('email', $normalizedEmail)->whereNotNull('confirmed_at'))
                ->first();
    }

    /**
     * @return HasMany<Device, $this>
     */
    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    /**
     * Enabled push devices only; the Web Push channel sends to each of them.
     *
     * @return Collection<int, PushDevice>
     */
    public function routeNotificationForWebPush(): Collection
    {
        /** @var Collection<int, PushDevice> */
        return $this->pushSubscriptions()->where('is_enabled', true)->get();
    }

    /**
     * Whether this person wants pushes for the event. A missing key means on.
     */
    public function wantsPush(PushEvent $event): bool
    {
        return (bool) ($this->push_event_preferences[$event->value] ?? true);
    }

    /**
     * @param  array<string, bool>  $preferences
     */
    public function setPushPreferences(array $preferences): void
    {
        $known = array_map(fn (PushEvent $event): string => $event->value, PushEvent::cases());

        $this->forceFill([
            'push_event_preferences' => array_map('boolval', array_merge($this->push_event_preferences ?? [], array_intersect_key($preferences, array_flip($known)))),
        ])->save();
    }

    /**
     * @return HasMany<SshKey, $this>
     */
    public function sshKeys(): HasMany
    {
        return $this->hasMany(SshKey::class);
    }

    /**
     * @return HasOne<GithubKeySync, $this>
     */
    public function githubKeySync(): HasOne
    {
        return $this->hasOne(GithubKeySync::class);
    }
}
