# Changelog

Developed and maintained by [IntrAgile Kft.](https://intragile.eu)

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project adheres to
[Semantic Versioning](https://semver.org/).

## [Unreleased]

## [1.0.0] - 2026-11-06

### Added

- `reverb:doctor`, a static deploy gate with `--json`, `--strict`, `--replicas`,
  `--frontend-env` and `--skip`, and ten rules: `connection-credentials`, `publish-target`,
  `scheme-port`, `server-path`, `server-bind`, `scaling`, `allowed-origins`, `payload-limit`,
  `reverb-version` and `frontend-env`.
- The same rules as laravel/doctor diagnostics in the `reverb` group, when laravel/doctor is
  installed.
- `reverb:proxy nginx|caddy|traefik`: a WebSocket reverse proxy configuration. The nginx version
  resolves the upstream per request through Docker's DNS and sets read timeouts from the ping
  interval.
- `reverb:stack swarm|compose`: a Reverb service with an `/up` health check, resource limits, an
  update order that matches the scaling setup, and a per-deployment scaling channel.
- `reverb:env`: the env block that separates the bind address, the publish target and the public
  host.
- Publishable stubs (`reverb-kit-stubs`) that take precedence over the package's own.
- An opt-in broadcast payload size guard (`REVERB_KIT_GUARD_PAYLOADS`). It measures every publish
  request as the Pusher client sends it, encrypted channels included, and reports or throws
  `PayloadTooLarge` before anything is sent.

---

Developed and maintained by [IntrAgile Kft.](https://intragile.eu)
