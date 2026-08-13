<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    | Finančný asistent. Rozhranie je OpenAI-kompatibilné, takže sa dá cez
    | OPENAI_BASE_URL nasmerovať aj na iného poskytovateľa bez zmeny kódu.
    */
    'openai' => [
        'key' => env('OPENAI_API_KEY'),

        // Chat a mesačný komentár. Vybrané testom na skutočnej úlohe: zo
        // všetkých lacných modelov jediný trafil aj správne poradie období aj
        // dnešný dátum, a bol pritom najrýchlejší.
        'model' => env('OPENAI_MODEL', 'gpt-4.1-mini'),

        // Návrh kategórie — vybrať jedno id zo zoznamu. Na to stačí najlacnejší
        // model; odpoveď sa navyše cache-uje na 30 dní.
        'model_fast' => env('OPENAI_MODEL_FAST', 'gpt-4.1-nano'),

        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
        'timeout' => env('OPENAI_TIMEOUT', 120),
    ],

];
