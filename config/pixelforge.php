<?php

/*
|--------------------------------------------------------------------------
| Pixelforge
|--------------------------------------------------------------------------
|
| Connection settings plus the published pricing tables from
| https://pixelforge.promptedit.dev/docs#pricing (also GET /v1/pricing).
|
| The rates here are only used for the up-front *estimate*. Actual costs
| always use the `rates` object Pixelforge returns on each generation,
| because rates are frozen when the generation is created.
|
*/

return [

    'base_url' => env('PIXELFORGE_BASE_URL', 'https://pixelforge.promptedit.dev/v1'),
    'api_key' => env('PIXELFORGE_API_KEY'),
    'webhook_secret' => env('PIXELFORGE_WEBHOOK_SECRET'),

    // Reject webhook signatures older/newer than this many seconds.
    'webhook_tolerance' => 300,

    // What we charge users on top of Pixelforge's cost, in percent.
    'markup_percent' => 25,

    // USD per 1,000,000 tokens, as exact decimal strings.
    'rates' => [
        'input_text_tokens' => '5.00',
        'input_image_tokens' => '10.00',
        'output_tokens' => '40.00',
        'reasoning_tokens' => '12.50',
    ],

    'sizes' => ['1024x1024', '1536x1024', '1024x1536'],
    'qualities' => ['low', 'medium', 'high'],
    'max_prompt_length' => 4000,
    'max_reference_images' => 4,

    'tokens' => [
        'characters_per_text_token' => 4,
        // Actual text tokens are within ±15% of ceil(chars / 4).
        'text_token_variance_percent' => 15,
        'per_reference_image' => 765,

        // Exact, known up front.
        'output' => [
            'low' => ['1024x1024' => 280, '1536x1024' => 420, '1024x1536' => 420],
            'medium' => ['1024x1024' => 1100, '1536x1024' => 1650, '1024x1536' => 1650],
            'high' => ['1024x1024' => 4300, '1536x1024' => 6450, '1024x1536' => 6450],
        ],

        // Only known after completion.
        'reasoning' => [
            'low' => ['min' => 0, 'max' => 500],
            'medium' => ['min' => 200, 'max' => 2000],
            'high' => ['min' => 1000, 'max' => 8000],
        ],
    ],

];
