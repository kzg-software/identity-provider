<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('directories', function (Blueprint $table) {
            // keep | disable | delete
            // Wie mit Benutzern verfahren wird, die bei einer vollen
            // Synchronisierung nicht mehr im Suchbereich (User DN / Group DN)
            // gefunden werden.
            $table->string('stale_user_handling', 20)->default('keep')->after('group_dn');
            $table->unsignedInteger('last_sync_removed_count')->nullable()->after('last_sync_user_count');
        });
    }

    public function down(): void
    {
        Schema::table('directories', function (Blueprint $table) {
            $table->dropColumn(['stale_user_handling', 'last_sync_removed_count']);
        });
    }
};
