<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('applications', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('logo_path')->nullable();
            $table->string('login_mode')->default('user_choice');
            // user_choice, auto_redirect, windows_sso, windows_sso_fallback, specific_provider
            $table->string('preferred_provider')->nullable();
            $table->boolean('consent_required')->default(true);
            $table->string('consent_mode')->default('first_time'); // always, first_time, skip, on_scope_change
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('applications');
    }
};
