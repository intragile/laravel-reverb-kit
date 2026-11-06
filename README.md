# Laravel Reverb Kit

Developed and maintained by [IntrAgile Kft.](https://intragile.eu)

[Magyar leírás](README.hu.md)

Production toolkit for [Laravel Reverb](https://reverb.laravel.com) when you run it yourself, in
Docker, Swarm or compose, behind your own reverse proxy:

- **`reverb:doctor`**, a static deploy gate. It catches the configuration mistakes that make
  real-time updates silently stop: a bind address used as the publish target, a hostname given
  to the server to bind to, a lost path prefix, replicas without scaling, a shared scaling
  channel, contradicting size limits, a vulnerable Reverb release. It exits non-zero and has a
  `--json` output. The same rules show up in [`laravel/doctor`](https://github.com/laravel/doctor)
  under the `reverb` group.
- **Generators** for the configuration you would otherwise copy between projects:
  - `reverb:proxy nginx|caddy|traefik`, the WebSocket reverse proxy;
  - `reverb:stack swarm|compose`, the Reverb service;
  - `reverb:env`, the env block that keeps the three Reverb hosts apart.
- **An opt-in payload size guard.** It checks every broadcast before it is sent. Without it,
  Reverb rejects a publish over its request limit with a 413 that surfaces as a generic
  `BroadcastException`, often in a queue worker, far from the code that caused it.

If you deploy with Laravel Forge, Forge configures the proxy and the daemon for you; the doctor is
still useful, the generators are not.

## Requirements

- PHP 8.3+
- Laravel 12 or 13

### Optional packages

| Package | What it enables |
|---|---|
| `laravel/reverb` (^1.7) | The server-side rules (bind address, scaling, origins, payload limits, version) and the `/up` health check in the stack templates. An application that only publishes to a Reverb server deployed elsewhere does not need it; those rules are then skipped. |
| `laravel/doctor` (^0.1) | The rules as `php artisan doctor` diagnostics. |
| `pusher/pusher-php-server` | Required by the payload guard. Laravel's `reverb` broadcast driver uses it already. |

## Installation

```bash
composer require intragile/laravel-reverb-kit
```

The service provider is discovered automatically. To change the defaults, publish the
configuration:

```bash
php artisan vendor:publish --tag=reverb-kit-config
```

## The three hosts

Most Reverb deployment failures come from mixing up three different hosts:

```
                      VITE_REVERB_HOST:443 (https)
  Browser ──────────────────────────────────▶ reverse proxy ──▶ Reverb server
                                                 /app/{key}      binds REVERB_SERVER_HOST:REVERB_SERVER_PORT
                                                                 (0.0.0.0:8080)
                                                                      ▲
  Laravel application ────────────────────────────────────────────────┘
                      REVERB_HOST:REVERB_PORT (http, internal network, e.g. reverb:8080)
```

- **`REVERB_SERVER_HOST`** is where the server listens: an IP, usually `0.0.0.0`. Nobody connects
  to it.
- **`REVERB_HOST` / `REVERB_PORT` / `REVERB_SCHEME`** are where Laravel sends broadcasts. In a
  container setup this is the service name on the internal network, for example `reverb:8080`
  over `http`.
- **`VITE_REVERB_HOST` / `_PORT` / `_SCHEME`** are what the browser connects to: the public
  hostname, through the proxy and TLS. They are baked into the frontend bundle at build time.

`php artisan reverb:env` prints this block with comments.

## Doctor

```bash
php artisan reverb:doctor
```

```
  OK    connection-credentials The connection credentials match the Reverb app 1.
 FAIL   publish-target         The reverb connection host is a bind address (0.0.0.0).
                                → REVERB_HOST is where Laravel sends broadcasts; the bind address belongs in REVERB_SERVER_HOST.
  OK    server-bind            Reverb binds to 0.0.0.0
  OK    scaling                scaling off, 1 replica
 WARN   allowed-origins        Reverb app(s) 1 accept connections from any origin
  ...
1 failed, 1 warning(s), 7 ok, 1 skipped.
```

| Option | Meaning |
|---|---|
| `--json` | Print `{findings: [{rule, status, message, hint}], worst}`. |
| `--strict` | Exit with 1 on warnings too. |
| `--replicas=N` | How many Reverb replicas the deployment runs (default `reverb-kit.topology.replicas`). |
| `--frontend-env=PATH` | The rendered frontend `.env` with the `VITE_REVERB_*` values (default `reverb-kit.frontend_env`, then the application's `.env`). |
| `--skip=RULE` | Leave a rule out; repeatable. `reverb-kit.doctor.skip` does the same permanently. |

Exit codes: `0` when nothing failed (warnings included, unless `--strict`), `1` on a failure,
`2` on invalid options. When the default broadcasting connection is not the Reverb one (for
example `null` in an application that does not broadcast), every rule is skipped and the exit
code is `0`.

### As a deploy gate

Run it where the deployed configuration is available, for example as a step after the
configuration is rendered and before traffic is switched:

```bash
php artisan reverb:doctor --replicas="$REVERB_REPLICAS" --frontend-env=frontend/.env
```

### Rules

| Rule | Checks | Fails when |
|---|---|---|
| `connection-credentials` | The broadcasting connection's `key`, `secret` and `app_id` match a Reverb app (only that they are set when Reverb is not installed). Skipped when laravel/reverb ships its own equivalent diagnostic. | No matching app, or a mismatching secret or app id. |
| `publish-target` | `REVERB_HOST` as the connection host. An internal service name (`reverb`) is fine. | It is empty, `0.0.0.0` or `::`. Warns on loopback in production. |
| `scheme-port` | Scheme, `useTLS` and port of the connection and of every app. | `https` without TLS or the other way round. Warns on 443 with `http` or 80 with `https`. |
| `server-path` | A `REVERB_SERVER_PATH` prefix also reaches the connection's `path` option. Laravel 13's default connection has no `path`, so the prefix is silently lost. The value must be `/prefix/`: the Pusher SDK resolves the publish URL relative to it. | The path is missing or differs. |
| `server-bind` | `REVERB_SERVER_HOST`. The server cannot bind to a hostname. | It is not an IP address. |
| `scaling` | Replicas, `REVERB_SCALING_ENABLED` and `REVERB_SCALING_CHANNEL`. Reverb's scaling channel is a plain Redis pub/sub channel, shared by every deployment that uses the same name. | More than one replica without scaling, or the default `reverb` channel with `reverb-kit.topology.shared_redis`. Warns on the default channel otherwise. |
| `allowed-origins` | The apps' `allowed_origins` in production. `config/reverb.php` hard-codes `['*']`. | Never; warns on `*`. |
| `payload-limit` | Reports `max_request_size` (the limit for server-side publishes) and each app's `max_message_size`. | Never; warns when `max_message_size` is above `max_request_size` (the wrong variable was raised) or the guard allows more than the server accepts. |
| `reverb-version` | The installed laravel/reverb against known advisories. | Below 1.4.0 (CVE-2024-50347), or below 1.7.0 with scaling enabled (CVE-2026-23524). Warns below 1.7.0 otherwise. |
| `frontend-env` | `VITE_REVERB_*` against the server side. `${VAR}` and `$VAR` references are left to the build. | The app key differs, or the host is a bind address, loopback in production, or a single-label internal name a browser cannot resolve. |

The doctor is static: it reads configuration and makes no network calls. For a live WebSocket
round trip, see [intragile/laravel-observability](https://github.com/intragile/laravel-observability),
whose `BroadcastCheck` sends an event through Reverb and waits for it on a real connection.

### laravel/doctor

With `laravel/doctor` installed, each rule is a diagnostic in the `reverb` group:

```bash
php artisan doctor --only=reverb
```

## Generators

Every generator prints to stdout, or writes a file with `--output=` (an existing file is only
overwritten with `--force`). Values come from your configuration; options override them.

### Reverse proxy

```bash
php artisan reverb:proxy nginx --public-host=ws.example.com
php artisan reverb:proxy caddy
php artisan reverb:proxy traefik
```

The nginx location resolves the upstream through Docker's DNS (`resolver 127.0.0.11`) and a
variable `proxy_pass`. With a literal host name nginx resolves it once, at startup, and keeps
proxying to the old address after the orchestrator reschedules Reverb. The read timeout is twice
the ping interval, so idle connections are not cut at nginx's 60 s default. The location matches
`/app/` only; `--with-api` also exposes the HTTP API under `/apps/`, which is only needed when
the publisher runs outside the network.

### Swarm and compose service

```bash
php artisan reverb:stack swarm --image=registry.example.com/app:1.4.0 --replicas=1
php artisan reverb:stack compose --image=registry.example.com/app:1.4.0
```

The service runs `reverb:start` from your application image, publishes no ports, checks health
on Reverb's `/up` endpoint (falling back to a status code check for releases without it), and
limits resources. With one replica it updates `stop-first`: two instances without scaling would
split the clients between them. With more than one replica it turns scaling on, updates
`start-first` and picks a scaling channel derived from the application name unless you pass
`--scaling-channel`.

### Env block

```bash
php artisan reverb:env --public-host=ws.example.com --internal-host=reverb
```

### Custom templates

```bash
php artisan vendor:publish --tag=reverb-kit-stubs
```

The stubs are copied to `stubs/reverb-kit/`; a published stub takes precedence over the
package's own.

## Payload size guard

Opt in with `REVERB_KIT_GUARD_PAYLOADS=true`. The guard wraps the `reverb` broadcast driver and
measures every publish request exactly as the Pusher client would send it, before anything is
sent. The size includes the channel list and, for `private-encrypted-*` channels, the ciphertext.

```
Broadcast "OrderShipped" is 10342 bytes, over the 10000-byte Reverb request limit (raise REVERB_MAX_REQUEST_SIZE or send less).
```

| Setting | Default | Meaning |
|---|---|---|
| `REVERB_KIT_ON_OVERSIZE` | `report` | `report`: pass the `PayloadTooLarge` exception to `report()` and drop the broadcast. `throw`: throw it to the caller. |
| `reverb-kit.publish.max_bytes` | `null` | The limit. Null uses `reverb.servers.reverb.max_request_size`, or 10 000 when Reverb is not installed. |
| `reverb-kit.publish.header_allowance` | `1024` | Bytes reserved for the request line and headers, which count toward Reverb's limit. |

A broadcast split into several requests (more than 100 channels, or encrypted channels) goes out
to all of its channels or to none. Laravel's `ShouldRescue` still applies on top: the guard
decides whether a payload is sent, `ShouldRescue` decides what happens to any other broadcasting
error.

## Channel isolation between deployments

Reverb isolates apps by their key, so deployments with their own `REVERB_APP_*` credentials do
not see each other's channels. What they can share is the scaling channel: give every deployment
its own `REVERB_SCALING_CHANNEL`, for example `reverb_{app}_{environment}`, and set
`REVERB_KIT_SHARED_REDIS=true` where several deployments use one Redis so the doctor enforces it.

## What happens when something is not installed

| Missing | Effect |
|---|---|
| laravel/reverb | The server-side rules are skipped; the connection-side rules and the generators still work. The stack templates use the status code health check. |
| laravel/doctor | No `php artisan doctor` diagnostics; `reverb:doctor` is unaffected. |
| Pusher client | Enabling the payload guard throws a clear exception when broadcasting is first used. Nothing else is affected. |

## Configuration reference

See [`config/reverb-kit.php`](config/reverb-kit.php). Environment variables:
`REVERB_KIT_CONNECTION`, `REVERB_KIT_SERVER`, `REVERB_KIT_REPLICAS`, `REVERB_KIT_SHARED_REDIS`,
`REVERB_KIT_FRONTEND_ENV`, `REVERB_KIT_GUARD_PAYLOADS`, `REVERB_KIT_ON_OVERSIZE`,
`REVERB_KIT_UPSTREAM_HOST`, `REVERB_KIT_PUBLIC_HOST`.

## Testing

```bash
composer test
composer analyse
```

## License

MIT. See [LICENSE](LICENSE).

---

Developed and maintained by [IntrAgile Kft.](https://intragile.eu)
