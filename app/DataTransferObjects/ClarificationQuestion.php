<?php

namespace App\DataTransferObjects;

/**
 * One question Yak asks before it can continue. Every question also accepts
 * a free-text answer, so the options never include an "Other" entry.
 */
final readonly class ClarificationQuestion
{
    public const MAX_OPTIONS = 4;

    /**
     * @param  list<array{label: string, description: string}>  $options
     */
    public function __construct(
        public string $id,
        public string $header,
        public string $question,
        public array $options,
        public bool $multiSelect = false,
    ) {}

    /**
     * Build a question from decoded agent or stored JSON, or null when the
     * data cannot be shown as a question.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): ?self
    {
        $id = trim((string) ($data['id'] ?? ''));
        $question = trim((string) ($data['question'] ?? ''));
        $header = trim((string) ($data['header'] ?? ''));

        $options = collect(is_array($data['options'] ?? null) ? $data['options'] : [])
            ->filter(fn (mixed $option): bool => is_array($option) && trim((string) ($option['label'] ?? '')) !== '')
            ->map(fn (array $option): array => [
                'label' => trim((string) $option['label']),
                'description' => trim((string) ($option['description'] ?? '')),
            ])
            ->unique('label')
            ->take(self::MAX_OPTIONS)
            ->values()
            ->all();

        if ($id === '' || $question === '' || count($options) < 2) {
            return null;
        }

        return new self(
            id: $id,
            header: $header !== '' ? $header : $id,
            question: $question,
            options: $options,
            multiSelect: ($data['multi_select'] ?? false) === true,
        );
    }

    /**
     * @return list<string>
     */
    public function labels(): array
    {
        return array_column($this->options, 'label');
    }

    /**
     * @return array{id: string, header: string, question: string, options: list<array{label: string, description: string}>, multi_select: bool}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'header' => $this->header,
            'question' => $this->question,
            'options' => $this->options,
            'multi_select' => $this->multiSelect,
        ];
    }
}
