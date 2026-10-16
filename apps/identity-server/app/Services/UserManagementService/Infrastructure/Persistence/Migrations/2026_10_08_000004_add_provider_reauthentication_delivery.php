<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('identity_provider_transactions', function (Blueprint $table): void {
            $table->text('source_token_cipher')->nullable();
            $table->string('sensitive_operation', 32)->nullable();
        });
        Schema::table('identity_authorization_codes', function (Blueprint $table): void {
            $table->text('reauthentication_cipher')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('identity_provider_transactions', fn (Blueprint $table) => $table->dropColumn(['source_token_cipher', 'sensitive_operation']));
        Schema::table('identity_authorization_codes', fn (Blueprint $table) => $table->dropColumn('reauthentication_cipher'));
    }
};
