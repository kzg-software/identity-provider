<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->text('allowed_ip_ranges')->nullable()->after('maintenance_allow');
            $table->text('access_time_windows')->nullable()->after('allowed_ip_ranges');
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn(['allowed_ip_ranges', 'access_time_windows']);
        });
    }
};
