<?php

declare(strict_types=1);

return [

    /*
    | The broadcasting connection the doctor and the payload guard look at, and the key of the
    | Reverb server under `reverb.servers` that serves it.
    */
    'connection' => env('REVERB_KIT_CONNECTION', 'reverb'),

    'server' => env('REVERB_KIT_SERVER', 'reverb'),

    /*
    | Environments treated as production: some rules only warn there (loopback hosts, `*` origins).
    */
    'production_environments' => ['production'],

    /*
    | How Reverb is deployed. Configuration alone cannot tell how many replicas run or whether
    | several deployments share one Redis, so the deploy pipeline states it here.
    */
    'topology' => [
        'replicas' => (int) env('REVERB_KIT_REPLICAS', 1),
        'shared_redis' => (bool) env('REVERB_KIT_SHARED_REDIS', false),
    ],

    /*
    | Path to the rendered frontend .env holding the VITE_REVERB_* values. Null falls back to the
    | application's own .env, which holds them when the frontend is built from the same project.
    */
    'frontend_env' => env('REVERB_KIT_FRONTEND_ENV'),

    'doctor' => [
        // Rule names to leave out, e.g. ['allowed-origins'].
        'skip' => [],
    ],

    /*
    | Checks the size of every broadcast before it is sent. Reverb rejects a publish larger than
    | its max_request_size with a 413, which otherwise surfaces far from the code that caused it.
    */
    'publish' => [
        'guard' => (bool) env('REVERB_KIT_GUARD_PAYLOADS', false),
        // report: report the exception and drop the broadcast; throw: rethrow it.
        'on_oversize' => env('REVERB_KIT_ON_OVERSIZE', 'report'),
        // Null uses reverb.servers.{server}.max_request_size, or 10000 when Reverb is not installed.
        'max_bytes' => null,
        // Bytes reserved for the HTTP request line and headers of the publish request.
        'header_allowance' => 1024,
    ],

    /*
    | Defaults for the reverb:proxy, reverb:stack and reverb:env generators.
    */
    'templates' => [
        // Name the proxy and the application use to reach Reverb on the internal network.
        'upstream_host' => env('REVERB_KIT_UPSTREAM_HOST', 'reverb'),
        // Hostname browsers connect to. Null uses the host of app.url.
        'public_host' => env('REVERB_KIT_PUBLIC_HOST'),
        // Docker's embedded DNS server.
        'resolver' => '127.0.0.11',
    ],

];
