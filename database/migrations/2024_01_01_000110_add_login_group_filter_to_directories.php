<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('directories', function (Blueprint $table) {
            // Ein oder mehrere Gruppen (DN oder CN, je Zeile bzw. per Komma).
            // Ist etwas gesetzt, werden nur Benutzer synchronisiert und
            // angemeldet, die (auch verschachtelt) Mitglied mindestens einer
            // dieser Gruppen sind.
            $table->text('login_group_filter')->nullable()->after('group_dn');
        });
    }

    public function down(): void
    {
        Schema::table('directories', function (Blueprint $table) {
            $table->dropColumn('login_group_filter');
        });
    }
};
