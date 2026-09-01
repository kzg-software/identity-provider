<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('directories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type')->default('active_directory'); // active_directory, ldap
            $table->string('domain')->nullable();
            $table->string('realm')->nullable();
            $table->string('netbios_domain')->nullable();
            $table->string('domain_controller')->nullable();
            $table->string('ldap_server')->nullable();
            $table->unsignedInteger('ldap_port')->nullable();
            $table->boolean('use_ldaps')->default(false);
            $table->string('base_dn')->nullable();
            $table->string('user_dn')->nullable();
            $table->string('group_dn')->nullable();
            $table->string('bind_user')->nullable();
            $table->text('bind_password_encrypted')->nullable();
            $table->string('upn_suffix')->nullable();
            $table->string('kerberos_realm')->nullable();
            $table->unsignedInteger('priority')->default(0);
            $table->boolean('is_active')->default(false);
            $table->json('config')->nullable();
            $table->timestamp('last_sync_at')->nullable();
            $table->unsignedInteger('last_sync_duration_seconds')->nullable();
            $table->unsignedInteger('last_sync_user_count')->nullable();
            $table->unsignedInteger('last_sync_group_count')->nullable();
            $table->text('last_sync_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('directories');
    }
};
