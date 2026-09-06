<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('two_factor_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();

            // TOTP-Geheimnis (verschlüsselt) und Bestätigungszeitpunkt.
            $table->text('totp_secret')->nullable();
            $table->timestamp('totp_confirmed_at')->nullable();

            // Wiederherstellungscodes (verschlüsselt, JSON-Liste). Gelten für
            // alle Zwei-Faktor-Methoden des Kontos.
            $table->text('recovery_codes')->nullable();

            $table->timestamps();
        });

        // Vorhandenen Zwei-Faktor-Zustand aus local_accounts übernehmen. Der
        // frühere Aufbau lag nur bei lokalen Konten; künftig gilt Zwei-Faktor
        // (TOTP + Passkeys) für lokale und AD-Konten gleichermaßen.
        if (Schema::hasColumn('local_accounts', 'two_factor_secret')) {
            $now = now();

            foreach (DB::table('local_accounts')->whereNotNull('two_factor_secret')->get() as $account) {
                DB::table('two_factor_credentials')->insert([
                    'user_id' => $account->user_id,
                    'totp_secret' => $account->two_factor_secret,
                    'totp_confirmed_at' => $account->two_factor_confirmed_at,
                    'recovery_codes' => $account->two_factor_recovery_codes,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            Schema::table('local_accounts', function (Blueprint $table) {
                $table->dropColumn([
                    'two_factor_secret',
                    'two_factor_recovery_codes',
                    'two_factor_confirmed_at',
                ]);
            });
        }

        Schema::table('users', function (Blueprint $table) {
            // Stabile, zufällige Kennung des Nutzers gegenüber Authenticatoren
            // (WebAuthn user handle). Wird bei der ersten Passkey-Registrierung
            // erzeugt und danach nicht mehr geändert.
            $table->string('webauthn_user_handle', 64)->nullable()->unique()->after('remember_token');
        });
    }

    public function down(): void
    {
        Schema::table('local_accounts', function (Blueprint $table) {
            $table->string('two_factor_secret')->nullable();
            $table->json('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
        });

        if (Schema::hasTable('two_factor_credentials')) {
            foreach (DB::table('two_factor_credentials')->get() as $row) {
                DB::table('local_accounts')->where('user_id', $row->user_id)->update([
                    'two_factor_secret' => $row->totp_secret,
                    'two_factor_recovery_codes' => $row->recovery_codes,
                    'two_factor_confirmed_at' => $row->totp_confirmed_at,
                ]);
            }
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('webauthn_user_handle');
        });

        Schema::dropIfExists('two_factor_credentials');
    }
};
