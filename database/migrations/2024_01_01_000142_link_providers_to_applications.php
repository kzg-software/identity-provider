<?php

use App\Support\ProviderBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->foreignId('provider_id')->nullable()->after('id')->constrained('providers')->nullOnDelete();
            $table->string('visibility')->default('portal')->after('logo_path'); // portal | hidden
        });

        Schema::table('oauth_clients', function (Blueprint $table) {
            $table->foreignId('provider_id')->nullable()->after('application_id')->constrained('providers')->nullOnDelete();
        });

        Schema::table('saml_service_providers', function (Blueprint $table) {
            $table->foreignId('provider_id')->nullable()->after('application_id')->constrained('providers')->nullOnDelete();
        });

        // Alte Zeilen in das neue Modell überführen.
        ProviderBackfill::run();

        // SAML-Anwendungen ohne Start-URL waren bisher als leere Kachel im Portal
        // sichtbar – künftig standardmäßig verborgen (per Admin umstellbar).
        $samlProviderIds = DB::table('providers')->where('type', 'saml')->pluck('id');
        DB::table('applications')
            ->whereIn('provider_id', $samlProviderIds)
            ->where(fn ($q) => $q->whereNull('launch_url')->orWhere('launch_url', ''))
            ->update(['visibility' => 'hidden']);

        // Bidirektionale FK entfernen: Anwendung ↔ Provider läuft jetzt nur noch
        // über applications.provider_id.
        Schema::table('oauth_clients', function (Blueprint $table) {
            $table->dropConstrainedForeignId('application_id');
        });

        Schema::table('saml_service_providers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('application_id');
        });
    }

    public function down(): void
    {
        Schema::table('oauth_clients', function (Blueprint $table) {
            $table->foreignId('application_id')->nullable()->after('id')->constrained('applications')->cascadeOnDelete();
        });

        Schema::table('saml_service_providers', function (Blueprint $table) {
            $table->foreignId('application_id')->nullable()->after('id')->constrained('applications')->cascadeOnDelete();
        });

        // Verknüpfung aus applications.provider_id zurückschreiben.
        foreach (DB::table('applications')->whereNotNull('provider_id')->get() as $app) {
            DB::table('oauth_clients')->where('provider_id', $app->provider_id)->update(['application_id' => $app->id]);
            DB::table('saml_service_providers')->where('provider_id', $app->provider_id)->update(['application_id' => $app->id]);
        }

        Schema::table('oauth_clients', function (Blueprint $table) {
            $table->dropConstrainedForeignId('provider_id');
        });

        Schema::table('saml_service_providers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('provider_id');
        });

        Schema::table('applications', function (Blueprint $table) {
            $table->dropConstrainedForeignId('provider_id');
            $table->dropColumn('visibility');
        });
    }
};
