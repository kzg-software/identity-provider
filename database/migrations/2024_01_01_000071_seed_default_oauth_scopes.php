<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('oauth_scopes')->insertOrIgnore([
            ['key' => 'openid', 'label' => 'OpenID', 'description' => 'Ermöglicht OpenID-Connect-Anmeldung und die eindeutige Benutzer-ID (sub).', 'is_default' => true, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'profile', 'label' => 'Profil', 'description' => 'Name, Benutzername, Abteilung, Firma.', 'is_default' => true, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'email', 'label' => 'E-Mail', 'description' => 'E-Mail-Adresse des Benutzers.', 'is_default' => true, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'groups', 'label' => 'Gruppen', 'description' => 'Active-Directory-Gruppen und zugewiesene Rollen.', 'is_default' => false, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        DB::table('oauth_scopes')->whereIn('key', ['openid', 'profile', 'email', 'groups'])->delete();
    }
};
