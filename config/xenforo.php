<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default Forum
    |--------------------------------------------------------------------------
    |
    | Which entry under "forums" a call without a name uses: XenForo::users() is
    | XenForo::forum(<this>)->users(). An application talking to one forum never
    | needs to name it.
    |
    */

    'default' => env('XENFORO_FORUM', 'main'),

    /*
    |--------------------------------------------------------------------------
    | Forums
    |--------------------------------------------------------------------------
    |
    | One entry per forum, named however you like -- the name is what
    | XenForo::forum('...') takes. There is no vendor-hosted XenForo endpoint, so
    | "url" has no default and every forum has to be told where it is; the board
    | URL and the /api URL are both accepted.
    |
    | The credential decides what the request may do, and the three shapes are not
    | interchangeable:
    |
    |   key                 a guest or user API key. The key itself decides which
    |                       user the request acts as.
    |   key + user          a super-user key acting as that user_id. XenForo answers
    |                       403 user_id_not_allowed if the key is not a super-user
    |                       key, so "user" is only meaningful beside one.
    |   bearer              an OAuth2 access token. Takes precedence over "key" --
    |                       a token is what an exchange leaves behind, not something
    |                       that sits alongside a key. Tokens expire; refreshing one
    |                       is the application's business.
    |
    | With none of them the client acts as a guest, which XenForo treats as a
    | request without a key rather than as an error: unauthenticated endpoints
    | answer normally and everything else answers 400 no_api_key_in_request.
    |
    | "version" pins requests to an API version, producing /api/vN/... URLs. Leave
    | it unset to use whatever the forum considers current.
    |
    */

    'forums' => [

        'main' => [
            'url' => env('XENFORO_URL'),
            'key' => env('XENFORO_API_KEY'),
            'user' => env('XENFORO_API_USER'),
            'bearer' => env('XENFORO_API_TOKEN'),
            'version' => env('XENFORO_API_VERSION'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Transport
    |--------------------------------------------------------------------------
    |
    | Applied to Laravel's HTTP client on every request. A consumer's own
    | Http::globalRequestMiddleware() applies alongside them, and so does the
    | transport half of Http::globalOptions() - timeouts, TLS verification and
    | certificates, proxy, protocol version and curl settings. Global headers,
    | query and body options do not: the request is built by the core package,
    | and they would overwrite its API key or its body.
    |
    | There is no redirect setting: Guzzle's PSR-18 entry point does not follow
    | redirects, so a 3xx is handed back whole. That is what the two attachment
    | thumbnail endpoints need, since the 301 they answer with IS their output.
    |
    */

    'timeout' => env('XENFORO_TIMEOUT', 10),

    'connect_timeout' => env('XENFORO_CONNECT_TIMEOUT', 5),

];
