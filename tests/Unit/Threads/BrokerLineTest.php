<?php

use App\Services\Threads\BrokerLine;

it('normalises broker line names', function (array $line, ?string $event) {
    expect(BrokerLine::event($line))->toBe($event);
})->with([
    'event field' => [['type' => 'broker', 'event' => 'runner_exited'], 'runner_exited'],
    'dotted type' => [['type' => 'broker.runner_exited'], 'runner_exited'],
    'broker started' => [['type' => 'broker', 'event' => 'broker_started'], 'broker_started'],
    'unknown broker event' => [['type' => 'broker', 'event' => 'mystery'], null],
    'runner line' => [['type' => 'runner.started'], null],
    'assistant' => [['type' => 'assistant'], null],
]);

it('normalises runner line names', function (array $line, ?string $event) {
    expect(BrokerLine::runnerEvent($line))->toBe($event);
})->with([
    'dotted type' => [['type' => 'runner.started'], 'started'],
    'event field' => [['type' => 'runner', 'event' => 'started'], 'started'],
    'exit after result' => [['type' => 'runner.exit_after_result'], 'exit_after_result'],
    'done' => [['type' => 'runner', 'event' => 'done'], 'done'],
    'unknown runner event' => [['type' => 'runner', 'event' => 'mystery'], null],
    'broker line' => [['type' => 'broker', 'event' => 'runner_exited'], null],
    'assistant' => [['type' => 'assistant'], null],
]);
