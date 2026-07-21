<?php

declare(strict_types=1);

namespace ReneRoscher\UserSessions\Tests\Fixtures\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use ReneRoscher\UserSessions\Models\Concerns\HasUserSessions;

class User extends Authenticatable
{
    use HasUserSessions;
    use Notifiable;

    protected $table = 'users';

    protected $guarded = [];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    public function casts(): array
    {
        return [
            'password' => 'hashed',
        ];
    }
}
