<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('directory_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('directory_id')->constrained('directories')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('object_guid')->unique();
            $table->string('sid')->nullable()->unique();
            $table->string('sam_account_name')->index();
            $table->string('upn')->nullable()->index();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('display_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('department')->nullable();
            $table->string('company')->nullable();
            $table->string('position')->nullable();
            $table->string('office')->nullable();
            $table->string('manager')->nullable();
            $table->string('distinguished_name');
            $table->string('domain')->nullable();
            $table->string('account_status')->nullable();
            $table->json('extra_attributes')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });

        Schema::create('directory_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('directory_id')->constrained('directories')->cascadeOnDelete();
            $table->string('object_guid')->unique();
            $table->string('sid')->nullable()->unique();
            $table->string('name')->index();
            $table->string('distinguished_name');
            $table->text('description')->nullable();
            $table->json('extra_attributes')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });

        Schema::create('directory_group_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('directory_user_id')->constrained('directory_users')->cascadeOnDelete();
            $table->foreignId('directory_group_id')->constrained('directory_groups')->cascadeOnDelete();
            $table->boolean('is_nested')->default(false);
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
            $table->unique(['directory_user_id', 'directory_group_id'], 'dgm_user_group_unique');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreign('directory_id')->references('id')->on('directories')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['directory_id']);
        });
        Schema::dropIfExists('directory_group_memberships');
        Schema::dropIfExists('directory_groups');
        Schema::dropIfExists('directory_users');
    }
};
