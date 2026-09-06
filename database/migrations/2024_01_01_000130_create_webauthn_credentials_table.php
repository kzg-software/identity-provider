<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webauthn_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            // Vom Nutzer vergebener Name ("YubiKey am Schlüsselbund", "MacBook").
            $table->string('name');

            // Die von der Anmeldung übergebene Credential-ID (base64url).
            $table->string('credential_id', 512)->unique();

            // Öffentlicher Schlüssel im PEM-Format.
            $table->text('public_key');

            $table->string('aaguid')->nullable();
            $table->string('attestation_format')->nullable();
            $table->json('transports')->nullable();

            // Signaturzähler des Authenticators – schützt vor geklonten Geräten.
            $table->unsignedBigInteger('sign_count')->default(0);

            // Wurde als auffindbarer Schlüssel (Passkey) angelegt?
            $table->boolean('discoverable')->default(false);
            // Kann das Gerät den Nutzer verifizieren (Biometrie/PIN)?
            $table->boolean('uv')->default(false);

            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webauthn_credentials');
    }
};
