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
            'claude-opus-4-6' => [
                'input' => 15.00,
                'output' => 75.00,
                'cache_write' => 18.75,
                'cache_read' => 1.50,
            ],
        ],
    ],
];
