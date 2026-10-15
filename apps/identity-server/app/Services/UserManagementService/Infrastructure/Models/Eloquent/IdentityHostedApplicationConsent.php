<?php

declare(strict_types=1);

namespace App\Services\UserManagementService\Infrastructure\Models\Eloquent;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class IdentityHostedApplicationConsent extends Model
{
    use HasUuids;

    protected $table = 'identity_hosted_application_consents';

    protected $fillable = ['hosted_application_id', 'user_id', 'project_account_id', 'terms_url', 'policy_revision', 'accepted_at'];

    protected function casts(): array
    {
        return ['accepted_at' => 'datetime'];
    }
}
