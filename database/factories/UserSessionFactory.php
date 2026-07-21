<?php

declare(strict_types=1);

namespace ReneRoscher\UserSessions\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use ReneRoscher\UserSessions\Models\UserSession;
use ReneRoscher\UserSessions\Support\DeviceType;

class UserSessionFactory extends Factory
{
    protected $model = UserSession::class;

    public function definition(): array
    {
        return [
            // No explicit id: HasUlids generates it, which is also what proves the model
            // works for plain Eloquent creation and not only for the recorder's upsert.
            'session_id' => Str::random(40),
            'user_type' => 'App\\Models\\User',
            'user_id' => $this->faker->randomDigitNotNull(),
            'ip_address' => $this->faker->ipv4(),
            'user_agent' => $this->faker->userAgent(),
            'device_type' => $this->faker->randomElement(DeviceType::cases())->value,
            'platform' => $this->faker->randomElement(['macOS', 'Windows', 'Linux', 'Android', 'iOS']),
            'browser' => $this->faker->randomElement(['Chrome', 'Firefox', 'Safari', 'Edge']),
            'last_activity' => now(),
            'revoked_at' => null,
            'created_at' => now(),
        ];
    }

    public function active(): static
    {
        return $this->state(fn (array $attrs): array => [
            'revoked_at' => null,
            'last_activity' => now(),
        ]);
    }

    public function revoked(): static
    {
        return $this->state(fn (array $attrs): array => [
            'revoked_at' => now()->subHour(),
        ]);
    }

    public function expired(): static
    {
        $lifetime = (int) config('session.lifetime', 120);

        return $this->state(fn (array $attrs): array => [
            'last_activity' => now()->subMinutes($lifetime + 1),
            'revoked_at' => null,
        ]);
    }
}
