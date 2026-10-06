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
        $id = self::text($data['id'] ?? null);
        $question = self::text($data['question'] ?? null);
        $header = self::text($data['header'] ?? null);

        $options = collect(is_array($data['options'] ?? null) ? $data['options'] : [])
            ->filter(fn (mixed $option): bool => is_array($option) && self::text($option['label'] ?? null) !== '')
            ->map(fn (array $option): array => [
                'label' => self::text($option['label']),
                'description' => self::text($option['description'] ?? null),
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
     * Agent JSON can put an array or object where a string belongs; such a
     * field reads as empty, which drops the question or option it is in.
     */
    private static function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
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
