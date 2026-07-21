# Contributing

Thanks for considering a contribution! A few guidelines keep the package fast, small and correct.

## Getting started

```bash
git clone https://github.com/Rene-Roscher/user-sessions-laravel
cd user-sessions-laravel
composer install
composer check   # lint + static analysis + tests with coverage
```

## Before you open a pull request

Run the full check suite and make sure it is green:

```bash
composer lint      # Laravel Pint (code style)
composer analyse   # PHPStan level max + Larastan
composer test      # Pest
```

- **Every behaviour needs a test.** The suite treats each documented claim as a test; new behaviour
  follows the same rule.
- **Keep the hot path DB-free.** Anything that runs per request must not touch the database. The
  `GuestFloodTest` and the architecture tests guard this — don't weaken them.
- **Respect Octane.** No static state, no `Request`/`Authenticatable` held as a property, no
  `runningInConsole()` for request detection. `tests/Architecture/ArchTest.php` enforces this.
- **Match the style.** Pint (Laravel preset) and `declare(strict_types=1)` everywhere.

## Scope

Please check the [Non-Goals](README.md#non-goals) before proposing a feature. 2FA, fingerprinting,
geo-IP, UI components and request logging are intentionally out of scope and belong in separate
packages.

## Reporting bugs

Open an issue with a minimal reproduction: Laravel version, PHP version, session driver, and the
smallest snippet that shows the problem. For security issues, see [SECURITY.md](SECURITY.md) —
please do **not** open a public issue.
