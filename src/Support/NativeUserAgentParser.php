<?php

declare(strict_types=1);

namespace ReneRoscher\UserSessions\Support;

use ReneRoscher\UserSessions\Contracts\UserAgentParser;

final class NativeUserAgentParser implements UserAgentParser
{
    private const BROWSER_PATTERNS = [
        'edge' => '/Edg\/([\d.]+)/i',
        'opera' => '/OPR\/([\d.]+)/i',
        'chrome' => '/Chrome\/([\d.]+)/i',
        'firefox' => '/Firefox\/([\d.]+)/i',
        'safari' => '/Version\/([\d.]+).*Safari/i',
        'ie' => '/MSIE ([\d.]+)|Trident\/.*rv:([\d.]+)/i',
    ];

    private const PLATFORM_PATTERNS = [
        'windows' => '/Windows NT ([\d.]+)/i',
        'macos' => '/Mac OS X ([\d_]+)/i',
        'ios' => '/iPhone|iPad|iPod/i',
        'android' => '/Android ([\d.]+)/i',
        'linux' => '/Linux/i',
        'chromeos' => '/CrOS/i',
    ];

    private const BOT_PATTERNS = [
        '/bot\b/i', '/crawl/i', '/spider/i', '/slurp/i', '/feed/i',
        '/fetcher/i', '/monitor/i', '/scraper/i', '/preview/i',
    ];

    private const MOBILE_PATTERNS = [
        '/iPhone/i', '/Android.*Mobile/i', '/Windows Phone/i',
        '/BlackBerry/i', '/Opera Mini/i', '/Mobile/i',
    ];

    private const TABLET_PATTERNS = [
        '/iPad/i', '/Android(?!.*Mobile)/i', '/Tablet/i', '/Silk/i',
    ];

    public function parse(?string $userAgent): Device
    {
        if ($userAgent === null || trim($userAgent) === '') {
            return new Device(DeviceType::Unknown);
        }

        if ($this->isBot($userAgent)) {
            return new Device(
                DeviceType::Bot,
                $this->detectPlatform($userAgent),
                $this->detectBrowser($userAgent),
            );
        }

        $type = $this->detectType($userAgent);
        $platform = $this->detectPlatform($userAgent);
        $browser = $this->detectBrowser($userAgent);

        if ($platform === 'ios') {
            $platform = 'iOS';
        } elseif ($platform === 'macos') {
            $platform = 'macOS';
        } elseif ($platform !== null) {
            $platform = ucfirst($platform);
        }

        return new Device($type, $platform, $browser);
    }

    private function isBot(string $ua): bool
    {
        foreach (self::BOT_PATTERNS as $pattern) {
            if (preg_match($pattern, $ua)) {
                return true;
            }
        }

        return false;
    }

    private function detectType(string $ua): DeviceType
    {
        foreach (self::TABLET_PATTERNS as $pattern) {
            if (preg_match($pattern, $ua)) {
                return DeviceType::Tablet;
            }
        }

        foreach (self::MOBILE_PATTERNS as $pattern) {
            if (preg_match($pattern, $ua)) {
                return DeviceType::Mobile;
            }
        }

        if (preg_match('/Windows NT|Mac OS X|Linux|CrOS/i', $ua)) {
            return DeviceType::Desktop;
        }

        return DeviceType::Unknown;
    }

    private function detectBrowser(string $ua): ?string
    {
        foreach (self::BROWSER_PATTERNS as $name => $pattern) {
            if (preg_match($pattern, $ua)) {
                return match ($name) {
                    'edge' => 'Edge',
                    'chrome' => 'Chrome',
                    'firefox' => 'Firefox',
                    'safari' => 'Safari',
                    'opera' => 'Opera',
                    'ie' => 'Internet Explorer',
                };
            }
        }

        return null;
    }

    private function detectPlatform(string $ua): ?string
    {
        foreach (self::PLATFORM_PATTERNS as $name => $pattern) {
            if (preg_match($pattern, $ua)) {
                return $name;
            }
        }

        return null;
    }
}
