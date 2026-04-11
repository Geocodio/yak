<?php

namespace App\DataTransferObjects;

use App\Models\Repository;
use Illuminate\Support\Collection;

readonly class RepoDetectionResult
{
    /**
     * @param  Collection<int, Repository>  $repositories
     * @param  Collection<int, Repository>  $options
     */
    private function __construct(
        public bool $resolved,
        public bool $needsClarification,
        public Collection $repositories,
        public Collection $options,
    ) {}

    /**
     * @param  list<Repository>  $repositories
     */
    public static function resolved(array $repositories): self
    {
        return new self(
            resolved: true,
            needsClarification: false,
            repositories: collect($repositories),
            options: collect(),
        );
    }

    /**
     * @param  list<Repository>  $options
     */
    public static function needsClarification(array $options): self
    {
        return new self(
            resolved: false,
            needsClarification: true,
            repositories: collect(),
            options: collect($options),
        );
    }

    public static function unresolved(): self
    {
        return new self(
            resolved: false,
            needsClarification: false,
            repositories: collect(),
            options: collect(),
        );
    }

    public function firstRepository(): Repository
    {
        /** @var Repository */
        return $this->repositories->firstOrFail();
    }

    public function isSingleRepo(): bool
    {
        return $this->resolved && $this->repositories->count() === 1;
    }

    public function isMultiRepo(): bool
    {
        return $this->resolved && $this->repositories->count() > 1;
    }
}
