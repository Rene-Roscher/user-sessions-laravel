<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Session;
use ReneRoscher\UserSessions\Models\UserSession;
use ReneRoscher\UserSessions\Tests\DisabledFeaturesTestCase;
use ReneRoscher\UserSessions\Tests\EnabledFeaturesTestCase;
use ReneRoscher\UserSessions\Tests\FeatureTestCase;
use ReneRoscher\UserSessions\Tests\TestCase;

uses(TestCase::class)->in('Unit', 'Architecture');

uses(FeatureTestCase::class)->in('Feature');

uses(EnabledFeaturesTestCase::class)->in('Integration');

uses(DisabledFeaturesTestCase::class)->in('Toggles');

/**
 * Put a live entry for this session id into the configured session store.
 *
 * Registry rows only exist because a real session existed, and on introspectable drivers
 * (file, redis) the device list asks the store whether a session is still alive. A test
 * that fakes a row without a matching store entry is therefore faking a DEAD session —
 * call this whenever the row is meant to represent a live device.
 */
function storeSession(string $sessionId, string $payload = ''): void
{
    Session::getHandler()->write($sessionId, $payload);
}

expect()->extend('toBeUserSession', function () {
    return $this->toBeInstanceOf(UserSession::class);
});

/**
 * Every fully-qualified class/interface/enum/trait name defined under src/.
 *
 * @return list<class-string>
 */
function userSessionsSrcClasses(): array
{
    $base = dirname(__DIR__).'/src';

    /** @var list<class-string> $classes */
    $classes = [];

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $relative = substr($file->getPathname(), strlen($base) + 1, -4);
        $fqcn = 'ReneRoscher\\UserSessions\\'.str_replace('/', '\\', $relative);

        if (class_exists($fqcn) || interface_exists($fqcn) || enum_exists($fqcn) || trait_exists($fqcn)) {
            $classes[] = $fqcn;
        }
    }

    sort($classes);

    return $classes;
}
