<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sso_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('session_id')->index();
            $table->string('protocol', 10);
            $table->foreignId('saml_service_provider_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('oauth_client_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name_id')->nullable();
            $table->string('session_index')->nullable();
            $table->timestamps();
        });

        Schema::table('oauth_clients', function (Blueprint $table) {
            $table->string('backchannel_logout_uri', 2048)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('oauth_clients', function (Blueprint $table) {
            $table->dropColumn('backchannel_logout_uri');
        });

        Schema::dropIfExists('sso_sessions');
    }
};
