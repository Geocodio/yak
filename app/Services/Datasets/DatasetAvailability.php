<?php

namespace App\Services\Datasets;

use App\Enums\DatasetStatus;
use App\Models\Dataset;
use App\Models\DatasetClone;
use App\Models\Repository;
use App\Models\Thread;
use Throwable;

/**
 * Whether the dataset a repository declares can be used on this server, for
 * the thread page, the repository settings and the environment note.
 */
final readonly class DatasetAvailability
{
    public function __construct(
        public DatasetAvailabilityState $state,
        public ?string $name,
        public ?string $version,
    ) {}

    public static function forRepository(Repository $repository): self
    {
        $dataset = Dataset::with('currentVersion')->where('repository_id', $repository->id)->first();

        if ($dataset === null) {
            $config = self::declaredConfig($repository);

            return new self($config === null ? DatasetAvailabilityState::NotDeclared : DatasetAvailabilityState::NotOnThisServer, $config?->name, null);
        }

        if ($dataset->current_version_id !== null) {
            return new self(DatasetAvailabilityState::Ready, $dataset->name, $dataset->currentVersion?->version);
        }

        $state = match ($dataset->status) {
            DatasetStatus::Fetching => DatasetAvailabilityState::Fetching,
            DatasetStatus::Failed => DatasetAvailabilityState::Failed,
            default => DatasetAvailabilityState::NotOnThisServer,
        };

        return new self($state, $dataset->name, null);
    }

    /** Only the primary repository's dataset counts; extra repositories get none. */
    public static function forThread(Thread $thread): self
    {
        $slug = $thread->primaryRepository()->value('repo') ?? $thread->repo;
        $repository = Repository::where('slug', $slug)->first();

        if ($repository === null) {
            return new self(DatasetAvailabilityState::NotDeclared, null, null);
        }

        $availability = self::forRepository($repository);

        if ($availability->state !== DatasetAvailabilityState::Ready) {
            return $availability;
        }

        $isAttached = DatasetClone::where('owner_type', $thread->getMorphClass())
            ->where('owner_id', $thread->getKey())
            ->whereNull('destroyed_at')
            ->exists();

        return $isAttached ? $availability : new self(DatasetAvailabilityState::Unattached, $availability->name, $availability->version);
    }

    public function message(): ?string
    {
        return match ($this->state) {
            DatasetAvailabilityState::NotOnThisServer => 'Dataset not on this server yet. Work that needs it stays on your computer.',
            DatasetAvailabilityState::Fetching => 'Dataset is being fetched on this server. Work that needs it stays on your computer.',
            DatasetAvailabilityState::Failed => 'Dataset fetch failed on this server. Work that needs it stays on your computer.',
            DatasetAvailabilityState::Unattached => 'Dataset not attached to this sandbox.',
            DatasetAvailabilityState::NotDeclared, DatasetAvailabilityState::Ready => null,
        };
    }

    /**
     * @return array{state: string, name: ?string, version: ?string, message: ?string}
     */
    public function toArray(): array
    {
        return ['state' => $this->state->value, 'name' => $this->name, 'version' => $this->version, 'message' => $this->message()];
    }

    private static function declaredConfig(Repository $repository): ?DatasetConfig
    {
        try {
            return DatasetConfig::fromRepository($repository);
        } catch (Throwable) {
            return null;
        }
    }
}
