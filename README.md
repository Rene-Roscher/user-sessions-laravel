# User Sessions for Laravel

[![Tests](https://github.com/Rene-Roscher/user-sessions-laravel/actions/workflows/tests.yml/badge.svg)](https://github.com/Rene-Roscher/user-sessions-laravel/actions/workflows/tests.yml)
[![Static analysis](https://github.com/Rene-Roscher/user-sessions-laravel/actions/workflows/static.yml/badge.svg)](https://github.com/Rene-Roscher/user-sessions-laravel/actions/workflows/static.yml)
[![PHPStan](https://img.shields.io/badge/PHPStan-level%20max-brightgreen)](https://phpstan.org/)
[![Type Coverage](https://img.shields.io/badge/type--coverage-100%25-brightgreen)](https://github.com/Rene-Roscher/user-sessions-laravel)
[![License](https://img.shields.io/badge/license-MIT-blue)](LICENSE.md)

> **Device management, "log out other devices" and new-device alerts for Laravel — without giving up your cache-based session driver.**

Laravel apps use Redis (or Memcached / DynamoDB) as their session driver because it's fast.
Doing so means losing everything the `database` driver gives you: listing active sessions,
seeing devices, invalidating a session on demand. Jetstream's *Browser Sessions* is hard-wired
to the `database` driver. This package gives you both — **Redis stays the source of truth, and a
lightweight registry table gives you the device list** — with **zero database writes on the hot
path**.

```php
$user->sessions()->active()->get();      // the device list
$request->userSession();                 // the current session, as a model
$session->revoke();                      // log a device out — effective immediately
$user->revokeOtherSessions($request);    // "log out everywhere else"
UserSessions::revokeAll($user);          // admin-level, from anywhere
```

---

## Requirements

| | |
|---|---|
| PHP | 8.2, 8.3, 8.4 |
| Laravel | 12.x, 13.x |
| Session driver | any (`array`, `file`, `redis`, `memcached`, …) |
| Registry database | anything Eloquent supports (integer, UUID and ULID user keys all work) |

---

## Installation

```bash
composer require rene-roscher/user-sessions-laravel
php artisan vendor:publish --tag=user-sessions-migrations
php artisan migrate
```

Add the (optional) trait to your authenticatable model:

```php
use ReneRoscher\UserSessions\Models\Concerns\HasUserSessions;

class User extends Authenticatable
{
    use HasUserSessions; // optional — tracking works without it
}
```

That's it. The middleware, event listeners, request macro and log context register themselves.
For automatic cleanup of stale rows, schedule Laravel's native pruning:

```php
use Illuminate\Support\Facades\Schedule;
use ReneRoscher\UserSessions\Models\UserSession;

Schedule::command('model:prune', ['--model' => [UserSession::class]])->daily();
```

---

## Usage

### Show a user their devices

```php
// routes/web.php or a controller
$sessions = $request->user()->activeSessions();
```

`activeSessions()` is the device-list API: it returns the active rows **annotated with the session
store's own view of last activity**, and rows whose sessions the store has already dropped are
filtered out via `isActive()` — so the list reflects reality without you calling `reconcile()`
yourself.

```blade
{{-- resources/views/devices.blade.php --}}
@foreach ($sessions as $session)
    <div>
        <strong>{{ $session->device_label }}</strong>       {{-- "Chrome on macOS" --}}
        <span>{{ $session->ip_address }}</span>
        <span>last active {{ $session->lastActiveHuman() }}</span>

        @if ($session->isCurrent(request()))
            <em>This device</em>
        @else
            <form method="POST" action="/devices/{{ $session->id }}/revoke">
                @csrf
                <button>Log out</button>
            </form>
        @endif
    </div>
@endforeach
```

### Revoke a single device

```php
$request->user()->revokeSession($id);   // store-destroy + revoked_at + event — effective on the next request
```

`revokeSession()` is scoped to the user's own rows, so a foreign id is a miss rather than a
cross-account revoke. It returns `false` if no matching row was found.

A Livewire component is just as small:

```php
public function revoke(string $id): void
{
    auth()->user()->revokeSession($id);
}
```

### Log out everywhere else / everywhere

```php
$request->user()->revokeOtherSessions($request);   // "log out everywhere else"
$request->user()->revokeAllSessions();             // including the current one — admin-level
```

### New-device alert

The package dispatches `UserSessionCreated` with an `isNewDevice` flag. Listen for it and
send your own notification in your app's language and branding:

```php
use ReneRoscher\UserSessions\Events\UserSessionCreated;

Event::listen(function (UserSessionCreated $event): void {
    if ($event->isNewDevice) {
        $event->session->user->notify(new NewDeviceLoginAlert($event->session));
    }
});
```

The event carries the full `UserSession` model, so you have device label, IP, platform and
browser without any extra queries.

---

## How it works

**Redis-primary with a shadow registry.**

```
                Request  ──►  Session store (Redis / Memcached / …)   ← 100% source of truth
                                        │                               (hot path never touches the DB)
                                        │  after the response, debounced
                                        ▼
                             user_sessions registry table            ← metadata only:
                             (id, session_id, user, ip, device,        session id, user (morph),
                              last_activity, revoked_at)                ip, parsed user-agent, last_activity
```

- The session store stays the **source of truth**. Reading/writing a session per request never
  touches the database.
- A lightweight `user_sessions` table holds **only metadata** — never the session payload.
- The registry is updated **after the response** (`defer()`) and **debounced** (default: at most
  once every 180s per session). The debounce marker lives in the cache, never the DB.
- **Guests are never tracked.** No `$request->user()`, nothing happens — an HTTP flood produces
  **zero** database writes ([proven by a test](tests/Feature/GuestFloodTest.php)).
- Authenticated traffic is debounced down to **one write per session per interval** — 25 requests
  produce a single database write ([proven by a test](tests/Feature/HotPathTest.php)).
- Revoking runs **driver-agnostically** via `Session::getHandler()->destroy($id)` — it works with
  any session driver, not just Redis, and the next request from that device really is a guest
  ([proven by a test](tests/Feature/RevokeRoundtripTest.php)).
- The registry is a **derivative**: it may lag, fail, or be rebuilt without logging anyone out.

> **The debounce needs a shared, persistent cache store.** On an `array` or `null` store
> `Cache::add()` cannot remember a marker between requests, so the debounce silently degrades into
> one database write per request — exactly what this package exists to avoid. Point
> `user-sessions.cache_store` at redis/memcached/database in production; `php artisan about` flags a
> store that cannot work.

---

## Accurate last activity

The registry column is written *debounced*, so on its own it is up to `sync_interval` stale, and a
session that expired or was regenerated in the store keeps sitting in the table looking alive until
it is pruned. Neither is acceptable in a device list.

So the **read path asks the store**, which knows exactly:

| Session driver | Source | Precision |
|---|---|---|
| `redis` (and cache-backed drivers on a Redis store) | remaining TTL — Laravel refreshes it on every write | exact |
| `file` | session file mtime | exact |
| `memcached`, `dynamodb`, `array`, … | not introspectable → the registry column | ±`sync_interval` |

This is a **read-path feature only** — one batched store round trip when a device list is rendered,
never anything on the hot path.

```php
$sessions = $user->activeSessions();          // resolved automatically
$user->sessions->withStoreActivity();         // or resolve a relation result yourself

$session->lastActivityAt();   // best known truth (store, else the column)
$session->last_activity;      // the raw persisted column, unchanged
$session->existsInStore();    // true / false / null when the store cannot say
$session->isActive();         // false as soon as the store has dropped the session
```

Rows whose sessions the store no longer holds can be cleaned up explicitly — never implicitly on a
read:

```php
UserSessions::reconcile($user);   // marks store-dead rows revoked, returns the count
```

Turn the whole thing off with `'resolve_activity_from_store' => false`; `last_activity` then behaves
as a plain debounced column (accurate to ±`sync_interval`).

---

## The Facade & the trait

The `HasUserSessions` trait is the consumer-facing API — three methods cover the standard
device-list flow:

```php
$user->activeSessions();               // Collection — the device list, store-accurate
$user->revokeSession($id);             // bool — log out one device (scoped to the user's own rows)
$user->revokeOtherSessions($request);  // int — "log out everywhere else"
$user->revokeAllSessions();            // int — including the current one
$user->currentSession($request);       // ?UserSession
```

The raw relation is exposed for admin/audit queries — it is **not** the device list:

```php
$user->sessions;                       // MorphMany, newest first (raw registry rows, includes revoked and store-dead)
```

The `UserSessions` facade is the admin/service layer — end a user's sessions from a Nova action,
command or controller without touching the user model:

```php
use ReneRoscher\UserSessions\Facades\UserSessions;

UserSessions::for($user);                          // Collection<UserSession>, store-accurate
UserSessions::find($sessionId);                    // ?UserSession
UserSessions::revoke($session, RevokedBy::ADMIN);  // bool
UserSessions::revokeOthers($user, $currentId);     // int
UserSessions::revokeAll($user);                    // int
UserSessions::reconcile($user);                    // int — drop rows the store no longer holds
UserSessions::payload($session);                   // ?array — read-only, via the handler
```

`revokeOthers()` and `revokeAll()` attempt every session even if one fails, and throw
`CouldNotRevokeSessions` when any could not be ended — a bulk revoke is a security control,
so a partial failure must never be reported to the user as "logged out everywhere".

All of these work **outside a request too** — from a command, a queued job or tinker. The session is
genuinely destroyed in the store there as well, not merely flagged in the table.

Each `UserSession` model exposes:

```php
$session->isActive();          // bool — not revoked, still in the store, within lifetime
$session->lastActivityAt();    // ?CarbonImmutable — store truth where available
$session->lastActiveHuman();   // ?string — "5 minutes ago" (store truth, convenience for views)
$session->existsInStore();     // ?bool — null when the store cannot answer
$session->isCurrent($request); // bool
$session->device_label;        // "Chrome on macOS"
$session->revoke();            // effective immediately
$session->payload();           // ?array — read-only session payload (debugging gold)
```

---

## Broadcasting (opt-in)

Turn `broadcast => true` on and `UserSessionCreated` / `UserSessionRevoked` broadcast on a
`PrivateChannel`. Revoke a device on phone A and browser B flies to the login screen the same second.

```php
// config/user-sessions.php
'broadcast' => true,
```

The channel is **scoped by the morph type and key** — `user-sessions.{morphType}.{id}`, where the
model's backslashes become dots (Laravel's model-channel convention) — so two different
authenticatable models that happen to share an integer id never collide. For `App\Models\User` #1
the channel is `user-sessions.App.Models.User.1`.

Register the channel authorization yourself (it's a security decision, so the package won't do it
for you) — one line per authenticatable model you track:

```php
// routes/channels.php
Broadcast::channel('user-sessions.App.Models.User.{id}', function ($user, $id) {
    return (int) $user->getKey() === (int) $id;
});
```

```js
// resources/js/echo bootstrap — morphType has backslashes replaced by dots
const morphType = 'App.Models.User';
Echo.private(`user-sessions.${morphType}.${userId}`)
    .listen('.session.revoked', (e) => window.location.reload());
```

---

## Device-aware logs & jobs

Every log line and every queued job dispatched from the request carries the device context — no
application code required:

```php
'context' => [
    'enabled' => true,
    'keys'    => ['device', 'user_session_id'],
],
```

```
[2026-07-15 10:22:31] production.ERROR: Payment failed
    {"device":"Chrome on macOS","user_session_id":"8JRSyS56DSXu…"}
```

`device` is parsed from the live request (no DB read). `user_session_id` is the registry row's
ULID — never the session id itself, which is a bearer credential that must not be copied into logs
and job payloads. It identifies the session for correlation and is shared without a hot-path query.

---

## Impersonation

When an admin is impersonating a user, tracking is **skipped entirely** — no registry row, no
events, and (crucially) no *"new device login"* notification is sent to the real user for the
admin's device.

Impersonation is detected in two ways, out of the box:

- the native `isImpersonated()` trait method (as used by
  [`lab404/laravel-impersonate`](https://github.com/404labfr/laravel-impersonate)), and
- any of the configured session keys (default `impersonated_by`, lab404's default).

Using a different package or a custom key? Add it:

```php
// config/user-sessions.php
'impersonation' => [
    'session_keys' => ['impersonated_by', 'your_custom_key'],
],
```

---

## Configuration

Publish it with `php artisan vendor:publish --tag=user-sessions-config`:

```php
return [
    /* Swappability — classes, not option soup */
    'model'  => \ReneRoscher\UserSessions\Models\UserSession::class,
    'parser' => \ReneRoscher\UserSessions\Support\NativeUserAgentParser::class,

    /* Read last_activity back from the session store instead of the debounced column */
    'resolve_activity_from_store' => true,
    'activity_resolver' => \ReneRoscher\UserSessions\Support\StoreSessionActivityResolver::class,

    /* Storage */
    'table'             => 'user_sessions',
    'sync_interval'     => 180,   // seconds of debounce; 0 = sync every request (still race-safe)
    'sync_on_failure'   => false, // true = also sync on 4xx/5xx responses (defer()->always())
    'new_device_window' => 86400, // seconds within which a device triple counts as "known"
    'prune_after_days'  => 30,    // retention AFTER expiry / revoke
    'max_listed'        => 100,   // upper bound on how many sessions a device list returns
    'cache_store'       => null,  // store for the debounce markers; null = your default store
                                  // MUST be shared and persistent — see "How it works"

    /* Feature toggles — each framework hook individually switchable */
    'middleware_group'         => 'web',  // null = register manually
    'request_macro'            => true,   // $request->userSession()
    'revoke_on_password_reset' => true,
    'broadcast'                => false,  // opt-in
    'context' => [
        'enabled' => true,
        'keys'    => ['device', 'user_session_id'],
    ],
    'events' => true,                     // global kill-switch for package events

    /* Tracking is skipped entirely while impersonating (see "Impersonation") */
    'impersonation' => [
        'session_keys' => ['impersonated_by'],
    ],
];
```

---

## Swapping implementations

Configurability comes from **interfaces in the container**, not config-string soup:

```php
// A more precise user-agent parser
$this->app->bind(
    \ReneRoscher\UserSessions\Contracts\UserAgentParser::class,
    MyWhichBrowserParser::class,
);

// Selective tracking, a different storage strategy, …
$this->app->bind(
    \ReneRoscher\UserSessions\Contracts\SessionRecorder::class,
    MyRecorder::class,
);
```

Point `model` at your own subclass to rename the `sessions()` relation or add behaviour.

---

## Events

| Event | Payload | Broadcast |
|---|---|---|
| `UserSessionCreated` | `UserSession $session`, `bool $isNewDevice` | opt-in, `user-sessions.{morphType}.{id}` |
| `UserSessionRevoked` | `UserSession $session`, `?string $revokedBy` | opt-in, `user-sessions.{morphType}.{id}` |
| `UserSessionsPruned` | `int $count` | no |

`$revokedBy` carries one of the `RevokedBy` constants — `self`, `other-device`, `password-reset`,
`admin` or `expired` (the store no longer held the session and `reconcile()` caught up) — so a
frontend can tell "you signed this device out" apart from "your password changed".
Applications may pass any other string.

Broadcast payloads deliberately contain the row's ULID (`id`) and **never** `session_id`: the
payload reaches the browser and any third-party broadcaster, and a session id is a bearer credential.

Set `events => false` to silence every package dispatch.

---

## Octane

Guaranteed compatible, and tested for it:

- The recorder and registry are **stateless singletons** — an architecture test asserts no `src`
  class holds static properties or a `Request`/`Authenticatable` as a property.
- The middleware imports the namespaced `use function Illuminate\Support\defer;` (Swoole defines
  its own global `defer`).
- Request context is detected via `Session::isStarted()`, **not** `runningInConsole()` — the latter
  reports `true` under Swoole even during HTTP requests.
- A [state-bleed test](tests/Feature/StateBleedTest.php) drives requests from different users through
  one booted application and asserts that rows stay correctly attributed, that device context does
  not survive a request boundary, and that no singleton retains a request, a user or a session row.

---

## Inertia & SPAs

Works out of the box. Inertia visits are ordinary session-backed requests, so the middleware,
the `userSession()` macro and the log context all behave exactly as they do for Blade responses.
The tracking write is deferred until *after* the response and never mutates it, so it cannot
interfere with Inertia's protocol:

- an Inertia `200` visit records normally;
- an Inertia `303` redirect (its POST-then-redirect convention) records normally;
- an Inertia `409` asset-version conflict does **not** write a phantom row — the deferred sync
  skips `4xx`, and the full page reload Inertia triggers records instead.

The same holds for Livewire, Blade and plain JSON APIs on session-backed (`web`) routes.

---

## Why not …?

| Package | Why not |
|---|---|
| `craftsys/laravel-redis-session-enhanced` | Metadata lives inside the Redis payload; no SQL-queryable registry, `readAll` doesn't scale. |
| `hardevine/laravel-session-tracker` | A DB write on the hot path of every request; Laravel-5-era codebase. |
| `diego-ninja/laravel-devices` | A 2FA / fingerprinting framework, DB-centric. |
| Jetstream Browser Sessions | Requires the `database` session driver — exactly the trade-off this package removes. |

## Non-Goals

No 2FA. No device fingerprinting. No geo-IP. No UI components. No request logging. No writing into
other sessions' payloads (a race condition by design). We integrate with the **framework**, not the
ecosystem.

---

## Testing

```bash
composer test        # pest
composer lint        # pint --test
composer analyse     # phpstan (level max)
composer check       # all of the above + coverage gate
```

The suite runs against a session driver of your choosing, which is how the "works with any driver"
claim is kept honest — CI runs all four legs:

```bash
SESSION_DRIVER=array vendor/bin/pest
SESSION_DRIVER=file  vendor/bin/pest
SESSION_DRIVER=redis vendor/bin/pest                      # needs a redis server
SESSION_DRIVER=redis REDIS_CLIENT=predis vendor/bin/pest
```

The registry database is parameterised the same way, and this matters more than it looks:
SQLite does not enforce column widths, does not validate UTF-8, and implements upsert and
`NOT NULL` defaults differently — so a green SQLite run cannot tell you whether the package
works on the database your application actually uses. CI runs MySQL 8 and PostgreSQL 16:

```bash
DB_CONNECTION=mysql DB_PORT=33061 DB_USERNAME=root     DB_PASSWORD=secret vendor/bin/pest
DB_CONNECTION=pgsql DB_PORT=54321 DB_USERNAME=postgres DB_PASSWORD=secret vendor/bin/pest
```

---

## Upgrade & support policy

- **Versioning:** strict [SemVer](https://semver.org) from v1.0.0. Breaking changes to the public
  API — the contracts in `src/Contracts`, the facade, the `HasUserSessions` trait, the model's
  public methods, event payloads and config keys — only ever land in a major release.
- **Not public API:** anything marked `@internal`, the exact SQL emitted, and the shape of log
  messages. These may change in a minor release.
- **Supported branches:** the current major receives features and fixes; the previous major receives
  security fixes for six months after the new major is tagged.
- **Laravel versions:** a Laravel release is supported while it receives official bug fixes. Dropping
  one is a major release for this package.
- **Migrations:** the shipped migration is published into your app, so it is yours. Schema changes in
  later versions ship as additional migrations with upgrade notes in the
  [CHANGELOG](CHANGELOG.md) — the original file is never rewritten under you.
- **Security:** report privately, see [SECURITY.md](SECURITY.md).

## License

The MIT License (MIT). See [LICENSE.md](LICENSE.md).
