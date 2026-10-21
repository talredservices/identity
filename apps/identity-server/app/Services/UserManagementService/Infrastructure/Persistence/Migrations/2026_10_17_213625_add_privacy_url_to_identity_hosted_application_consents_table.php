<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('identity_hosted_application_consents', function (Blueprint $table): void {
            $table->string('privacy_url', 2048)->nullable()->after('terms_url');
        });
    }

    public function down(): void
    {
        Schema::table('identity_hosted_application_consents', function (Blueprint $table): void {
            $table->dropColumn('privacy_url');
        });
    }
};
