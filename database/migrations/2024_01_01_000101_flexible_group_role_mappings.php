<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // MySQL/MariaDB: der Unique-Index (directory_group_id, role) deckt den
        // Fremdschlüssel auf directory_group_id ab und lässt sich erst nach dem
        // Entfernen des Fremdschlüssels löschen.
        Schema::table('group_role_mappings', function (Blueprint $table) {
            $table->dropForeign(['directory_group_id']);
        });

        Schema::table('group_role_mappings', function (Blueprint $table) {
            $table->dropUnique('group_role_mappings_directory_group_id_role_unique');
        });

        Schema::table('group_role_mappings', function (Blueprint $table) {
            // Ein Mapping ist entweder an eine synchronisierte Gruppe geknüpft
            // ODER nennt einen Gruppennamen frei (Abgleich per Name gegen die
            // Gruppen des Benutzers), optional auf ein Verzeichnis beschränkt.
            $table->unsignedBigInteger('directory_group_id')->nullable()->change();

            if (! Schema::hasColumn('group_role_mappings', 'group_name')) {
                $table->string('group_name')->nullable()->after('directory_group_id');
                $table->index('group_name');
            }

            if (! Schema::hasColumn('group_role_mappings', 'directory_id')) {
                $table->foreignId('directory_id')->nullable()->after('group_name')
                    ->constrained('directories')->nullOnDelete();
            }
        });

        Schema::table('group_role_mappings', function (Blueprint $table) {
            $table->foreign('directory_group_id')
                ->references('id')->on('directory_groups')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('group_role_mappings', function (Blueprint $table) {
            $table->dropForeign(['directory_group_id']);
            $table->dropForeign(['directory_id']);
            $table->dropIndex(['group_name']);
            $table->dropColumn(['group_name', 'directory_id']);
        });

        // Vor dem Wiederherstellen des Unique-Index dürfen keine NULLs im
        // directory_group_id stehen.
        \Illuminate\Support\Facades\DB::table('group_role_mappings')->whereNull('directory_group_id')->delete();

        Schema::table('group_role_mappings', function (Blueprint $table) {
            $table->unsignedBigInteger('directory_group_id')->nullable(false)->change();
            $table->unique(['directory_group_id', 'role']);
            $table->foreign('directory_group_id')
                ->references('id')->on('directory_groups')->cascadeOnDelete();
        });
    }
};
