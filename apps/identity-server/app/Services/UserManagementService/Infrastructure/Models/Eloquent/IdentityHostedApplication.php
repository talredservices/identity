<?php

declare(strict_types=1);

namespace App\Services\UserManagementService\Infrastructure\Models\Eloquent;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class IdentityHostedApplication extends Model
{
    use HasUuids;

    protected $table = 'identity_hosted_applications';

    protected $fillable = [
        'project_id',
        'primary_client_id',
        'sandbox_client_id',
        'key',
        'name',
        'application_url',
        'callback_url',
        'authentication_mode',
        'password_reset_url',
        'policy_revision',
        'auth_page_set',
        'appearance',
        'authentication',
        'logo_path',
        'status',
    ];

    protected function casts(): array
    {
        return ['appearance' => 'array', 'authentication' => 'array'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(IdentityProject::class, 'project_id');
    }

    public function primaryClient(): BelongsTo
    {
        return $this->belongsTo(IdentityProjectClient::class, 'primary_client_id');
    }

    public function sandboxClient(): BelongsTo
    {
        return $this->belongsTo(IdentityProjectClient::class, 'sandbox_client_id');
    }
}
