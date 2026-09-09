<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pro Kategorie: bekommt der Benutzer diese Benachrichtigung auch per
     * E-Mail? Fehlende Kategorie bedeutet "ja". Sicherheitsrelevante Hinweise
     * gehen immer per E-Mail und an die Glocke, unabhaengig davon.
     * Siehe App\Support\NotificationCategories.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('notification_email_prefs')->nullable()->after('email_verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('notification_email_prefs');
        });
    }
};
