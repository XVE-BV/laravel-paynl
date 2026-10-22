<?php

use Xve\LaravelPaynl\Services\PaynlService;

return [
    /*
     * Credentials from the Pay. dashboard: the token code (AT-####-####), the API
     * token next to it and the sales location (SL-####-####).
     */
    'token_code' => env('PAYNL_TOKEN_CODE'),
    'api_token' => env('PAYNL_API_TOKEN'),
    'service_id' => env('PAYNL_SERVICE_ID'),
    // Only needed when Pay. signs the exchange with the sales location (SL) secret instead of the API token
    'service_secret' => env('PAYNL_SERVICE_SECRET'),

    'testmode' => (bool) env('PAYNL_TESTMODE', false),

    'currency' => 'EUR',

    /*
     * The Pay. core (TGU) for creating orders and asking their status, e.g. https://connect.payments.nl
     * when connect.pay.nl is down. Empty keeps the SDK default (https://connect.pay.nl). Refunds and
     * terminals always go to rest.pay.nl.
     */
    'core' => env('PAYNL_CORE'),

    /*
     * Seconds before a call to Pay. gives up, so a slow Pay. never hangs the kassa or a worker.
     */
    'timeout' => 10,
    'connect_timeout' => 3,

    /*
     * Sent along with every order so Pay. can recognise the integration.
     * `object` is XVE's PartnerID, the same in every project: leave PAYNL_STATS_OBJECT empty.
     * `tool` and `extra1` are free fields, searchable in the Pay. admin panel.
     */
    'stats' => [
        'object' => env('PAYNL_STATS_OBJECT') ?: PaynlService::PARTNER_ID,
        'tool' => env('PAYNL_STATS_TOOL', env('APP_NAME')),
        'extra1' => env('PAYNL_STATS_EXTRA1'),
    ],

    /*
     * The exchange (webhook) Pay. calls on every status change.
     * It must stay reachable without auth, CSRF or rate limiting; every call is verified
     * (HMAC signature, or a status call to Pay.) before it is trusted.
     */
    'exchange' => [
        'enabled' => true,
        'path' => 'paynl/exchange',
        'middleware' => [],
    ],

    /*
     * Terminal payments through TerminalPayments. Pay. asks a kassa to wait 3 seconds before the
     * first status call, then to ask at most once a second. A payment nobody pays within `timeout`
     * seconds is stopped; when Pay. still has no final answer `cancel_grace` seconds later, the
     * kassa stops asking. `book_wait` is how long a booking waits for another one of the same order.
     *
     * A running payment is kept in the cache (`cache_store`, null is the default store) for
     * `remember` seconds: long enough for Pay.'s exchange retries (6 times within 2 hours).
     */
    'terminal' => [
        'first_poll_ms' => 3000,
        'poll_ms' => 1000,
        'timeout' => 180,
        'cancel_grace' => 60,
        'book_wait' => 10,
        'cache_store' => null,
        'remember' => 86400,
    ],
];
