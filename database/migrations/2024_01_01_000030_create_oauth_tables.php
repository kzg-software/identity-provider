<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oauth_clients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->nullable()->constrained('applications')->cascadeOnDelete();
            $table->string('name');
            $table->string('client_id')->unique();
            $table->string('client_secret')->nullable();
            $table->json('allowed_grant_types')->nullable();
            $table->unsignedInteger('access_token_lifetime')->default(3600);
            $table->unsignedInteger('refresh_token_lifetime')->default(1209600);
            $table->unsignedInteger('id_token_lifetime')->default(3600);
            $table->boolean('pkce_required')->default(true);
            $table->boolean('secret_required')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('oauth_redirect_uris', function (Blueprint $table) {
            $table->id();
            $table->foreignId('oauth_client_id')->constrained('oauth_clients')->cascadeOnDelete();
            $table->string('uri');
            $table->string('type')->default('login'); // login, logout
            $table->timestamps();
        });

        Schema::create('oauth_scopes', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('label');
            $table->text('description')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        Schema::create('oauth_consents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('oauth_client_id')->constrained('oauth_clients')->cascadeOnDelete();
            $table->json('scopes');
            $table->timestamp('granted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'oauth_client_id'], 'oauth_consents_user_client_unique');
        });

        Schema::create('oauth_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('oauth_client_id')->constrained('oauth_clients')->cascadeOnDelete();
            $table->string('type'); // authorization_code, access_token, refresh_token, id_token
            $table->string('identifier')->unique();
            $table->text('token_hash')->nullable();
            $table->json('scopes')->nullable();
            $table->boolean('revoked')->default(false);
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('oidc_keys', function (Blueprint $table) {
            $table->id();
            $table->string('kid')->unique();
            $table->string('algorithm')->default('RS256');
            $table->text('public_key');
            $table->text('private_key_encrypted');
            $table->boolean('is_active')->default(true);
            $table->timestamp('rotated_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oidc_keys');
        Schema::dropIfExists('oauth_tokens');
        Schema::dropIfExists('oauth_consents');
        Schema::dropIfExists('oauth_scopes');
        Schema::dropIfExists('oauth_redirect_uris');
        Schema::dropIfExists('oauth_clients');
    }
};
