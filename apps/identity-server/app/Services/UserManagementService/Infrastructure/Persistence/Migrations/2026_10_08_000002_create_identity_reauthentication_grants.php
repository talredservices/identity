<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('identity_reauthentication_grants', function (Blueprint $table): void {
            $table->string('grant_hash', 64)->primary();
            $table->uuid('user_id')->index();
            $table->uuid('project_id');
            $table->uuid('client_id');
            $table->string('session_family_id');
            $table->string('operation', 32);
            $table->timestamp('expires_at')->index();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('identity_reauthentication_grants');
    }
};
