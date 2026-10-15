<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('identity_hosted_applications', function (Blueprint $table): void {
            // Existing records keep hosted behavior until their consumer passes cutover.
            $table->string('authentication_mode', 16)->default('hosted');
            $table->text('password_reset_url')->nullable();
            $table->string('policy_revision', 128)->nullable();
        });
        Schema::table('identity_hosted_application_consents', function (Blueprint $table): void {
            $table->string('policy_revision', 128)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('identity_hosted_application_consents', function (Blueprint $table): void {
            $table->dropColumn('policy_revision');
        });
        Schema::table('identity_hosted_applications', function (Blueprint $table): void {
            $table->dropColumn(['authentication_mode', 'password_reset_url', 'policy_revision']);
        });
    }
};
