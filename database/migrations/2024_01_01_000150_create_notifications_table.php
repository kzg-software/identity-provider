<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Benachrichtigungen für die Glocke in der Navigationsleiste.
     *
     * Jede Zeile gehört genau einem Empfänger (user_id). Systemweite Hinweise
     * für Administratoren werden pro Admin einzeln angelegt (Fan-out), damit
     * der Gelesen-Status je Person stimmt.
     */
    public function up(): void
    {
        Schema::create('user_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->string('type');
            $table->string('level')->default('info');
            $table->string('title');
            $table->text('body')->nullable();
            $table->string('action_url')->nullable();
            $table->string('dedupe_key')->nullable();

            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'dedupe_key']);
            $table->index(['user_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_notifications');
    }
};
