<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saml_service_providers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->nullable()->constrained('applications')->cascadeOnDelete();
            $table->string('name');
            $table->string('entity_id')->unique();
            $table->string('acs_url');
            $table->string('slo_url')->nullable();
            $table->string('name_id_format')->default('urn:oasis:names:tc:SAML:2.0:nameid-format:persistent');
            $table->text('certificate')->nullable();
            $table->boolean('sign_assertions')->default(true);
            $table->boolean('sign_responses')->default(true);
            $table->boolean('encrypt_assertions')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('saml_attribute_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('saml_service_provider_id')->constrained('saml_service_providers')->cascadeOnDelete();
            $table->string('saml_attribute');
            $table->string('user_attribute');
            $table->timestamps();
        });

        Schema::create('saml_certificates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type')->default('signing'); // signing, encryption
            $table->text('certificate');
            $table->text('private_key_encrypted');
            $table->string('fingerprint')->nullable();
            $table->string('algorithm')->default('sha256');
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saml_certificates');
        Schema::dropIfExists('saml_attribute_mappings');
        Schema::dropIfExists('saml_service_providers');
    }
};
