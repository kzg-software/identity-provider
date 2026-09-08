<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('oauth_clients', function (Blueprint $table) {
            // null = keine Einschränkung (alle bekannten Scopes erlaubt, wie bisher)
            $table->json('allowed_scopes')->nullable()->after('allowed_grant_types');
        });
    }

    public function down(): void
    {
        Schema::table('oauth_clients', function (Blueprint $table) {
            $table->dropColumn('allowed_scopes');
        });
    }
};
