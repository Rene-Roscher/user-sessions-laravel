<?php

declare(strict_types=1);

namespace ReneRoscher\UserSessions\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use ReneRoscher\UserSessions\Models\Concerns\HasUserSessions;

class UuidUser extends Authenticatable
{
    use HasUserSessions;
    use HasUuids;
    use Notifiable;

    protected $table = 'uuid_users';

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
