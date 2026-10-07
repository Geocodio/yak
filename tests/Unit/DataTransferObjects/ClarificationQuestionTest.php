<?php

use App\DataTransferObjects\ClarificationQuestion;

it('builds a question from valid data', function () {
    $question = ClarificationQuestion::fromArray([
        'id' => 'payg_triggers',
        'header' => 'PAYG trigger',
        'question' => 'Fire only on rejection?',
        'options' => [
            ['label' => 'Only on rejection', 'description' => 'No card: free tier used up.'],
            ['label' => 'Also at 0', 'description' => 'Keep the issue trigger.'],
        ],
        'multi_select' => true,
    ]);

    expect($question)->not->toBeNull()
        ->and($question->id)->toBe('payg_triggers')
        ->and($question->multiSelect)->toBeTrue()
        ->and($question->toArray()['options'][1]['label'])->toBe('Also at 0');
});

it('rejects a question with fewer than two options', function () {
    expect(ClarificationQuestion::fromArray([
        'id' => 'one', 'header' => 'One', 'question' => 'Only one?',
        'options' => [['label' => 'Yes', 'description' => '']],
    ]))->toBeNull();
});

it('rejects a question without question text or id', function (array $data) {
    expect(ClarificationQuestion::fromArray($data))->toBeNull();
})->with([
    'no text' => [['id' => 'a', 'header' => 'A', 'question' => ' ', 'options' => [['label' => 'x'], ['label' => 'y']]]],
    'no id' => [['id' => '', 'header' => 'A', 'question' => 'Q?', 'options' => [['label' => 'x'], ['label' => 'y']]]],
]);

it('keeps at most four options, drops blank labels and defaults descriptions', function () {
    $question = ClarificationQuestion::fromArray([
        'id' => 'many', 'header' => 'Many', 'question' => 'Pick one',
        'options' => [['label' => 'a'], ['label' => ''], ['label' => 'b'], ['label' => 'c'], ['label' => 'd'], ['label' => 'e']],
    ]);

    expect(array_column($question->options, 'label'))->toBe(['a', 'b', 'c', 'd'])
        ->and($question->options[0]['description'])->toBe('')
        ->and($question->multiSelect)->toBeFalse();
});

it('falls back to the id as header', function () {
    $question = ClarificationQuestion::fromArray([
        'id' => 'demo_accounts', 'question' => 'Demo?', 'options' => [['label' => 'x'], ['label' => 'y']],
    ]);

    expect($question->header)->toBe('demo_accounts');
});

it('drops a question or option whose text field is not a scalar', function () {
    $options = [['label' => 'Small', 'description' => ['nested']], ['label' => 'Large'], ['label' => (object) []]];

    expect(ClarificationQuestion::fromArray(['id' => ['x'], 'question' => 'Which?', 'options' => $options]))->toBeNull()
        ->and(ClarificationQuestion::fromArray(['id' => 'scope', 'question' => 'Which?', 'header' => ['h'], 'options' => $options])?->toArray())
        ->toBe(['id' => 'scope', 'header' => 'scope', 'question' => 'Which?', 'options' => [['label' => 'Small', 'description' => ''], ['label' => 'Large', 'description' => '']], 'multi_select' => false]);
});
