# Changelog

All notable changes to `rene-roscher/user-sessions-laravel` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased](https://github.com/Rene-Roscher/user-sessions-laravel/commits/main/compare/v1.0.0...HEAD)

## [v1.0.0](https://github.com/Rene-Roscher/user-sessions-laravel/commits/main/compare/main...v1.0.0) - 2026-07-23

### Added

- **`HasUserSessions::revokeSession(string $id, ?string $revokedBy = RevokedBy::SELF): bool`** — revoke
  one of the user's own sessions by registry id. Scoped to the user's rows, so a foreign id is a
  miss rather than a cross-account revoke. The standard device-list controller no longer needs to
  touch the `sessions()` relation or the facade.
- **`UserSession::lastActiveHuman(): ?string`** — convenience for views: `lastActivityAt()?->diffForHumans()`
  in one call, so a Blade/Inertia template can render "last active 5 minutes ago" without knowing
  which timestamp is store-truth vs. debounced column.
- **Store-backed last activity.** The device list now reads `last_activity` from the session store
  itself (Redis TTL, file mtime) instead of trusting the debounced registry column, and a session
  the store has dropped is reported inactive immediately instead of lingering until it is pruned.
  New `SessionActivityResolver` contract, `resolve_activity_from_store` / `activity_resolver` config,
  `$user->activeSessions()`, `$session->lastActivityAt()`, `$session->existsInStore()`,
  `UserSessions::reconcile()` and `$collection->withStoreActivity()`. Read path only.
- `cache_store` config for the debounce markers, plus `php artisan about` now reporting whether the
  configured store can actually debounce and where last activity is read from.
- `RevokedBy` constants (`self`, `other-device`, `password-reset`, `admin`, `expired`) and a
  `$revokedBy` parameter on `revokeOthers()` / `revokeAll()`.
- Redis-primary session tracking with a shadow `user_sessions` registry table.
- `HasUserSessions` trait: `sessions()`, `currentSession()`, `revokeOtherSessions()`, `revokeAllSessions()`.
- `UserSessions` facade / `ManagesUserSessions` service: `for()`, `find()`, `revoke()`, `revokeOthers()`, `revokeAll()`, `payload()`.
- `$request->userSession()` macro (memoized per request, Octane-safe).
- Debounced, deferred registry updates with race-safe `upsert`; guests are never tracked.
- Driver-agnostic revoke via the session handler, including a resurrection guard for self-revoke.
- Event listeners for `Logout`, `CurrentDeviceLogout`, `OtherDeviceLogout` and `PasswordReset`.
- Impersonation-aware tracking: while impersonating (native `isImpersonated()` trait or a
  configured session key), no row/event/notification is produced.
- First-class Inertia / Livewire / SPA compatibility (deferred, response-safe tracking).
- New-device detection: `UserSessionCreated` carries an `isNewDevice` flag and the full session
  model — bring your own notification by listening to the event (recipe in the README). The
  package deliberately ships no mail of its own.
- Opt-in broadcasting of `UserSessionCreated` / `UserSessionRevoked` on morph-scoped private
  channels (`user-sessions.{morphType}.{id}`) so ids cannot collide across models.
- Device-aware log context (`device`, `user_session_id`).
- `UserSessionsPruned` event, dispatched from Laravel's native `model:prune`.
- Dependency-free `NativeUserAgentParser`, swappable via the `UserAgentParser` contract.
- Architecture tests enforcing the Octane rules; PHPStan level max; 100% type coverage.

### Hardened

- Device lists are bounded by a new `max_listed` config (default 100). Nothing stops a
  scripted client from logging in repeatedly and accumulating rows, and rendering that
  list hydrates every row and asks the session store about each one.
- The architecture test that forbids request-scoped state now covers **every** class under
  `src/` rather than the two it originally named, and also rejects a held `Session\Store`.
  Under a long-running worker any such property would serve one user's device to the next.

### Testing

- The suite now runs against **MySQL 8 and PostgreSQL 16** as well as SQLite, and against
  every session driver. SQLite does not enforce column widths or validate UTF-8, so it
  could never have failed on the User-Agent defects fixed below — verified by mutation:
  removing the sanitising leaves SQLite green while both real engines fail.
- The upsert race (PLAN §12 test 3b) is exercised with **16 genuinely concurrent OS
  processes** on separate connections instead of a sequential loop, which could not fail
  by construction.
- The Octane state-bleed scenario (PLAN §12 test 19) is now driven end to end: several
  requests from different users through one booted application, asserting rows are
  attributed correctly, device context does not survive a request boundary, and no
  container singleton retains a `Request`, a user or a session row.

### Fixed

- **The architecture test guarding against request-scoped state could never fail.** Its
  per-property assertions passed a failure message to `not->toContain()`, but `toContain()`
  takes only needles — the message became a second needle that never matched, and a
  not-expectation passes as soon as any needle misses. Rewritten to collect violations and
  assert with `toBe()` (which does take a message). The repaired test immediately caught
  `SessionPayload` holding a `Session\Store`; it now holds the narrower
  `SessionHandlerInterface` instead.
- Removed the unused `illuminate/notifications` dependency — nothing in the package
  requires it since the built-in mail was replaced by the listener recipe.
- **Registry failures are no longer swallowed inside a transaction.** PostgreSQL aborts an
  entire transaction after any failed statement, so absorbing a `QueryException` there
  replaced the real cause with a cascade of "current transaction is aborted" errors further
  up the caller's stack. Database errors now propagate when a transaction is open;
  everything else is still contained so a logout cannot 500.
- **A bulk revoke stopped at the first failing row and still reported success.** One
  deadlocked row left every remaining device authenticated while the controller rendered
  "you have been logged out everywhere". Each session is now revoked independently, and a
  partial failure raises `CouldNotRevokeSessions` instead of returning a count. A `saving`
  listener vetoing the write (returning `false`) also counts as a failure rather than
  passing silently.
- **`UserSessions::for()` / `activeSessions()` listed dead sessions** although the docs
  promised otherwise: expired rows and rows the store had already dropped were returned,
  rendering a logged-out device as live with a working "log out" button.
- **A single failed write permanently suppressed the new-device alert.** The "announced"
  marker was claimed before the upsert, so one transient database error burned it and the
  security email for that login was never sent. It is now claimed only after the write.
- **`UserSession` ignored the `user-sessions.table` config**, so renaming the table broke
  every Eloquent path (facade, relation, macro, `model:prune`) while the recorder happily
  wrote to the renamed table.
- Invalid UTF-8 in a `User-Agent` aborted the upsert exactly like an over-long value did —
  values are now scrubbed to valid UTF-8 and stripped of control characters (which also
  closes a log-injection vector) before truncation.
- The store activity resolver no longer reports "active just now" for sessions whose TTL
  was written under a different `session.lifetime`.
- **Revoking outside a request silently did nothing store-side.** `destroyStoredSession()` was gated
  on `Session::isStarted()`, so `UserSessions::revoke()` / `revokeAll()` from a command, a queued job
  or tinker set `revoked_at`, fired the event and reported success while every device stayed logged
  in until natural expiry. The handler destroy now runs in every context.
- **The raw session id was shared into the log context** (and therefore into every log line and
  every queued job payload) instead of the registry row's ULID, and it was shared unconditionally
  rather than only when the row had already been resolved.
- **Device context never reached controller-dispatched jobs.** Context was shared on the middleware's
  way out, i.e. after the controller had already dispatched its jobs and written its log lines. The
  device label is now shared on the way in.
- `payload()` returned `null` whenever no session was started, making it useless in tinker and
  queued jobs — the very places it exists for.
- The new-device email built its "Manage devices" link with `url('/')`, letting a spoofed `Host`
  header on the attacker's login request point a security alert at the attacker's site.
- Password-reset revocations were labelled `other-device` / `all` and never `password-reset`.
- The `UserSession` model was missing `HasUlids`, so any `create()` without an explicit id failed
  with a not-null violation on the primary key.
- Over-long `User-Agent` headers are truncated to the column width; on MySQL/Postgres they aborted
  the upsert *after* the debounce marker was claimed, permanently silencing that session.
- Broadcast payloads no longer contain `session_id`.
- Registry failures in the logout / password-reset / other-device listeners are logged instead of
  breaking the user's flow.
- Added indexes for the device-list query (`user_type, user_id, revoked_at`) and for pruning
  (`revoked_at`).
