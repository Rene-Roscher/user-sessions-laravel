<?php

declare(strict_types=1);

use ReneRoscher\UserSessions\Support\Device;
use ReneRoscher\UserSessions\Support\DeviceType;
use ReneRoscher\UserSessions\Support\NativeUserAgentParser;

it('parses Chrome on macOS correctly', function (): void {
    $parser = new NativeUserAgentParser;

    $device = $parser->parse('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/119.0.0.0 Safari/537.36');

    expect($device->type)->toBe(DeviceType::Desktop)
        ->and($device->browser)->toBe('Chrome')
        ->and($device->platform)->toBe('macOS')
        ->and($device->label())->toBe('Chrome on macOS');
});

it('parses Safari on iOS correctly', function (): void {
    $parser = new NativeUserAgentParser;

    $device = $parser->parse('Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1');

    expect($device->type)->toBe(DeviceType::Mobile)
        ->and($device->browser)->toBe('Safari')
        ->and($device->platform)->toBe('iOS');
});

it('parses Firefox on Windows correctly', function (): void {
    $parser = new NativeUserAgentParser;

    $device = $parser->parse('Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:120.0) Gecko/20100101 Firefox/120.0');

    expect($device->type)->toBe(DeviceType::Desktop)
        ->and($device->browser)->toBe('Firefox')
        ->and($device->platform)->toBe('Windows');
});

it('detects tablets correctly', function (): void {
    $parser = new NativeUserAgentParser;

    $device = $parser->parse('Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1');

    expect($device->type)->toBe(DeviceType::Tablet);
});

it('detects Android mobile correctly', function (): void {
    $parser = new NativeUserAgentParser;

    $device = $parser->parse('Mozilla/5.0 (Linux; Android 13; SM-S901B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/119.0.0.0 Mobile Safari/537.36');

    expect($device->type)->toBe(DeviceType::Mobile)
        ->and($device->browser)->toBe('Chrome')
        ->and($device->platform)->toBe('Android');
});

it('detects Android tablet via non-Mobile pattern', function (): void {
    $parser = new NativeUserAgentParser;

    $device = $parser->parse('Mozilla/5.0 (Linux; Android 13; SM-X900) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/119.0.0.0 Safari/537.36');

    expect($device->type)->toBe(DeviceType::Tablet);
});

it('detects bots', function (): void {
    $parser = new NativeUserAgentParser;

    $device = $parser->parse('Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)');

    expect($device->type)->toBe(DeviceType::Bot);
});

it('handles null user agent as Unknown', function (): void {
    $parser = new NativeUserAgentParser;

    $device = $parser->parse(null);

    expect($device->type)->toBe(DeviceType::Unknown)
        ->and($device->platform)->toBeNull()
        ->and($device->browser)->toBeNull();
});

it('handles empty string as Unknown', function (): void {
    $parser = new NativeUserAgentParser;

    $device = $parser->parse('');

    expect($device->type)->toBe(DeviceType::Unknown);
});

it('handles garbage input gracefully', function (): void {
    $parser = new NativeUserAgentParser;

    $device = $parser->parse('garbage input not a user agent at all');

    expect($device->type)->toBe(DeviceType::Unknown);
});

it('detects Edge browser', function (): void {
    $parser = new NativeUserAgentParser;

    $device = $parser->parse('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/119.0.0.0 Safari/537.36 Edg/119.0.0.0');

    expect($device->browser)->toBe('Edge');
});

it('detects Opera browser', function (): void {
    $parser = new NativeUserAgentParser;

    $device = $parser->parse('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/119.0.0.0 Safari/537.36 OPR/105.0.0.0');

    expect($device->browser)->toBe('Opera');
});

it('detects Linux platform', function (): void {
    $parser = new NativeUserAgentParser;

    $device = $parser->parse('Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/119.0.0.0 Safari/537.36');

    expect($device->platform)->toBe('Linux')
        ->and($device->type)->toBe(DeviceType::Desktop);
});

it('creates device label for unknown', function (): void {
    $device = new Device(DeviceType::Unknown);

    expect($device->label())->toBe('Unknown device');
});

it('creates device label with platform only', function (): void {
    $device = new Device(DeviceType::Desktop, 'macOS');

    expect($device->label())->toBe('Unknown browser on macOS');
});

/**
 * PLAN §12 test 21 asks for a corpus of real-world strings rather than one happy-path
 * example per branch. These are verbatim User-Agents, including the awkward ones: bots
 * that impersonate browsers, in-app webviews, smart TVs and console browsers.
 *
 * The contract is deliberately modest — the parser must never throw and must never
 * mislabel a phone as a desktop; exact browser naming is best-effort by design.
 */
dataset('user agent corpus', [
    'Chrome Windows 11' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36', DeviceType::Desktop],
    'Safari iPhone 17' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.1 Mobile/15E148 Safari/604.1', DeviceType::Mobile],
    'Chrome Android phone' => ['Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36', DeviceType::Mobile],
    'Samsung Internet' => ['Mozilla/5.0 (Linux; Android 13; SAMSUNG SM-S918B) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/23.0 Chrome/115.0.0.0 Mobile Safari/537.36', DeviceType::Mobile],
    'iPad Safari' => ['Mozilla/5.0 (iPad; CPU OS 17_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.1 Safari/605.1', DeviceType::Tablet],
    'Firefox Ubuntu' => ['Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:121.0) Gecko/20100101 Firefox/121.0', DeviceType::Desktop],
    'Edge macOS' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 Edg/120.0.0.0', DeviceType::Desktop],
    'Googlebot' => ['Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)', DeviceType::Bot],
    'Bingbot' => ['Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)', DeviceType::Bot],
    'curl' => ['curl/8.4.0', DeviceType::Unknown],
    'Facebook in-app webview' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 16_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 [FBAN/FBIOS;FBAV/440.0.0.32.113]', DeviceType::Mobile],
    'Android tablet' => ['Mozilla/5.0 (Linux; Android 13; SM-X700) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/119.0.0.0 Safari/537.36', DeviceType::Tablet],
]);

it('classifies the real-world corpus without throwing', function (string $userAgent, DeviceType $expected): void {
    $device = (new NativeUserAgentParser)->parse($userAgent);

    expect($device->type)->toBe($expected)
        ->and($device->label())->toBeString()->not->toBeEmpty();
})->with('user agent corpus');

it('survives hostile and malformed user agents', function (string $userAgent): void {
    // Never throw, never blow past the column width, always produce a printable label.
    $device = (new NativeUserAgentParser)->parse($userAgent);

    expect($device->label())->toBeString()->not->toBeEmpty()
        ->and(mb_strlen((string) $device->platform))->toBeLessThanOrEqual(50)
        ->and(mb_strlen((string) $device->browser))->toBeLessThanOrEqual(50);
})->with([
    'empty' => [''],
    'whitespace' => ['   '],
    'null bytes' => ["Mozilla/5.0\0\0"],
    'newlines' => ["Mozilla/5.0\r\nX-Injected: 1"],
    'huge' => [str_repeat('A', 8192)],
    'html' => ['<script>alert(1)</script>'],
    'sql-ish' => ["' OR 1=1 --"],
    'unicode' => ['🦊 Mozilla/5.0 (Ünicöde; 日本語)'],
    'percent encoded' => ['%00%0a%0dMozilla'],
]);
