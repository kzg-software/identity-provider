<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Manuell (im Admin-Bereich) zugewiesene Rollen. Bleiben bei einer
            // Verzeichnis-Synchronisierung erhalten, im Gegensatz zu "roles",
            // die aus dem Gruppen-Mapping neu berechnet werden.
            $table->json('manual_roles')->nullable()->after('roles');
        });

        // Bestehende Administratoren behalten: is_admin wird künftig aus den
        // Rollen abgeleitet, also die 'admin'-Rolle explizit hinterlegen.
        foreach (DB::table('users')->where('is_admin', true)->get(['id', 'manual_roles']) as $user) {
            $roles = json_decode($user->manual_roles ?? '[]', true) ?: [];

            if (! in_array('admin', $roles, true)) {
                $roles[] = 'admin';
            }

            DB::table('users')->where('id', $user->id)->update(['manual_roles' => json_encode($roles)]);
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('manual_roles');
        });
    }
};
