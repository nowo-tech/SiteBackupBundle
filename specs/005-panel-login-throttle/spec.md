# Feature Specification: Panel login throttle and session rotation

**Feature Branch**: `main`  
**Created**: 2026-10-12  
**Status**: Implemented (target **v1.17.0**)  
**Origin**: ported from a production host app (`ThrottledSiteBackupAccessGate` decorator + `framework.rate_limiter.site_backup_login`, security audit SEC-BACKUP-THROTTLE).

## Problem

1. **Online guessing** — the panel password gate only runs `password_verify()`; the panel (which can restore the database) is reachable on a public route and allowed unlimited attempts.
2. **Session fixation** — a successful login only set a session flag without rotating the session id.
3. Hosts had to write their own decorator and limiter to fix both.

## Requirements

| ID | Requirement |
| --- | --- |
| FR-THR-001 | `security.login_throttle` (`enabled: true`, `limit: 5` (min 1), `interval: '15 minutes'` (validated), `policy: fixed_window`/`sliding_window`, `limiter: null`, `cache_pool: null`). |
| FR-THR-002 | `ThrottledSiteBackupAccessGate` wraps the default `PasswordSiteBackupAccessGate` **or** the custom `security.access_gate`; registered as `nowo_site_backup.access_gate`, the target of the `SiteBackupAccessGateInterface` alias. Host decorators keep working. |
| FR-THR-003 | Throttling applies only while the inner gate reports `isProtectionEnabled()`. Key: `Request::getClientIp()` (`unknown` when null). One token per `authenticate()` call; limiter `reset()` on success. |
| FR-THR-004 | Exhausted → `TooManyRequestsHttpException` with `Retry-After` (≥ 1 s). `SiteBackupPanelController` catches it and re-renders the login template with HTTP 429, `Retry-After` header, `throttled: true` and the translated `panel.login.too_many_attempts` (`%minutes%`; English fallback without translator). Never a 500. |
| FR-THR-005 | Built-in limiter `nowo_site_backup.login_limiter` (`RateLimiterFactory`, id `nowo_site_backup_login`, `CacheStorage`, optional `lock.factory`). Pool = `cache_pool` ?? `cache.rate_limiter` ?? `cache.app`; resolved by `LoginThrottleStoragePass`; missing pool → `LogicException` at compile time. |
| FR-THR-006 | `limiter` = host `RateLimiterFactoryInterface` service id; replaces the built-in limiter. |
| FR-SES-001 | After a successful protected login the session id is rotated (`migrate(true)`) when the request has a session — also with `enabled: false`. |
| FR-DEP-001 | `symfony/rate-limiter: ^7.3 \|\| ^8.0` is a hard requirement (default-on feature; `RateLimiterFactoryInterface` since 7.3). |
| FR-I18N-001 | `panel.login.too_many_attempts` in en, es, fr, it, pt, de, nl. |
| FR-WRK-001 | Wrapper is `readonly` and stateless; limiter state lives in the cache pool (FrankenPHP worker safe). |

## Configuration (defaults)

```yaml
nowo_site_backup:
    security:
        login_throttle:
            enabled: true
            limit: 5
            interval: '15 minutes'
            policy: fixed_window
            limiter: null
            cache_pool: null
```

## Tests

- `tests/Unit/Security/ThrottledSiteBackupAccessGateTest.php` — throttled path (429 + Retry-After, per IP), success resets + session migrated, disabled throttle, protection disabled, delegation, missing client IP.
- `tests/Unit/Controller/SiteBackupPanelControllerThrottleTest.php` — 429 login page (translated / English fallback / default Retry-After), success redirect.
- `tests/Unit/DependencyInjection/Compiler/LoginThrottleStoragePassTest.php` — pool resolution order, compile-time errors, compiled gate graph throttles.
- `tests/Unit/DependencyInjection/SiteBackupExtensionTest.php`, `ConfigurationTest.php` — wiring (default, disabled, custom limiter service, custom pool / policy, custom gate wrapped), validation.

## Out of scope

- Throttling by username or globally (single shared password; per-IP is the audit requirement).
- Lockout notifications / logging of throttled attempts.
