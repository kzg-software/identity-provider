<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('group_role_mappings', function (Blueprint $table) {
            $table->dropUnique('group_role_mappings_directory_group_id_role_unique');
        });

        Schema::table('group_role_mappings', function (Blueprint $table) {
            // Ein Mapping ist entweder an eine synchronisierte Gruppe geknüpft
            // ODER nennt einen Gruppennamen frei (matcht per Name gegen die
            // Gruppen des Benutzers), optional auf ein Verzeichnis beschränkt.
            $table->foreignId('directory_group_id')->nullable()->change();
            $table->string('group_name')->nullable()->after('directory_group_id');
            $table->foreignId('directory_id')->nullable()->after('group_name')
                ->constrained('directories')->nullOnDelete();
            $table->index('group_name');
        });
    }

    public function down(): void
    {
        Schema::table('group_role_mappings', function (Blueprint $table) {
            $table->dropIndex(['group_name']);
            $table->dropConstrainedForeignId('directory_id');
            $table->dropColumn('group_name');
        });
    }
};
