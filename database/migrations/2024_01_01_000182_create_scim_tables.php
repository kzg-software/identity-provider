<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scim_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('base_url', 2048);
            $table->text('auth_token');
            $table->boolean('sync_users')->default(true);
            $table->boolean('sync_groups')->default(false);
            $table->string('on_removal', 12)->default('deactivate');
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_synced_at')->nullable();
            $table->string('last_status', 12)->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        Schema::create('scim_resources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scim_connection_id')->constrained()->cascadeOnDelete();
            $table->string('resource_type', 10);
            $table->unsignedBigInteger('local_id');
            $table->string('remote_id');
            $table->string('payload_hash', 64)->nullable();
            $table->boolean('deactivated')->default(false);
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['scim_connection_id', 'resource_type', 'local_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scim_resources');
        Schema::dropIfExists('scim_connections');
    }
};
