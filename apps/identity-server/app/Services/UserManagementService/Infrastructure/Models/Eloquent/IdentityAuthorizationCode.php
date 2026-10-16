<?php

declare(strict_types=1);

namespace App\Services\UserManagementService\Infrastructure\Models\Eloquent;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class IdentityAuthorizationCode extends Model
{
    use HasUuids;

    protected $table = 'identity_authorization_codes';

    protected $fillable = [
        'user_id',
        'project_id',
        'client_id',
        'source_refresh_family_id',
        'code_hash',
        'reauthentication_cipher',
        'redirect_uri',
        'expires_at',
        'consumed_at',
    ];

    protected $hidden = ['code_hash'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }
}
