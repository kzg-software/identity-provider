<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saml_replay_protections', function (Blueprint $table) {
            $table->id();
            $table->string('message_id')->unique();
            $table->string('type')->default('AuthnRequest'); // AuthnRequest, LogoutRequest
            $table->timestamp('seen_at');
        });

        Schema::table('saml_service_providers', function (Blueprint $table) {
            $table->boolean('require_signed_requests')->default(false)->after('encrypt_assertions');
        });
    }

    public function down(): void
    {
        Schema::table('saml_service_providers', function (Blueprint $table) {
            $table->dropColumn('require_signed_requests');
        });

        Schema::dropIfExists('saml_replay_protections');
    }
};
