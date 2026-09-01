<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_role_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('directory_group_id')->constrained('directory_groups')->cascadeOnDelete();
            $table->string('role');
            $table->json('claims')->nullable();
            $table->timestamps();
            $table->unique(['directory_group_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_role_mappings');
    }
};
