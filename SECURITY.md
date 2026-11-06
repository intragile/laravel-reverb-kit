# Security Policy

Developed and maintained by [IntrAgile Kft.](https://intragile.eu)

## Reporting a vulnerability

Please report security issues privately to **info@intragile.eu** rather than opening a public
issue. Include the affected version and, if possible, steps to reproduce. You will receive a
reply within a few working days.

## Security-relevant behaviour

- **No secrets in output.** `reverb:doctor`, its JSON output and the laravel/doctor diagnostics
  may name app ids, app keys and hosts, which are public (every browser receives the app key),
  but never the app secret. A finding that prints the secret is a security issue and should be
  reported privately.
- **Advisories.** The `reverb-version` rule fails on laravel/reverb releases affected by
  CVE-2024-50347 (below 1.4.0) and, with scaling enabled, CVE-2026-23524 (below 1.7.0). Its list
  is only as current as the installed version of this package.
- **Exposed HTTP API.** `reverb:proxy --with-api` exposes Reverb's `/apps` API. Every request to
  it is authenticated by its signature, but only expose it when a publisher outside the network
  needs it.

## Deployment advice

- Restrict `allowed_origins` in `config/reverb.php` to your frontend origins in production.
- Give each deployment its own `REVERB_APP_*` credentials and its own `REVERB_SCALING_CHANNEL`,
  and protect the scaling Redis with authentication.
- Run `php artisan reverb:doctor --strict` in your deployment pipeline.

---

Developed and maintained by [IntrAgile Kft.](https://intragile.eu)
