<?php

declare(strict_types=1);

arch('all source files declare strict types')
    ->expect('ReneRoscher\UserSessions')
    ->toUseStrictTypes();

arch('contracts are interfaces only')
    ->expect('ReneRoscher\UserSessions\Contracts')
    ->toBeInterfaces();

arch('events carry no behaviour beyond broadcasting')
    ->expect('ReneRoscher\UserSessions\Events')
    ->toBeClasses();

arch('no debugging leftovers')
    ->expect('ReneRoscher\UserSessions')
    ->not->toUse(['dd', 'dump', 'var_dump', 'ray', 'die', 'exit', 'print_r']);

arch('no env() reads outside the config file')
    ->expect('ReneRoscher\UserSessions')
    ->not->toUse('env');

// §12.4 — Octane: no request-scoped state may live statically on any src class,
// otherwise it would bleed across requests served by the same booted worker.
test('no source class declares static properties', function (): void {
    $classes = userSessionsSrcClasses();

    // Guard against a vacuous pass: the discovery helper must actually find classes.
    expect($classes)->not->toBeEmpty();

    foreach ($classes as $class) {
        $reflection = new ReflectionClass($class);

        $ownStaticProperties = array_values(array_filter(
            $reflection->getProperties(ReflectionProperty::IS_STATIC),
            fn (ReflectionProperty $property): bool => $property->getDeclaringClass()->getName() === $class,
        ));

        $names = array_map(fn (ReflectionProperty $p): string => $p->getName(), $ownStaticProperties);

        expect($names)->toBe([], "{$class} declares static properties: ".implode(', ', $names));
    }
});

// §5.1 — the stateless services must never hold a Request or Authenticatable as a
// property; all such values are passed explicitly per call (Octane rule as API design).
test('no container-resolved service holds a request-scoped property', function (): void {
    // Every class under src/ is checked, not just the two originally named: the container
    // has grown more singletons since (context sharer, activity resolver), and any of them
    // holding a Request or a user would survive across requests under Octane and serve one
    // user's device to the next. The Models are exempt — they ARE per-request data.
    $exempt = fn (string $class): bool => str_contains($class, '\\Models\\')
        || str_contains($class, '\\Events\\')
        || str_contains($class, '\\Exceptions\\');

    $checked = 0;
    $violations = [];

    foreach (userSessionsSrcClasses() as $class) {
        if ($exempt($class)) {
            continue;
        }

        $reflection = new ReflectionClass($class);

        if ($reflection->isInterface()) {
            continue;
        }

        $checked++;

        foreach ($reflection->getProperties() as $property) {
            $type = $property->getType();
            $typeName = $type instanceof ReflectionNamedType ? $type->getName() : (string) $type;

            foreach (['Http\\Request', 'Contracts\\Auth\\Authenticatable', 'Session\\Store'] as $forbidden) {
                if (str_contains($typeName, $forbidden)) {
                    $violations[] = "{$class}::\${$property->getName()} holds {$typeName}";
                }
            }
        }
    }

    // Collected and asserted via toBe(), which really does take a failure message.
    // The previous per-property `->not->toContain($needle, $message)` could never fail:
    // toContain() has no message parameter, so the message became a second needle, and
    // a not-expectation passes as soon as ANY needle misses — which the message always did.
    expect($violations)->toBe([], implode(PHP_EOL, $violations));

    // Guard against the loop silently checking nothing.
    expect($checked)->toBeGreaterThan(8);
});

// §9.1 — Swoole defines its own global defer(); Laravel only registers its helper when
// none exists, so the middleware must import the namespaced helper explicitly.
test('middleware imports the namespaced defer helper', function (): void {
    $source = (string) file_get_contents(dirname(__DIR__, 2).'/src/Middleware/RecordSessions.php');

    expect($source)->toContain('use function Illuminate\Support\defer;');
});
