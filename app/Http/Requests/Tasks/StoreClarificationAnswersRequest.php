<?php

namespace App\Http\Requests\Tasks;

use App\DataTransferObjects\ClarificationQuestion;
use App\Models\YakTask;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreClarificationAnswersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'answers' => ['required', 'array'],
            'answers.*' => ['array'],
            'answers.*.choices' => ['present', 'array'],
            'answers.*.choices.*' => ['string'],
            'answers.*.other' => ['nullable', 'string', 'max:2000'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<int, \Closure(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $questions = collect($this->head()->pendingClarificationQuestions())->keyBy(fn (ClarificationQuestion $question): string => $question->id);

            if ($questions->isEmpty()) {
                return;
            }

            $answered = 0;

            foreach ((array) $this->input('answers', []) as $id => $answer) {
                $question = $questions->get($id);

                if ($question === null) {
                    $validator->errors()->add('answers', 'An answer does not match any question.');

                    continue;
                }

                $choices = array_values((array) ($answer['choices'] ?? []));

                if (array_diff($choices, $question->labels()) !== []) {
                    $validator->errors()->add("answers.{$id}", 'Pick one of the listed options.');
                }

                if (! $question->multiSelect && count($choices) > 1) {
                    $validator->errors()->add("answers.{$id}", 'Pick only one option.');
                }

                if ($choices !== [] || trim((string) ($answer['other'] ?? '')) !== '') {
                    $answered++;
                }
            }

            if ($answered === 0) {
                $validator->errors()->add('answers', 'Answer at least one question.');
            }
        }];
    }

    /**
     * The task in the conversation that is waiting on answers.
     */
    public function head(): YakTask
    {
        /** @var YakTask $task */
        $task = $this->route('task');

        return $task->conversation()->last() ?? $task;
    }

    /**
     * Answers with blank entries removed, as the submitter stores them.
     *
     * @return array<string, array{choices: list<string>, other: string|null}>
     */
    public function answers(): array
    {
        return collect((array) $this->validated('answers'))
            ->map(fn (array $answer): array => [
                'choices' => array_values((array) ($answer['choices'] ?? [])),
                'other' => trim((string) ($answer['other'] ?? '')) !== '' ? trim((string) $answer['other']) : null,
            ])
            ->filter(fn (array $answer): bool => $answer['choices'] !== [] || $answer['other'] !== null)
            ->all();
    }
}
