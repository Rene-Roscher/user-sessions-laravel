<?php

declare(strict_types=1);

namespace ReneRoscher\UserSessions\Tests\Fixtures\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use ReneRoscher\UserSessions\Tests\Fixtures\Models\Admin;
use ReneRoscher\UserSessions\Tests\Fixtures\Models\User;

class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            'email' => $this->faker->unique()->safeEmail(),
            'password' => 'password',
        ];
    }
}

class AdminFactory extends Factory
{
    protected $model = Admin::class;

    public function definition(): array
    {
        return [
            'email' => $this->faker->unique()->safeEmail(),
            'password' => 'password',
        ];
    }
}
