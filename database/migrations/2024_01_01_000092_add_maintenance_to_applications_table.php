<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->boolean('maintenance_mode')->default(false)->after('is_active');
            $table->text('maintenance_message')->nullable()->after('maintenance_mode');
            // Eine Zeile pro Eintrag: Benutzername oder @Gruppenname. Lokale
            // Administratoren dürfen unabhängig davon immer rein.
            $table->text('maintenance_allow')->nullable()->after('maintenance_message');
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn(['maintenance_mode', 'maintenance_message', 'maintenance_allow']);
        });
    }
};
