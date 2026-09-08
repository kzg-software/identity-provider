<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('oauth_clients', function (Blueprint $table) {
            $table->json('allowed_response_types')->nullable()->after('allowed_grant_types');
            $table->string('id_token_signed_response_alg')->default('RS256')->after('id_token_lifetime');
        });

        Schema::table('saml_service_providers', function (Blueprint $table) {
            $table->string('slo_binding')->default('urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect')->after('slo_url');
            $table->string('sign_algorithm')->default('sha256')->after('require_signed_requests');
            $table->unsignedInteger('session_lifetime_minutes')->nullable()->after('sign_algorithm');
            $table->boolean('want_name_id_encrypted')->default(false)->after('encrypt_assertions');
        });
    }

    public function down(): void
    {
        Schema::table('oauth_clients', function (Blueprint $table) {
            $table->dropColumn(['allowed_response_types', 'id_token_signed_response_alg']);
        });

        Schema::table('saml_service_providers', function (Blueprint $table) {
            $table->dropColumn(['slo_binding', 'sign_algorithm', 'session_lifetime_minutes', 'want_name_id_encrypted']);
        });
    }
};
