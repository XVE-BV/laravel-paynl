<?php

return [
    /*
     * Credentials from the Pay. dashboard: the token code (AT-####-####), the API
     * token next to it and the sales location (SL-####-####).
     */
    'token_code' => env('PAYNL_TOKEN_CODE'),
    'api_token' => env('PAYNL_API_TOKEN'),
    'service_id' => env('PAYNL_SERVICE_ID'),

    'testmode' => (bool) env('PAYNL_TESTMODE', false),

    /*
     * Sent along with every order so Pay. can recognise the integration.
     * `object` carries the partner/integration code Pay. hands out on certification
     * (use TEST_PARTNER_VALUE during the certification run). `tool` and `extra1`
     * are free fields, searchable in the Pay. admin panel.
     */
    'stats' => [
        'object' => env('PAYNL_STATS_OBJECT', 'xve/laravel-paynl'),
        'tool' => env('PAYNL_STATS_TOOL', env('APP_NAME')),
        'extra1' => env('PAYNL_STATS_EXTRA1'),
    ],

    /*
     * The exchange (webhook) Pay. calls on every status change. It must stay
     * reachable without auth; the payload is verified against the Pay. API.
     */
    'exchange' => [
        'enabled' => true,
        'path' => 'paynl/exchange',
        'middleware' => ['api'],
    ],
];
