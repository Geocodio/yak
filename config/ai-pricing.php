<?php

/*
 * Per-model pricing used to compute cost_usd for AI SDK calls.
 *
 * Rates are in USD per million tokens. Anthropic changes prices rarely —
 * keep this map in sync with https://www.anthropic.com/pricing.
 * Unknown models are logged and recorded with cost_usd = 0.
 */

return [
    'providers' => [
        'anthropic' => [
            'claude-haiku-4-5-20251001' => [
                'input' => 1.00,
                'output' => 5.00,
                'cache_write' => 1.25,
                'cache_read' => 0.10,
            ],
            'claude-sonnet-4-6' => [
                'input' => 3.00,
                'output' => 15.00,
                'cache_write' => 3.75,
                'cache_read' => 0.30,
            ],
            'claude-sonnet-5-5' => [
                'input' => 2.00,
                'output' => 10.00,
                'cache_write' => 2.50,
                'cache_read' => 0.20,
            ],
            'claude-opus-4-6' => [
                'input' => 5.00,
                'output' => 25.00,
                'cache_write' => 6.25,
                'cache_read' => 0.50,
            ],
            'claude-opus-5-5' => [
                'input' => 4.00,
                'output' => 20.00,
                'cache_write' => 5.00,
                'cache_read' => 0.20,
            ],
            'claude-fable-5-1' => [
                'input' => 10.00,
                'output' => 50.00,
                'cache_write' => 12.50,
                'cache_read' => 0.25,
            ],
        ],
    ],
];
