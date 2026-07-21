<?php

declare(strict_types=1);

namespace ReneRoscher\UserSessions\Support;

readonly class Device
{
    public function __construct(
        public DeviceType $type,
        public ?string $platform = null,
        public ?string $browser = null,
    ) {}

    public function label(): string
    {
        if ($this->platform !== null && $this->browser !== null) {
            return $this->browser.' on '.$this->platform;
        }

        if ($this->browser !== null) {
            return $this->browser;
        }

        if ($this->platform !== null) {
            return 'Unknown browser on '.$this->platform;
        }

        return 'Unknown device';
    }
}
