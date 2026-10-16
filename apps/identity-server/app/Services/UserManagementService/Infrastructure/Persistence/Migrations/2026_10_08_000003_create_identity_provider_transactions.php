<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('identity_provider_transactions', function (Blueprint $table): void {
            $table->string('state_hash', 64)->primary();
            $table->uuid('application_id');
            $table->uuid('client_id');
            $table->text('client_secret_cipher');
            $table->string('consumer_state', 180);
            $table->text('callback_url');
            $table->string('policy_revision', 128)->nullable();
            $table->boolean('terms_accepted')->default(false);
            $table->timestamp('expires_at')->index();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('identity_provider_transactions');
    }
};
