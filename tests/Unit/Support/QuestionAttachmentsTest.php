<?php

use App\DataTransferObjects\ClarificationQuestion;
use App\Support\QuestionAttachments;

it('finds image references and normalises the file name', function () {
    expect(QuestionAttachments::references('See ![Flow today](.yak-artifacts/shared/flow.png) and ![Chart]( shared/chart.html ) and ![x](./a.png)'))
        ->toBe([
            ['alt' => 'Flow today', 'name' => 'flow.png'],
            ['alt' => 'Chart', 'name' => 'chart.html'],
            ['alt' => 'x', 'name' => 'a.png'],
        ]);
});

it('strips references and tidies the whitespace left behind', function () {
    expect(QuestionAttachments::strip("How should we handle 3DS?\n\n![Flow](flow.png) ![Chart](chart.html)"))
        ->toBe('How should we handle 3DS?');
});

it('ignores links that are not images', function () {
    expect(QuestionAttachments::references('[docs](https://example.com)'))->toBe([]);
});

it('collects unique file names from questions and option previews', function () {
    $question = ClarificationQuestion::fromArray([
        'id' => 'q1', 'header' => 'H', 'question' => 'Q? ![Flow](flow.png)',
        'options' => [
            ['label' => 'A', 'description' => '', 'preview' => '![A](a.html)'],
            ['label' => 'B', 'description' => '', 'preview' => '![Flow again](flow.png)'],
        ],
    ]);

    expect(QuestionAttachments::fileNames([$question]))->toBe(['flow.png', 'a.html']);
});

it('reads a reference that has a title', function () {
    expect(QuestionAttachments::references('![Flow](flow.png "The flow today")'))->toBe([['alt' => 'Flow', 'name' => 'flow.png']])
        ->and(QuestionAttachments::strip('Pick one. ![Flow](flow.png "The flow today")'))->toBe('Pick one.');
});
