<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('directories', function (Blueprint $table) {
            // Inkrementelle Synchronisierung: nur Objekte abfragen, deren
            // uSNChanged seit dem letzten Lauf gestiegen ist. Nur fuer Active
            // Directory sinnvoll, daher standardmaessig aus.
            $table->boolean('delta_sync_enabled')->default(false)->after('stale_user_handling');

            // Cursor der letzten Synchronisierung (hoechster gesehener
            // uSNChanged-Wert). uSNChanged ist DC-lokal, daher wird der
            // Hostname des DC mitgespeichert: wechselt er, ist der Cursor
            // wertlos und es laeuft wieder eine volle Synchronisierung.
            $table->unsignedBigInteger('last_sync_usn')->nullable()->after('last_sync_error');
            $table->string('last_sync_directory_host')->nullable()->after('last_sync_usn');
            $table->timestamp('last_full_sync_at')->nullable()->after('last_sync_directory_host');
        });
    }

    public function down(): void
    {
        Schema::table('directories', function (Blueprint $table) {
            $table->dropColumn([
                'delta_sync_enabled',
                'last_sync_usn',
                'last_sync_directory_host',
                'last_full_sync_at',
            ]);
        });
    }
};
