<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Überführt das alte Datenmodell (OauthClient/SamlServiceProvider hängen direkt
 * über *.application_id an einer Application) in das neue: jede technische
 * Konfiguration bekommt einen Eintrag in `providers`, und `applications.provider_id`
 * zeigt darauf.
 *
 * Wird aus der Migration aufgerufen, solange die alten `application_id`-Spalten
 * noch existieren. Idempotent: verarbeitet nur Zeilen ohne `provider_id`.
 */
class ProviderBackfill
{
    public static function run(): void
    {
        self::backfill('oauth_clients', 'oidc');
        self::backfill('saml_service_providers', 'saml');
    }

    private static function backfill(string $table, string $type): void
    {
        if (! self::hasColumn($table, 'application_id') || ! self::hasColumn($table, 'provider_id')) {
            return;
        }

        $rows = DB::table($table)->whereNull('provider_id')->get();

        foreach ($rows as $row) {
            $providerId = DB::table('providers')->insertGetId([
                'name' => $row->name ?: ucfirst($type).'-Provider',
                'type' => $type,
                'is_active' => $row->is_active ?? true,
                'created_at' => $row->created_at ?? now(),
                'updated_at' => now(),
            ]);

            DB::table($table)->where('id', $row->id)->update(['provider_id' => $providerId]);

            if ($row->application_id) {
                DB::table('applications')->where('id', $row->application_id)->update(['provider_id' => $providerId]);
            }
        }
    }

    private static function hasColumn(string $table, string $column): bool
    {
        return Schema::hasColumn($table, $column);
    }
}
