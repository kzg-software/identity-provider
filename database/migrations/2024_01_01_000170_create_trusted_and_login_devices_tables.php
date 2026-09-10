<?php

use App\Services\SessionTracker;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Geraete, denen der Benutzer auf der Zwei-Faktor-Seite bewusst
        // vertraut hat ("Gerät merken"). Solange ein gueltiger Eintrag samt
        // Cookie vorliegt, wird der zweite Faktor auf diesem Geraet nicht
        // erneut abgefragt.
        Schema::create('trusted_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->string('label')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['user_id', 'expires_at']);
        });

        // Bekannte Anmeldegeraete je Konto, grob anhand von Browser,
        // Betriebssystem und Geraetetyp. Taucht eine neue Kombination auf,
        // wird der Benutzer einmalig per E-Mail informiert.
        Schema::create('user_login_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('signature', 64);
            $table->string('label')->nullable();
            $table->string('last_ip_address', 45)->nullable();
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->unsignedInteger('login_count')->default(1);
            $table->timestamps();

            $table->unique(['user_id', 'signature']);
        });

        $this->seedKnownDevicesFromSessions();
    }

    /**
     * Bereits gesehene Geräte aus user_sessions übernehmen, damit die neue
     * "neues Gerät"-E-Mail beim ersten Login nach dem Update nicht für jedes
     * Bestandsgerät einmal ausgelöst wird.
     */
    private function seedKnownDevicesFromSessions(): void
    {
        if (! Schema::hasTable('user_sessions')) {
            return;
        }

        DB::table('user_sessions')->orderBy('id')->chunkById(500, function ($sessions) {
            $rows = [];

            foreach ($sessions as $s) {
                if (! $s->user_id) {
                    continue;
                }

                $signature = SessionTracker::signatureFromParts($s->browser, $s->platform, $s->device);
                $seenAt = $s->login_at ?? $s->last_activity_at ?? now();

                $key = $s->user_id.'|'.$signature;
                $rows[$key] ??= [
                    'user_id' => $s->user_id,
                    'signature' => $signature,
                    'label' => SessionTracker::deviceLabel($s->user_agent),
                    'last_ip_address' => $s->ip_address,
                    'first_seen_at' => $seenAt,
                    'last_seen_at' => $seenAt,
                    'login_count' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            foreach ($rows as $row) {
                DB::table('user_login_devices')->updateOrInsert(
                    ['user_id' => $row['user_id'], 'signature' => $row['signature']],
                    $row,
                );
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_login_devices');
        Schema::dropIfExists('trusted_devices');
    }
};
