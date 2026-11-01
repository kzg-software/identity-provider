<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflows', function (Blueprint $table) {
            $table->id();
            $table->string('phase', 16);
            $table->foreignId('application_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('trigger', 64)->nullable();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('graph');
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['phase', 'application_id']);
        });

        Schema::create('workflow_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->json('graph');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('workflow_acceptances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('document_key', 64);
            $table->string('document_version', 32);
            $table->timestamp('accepted_at');

            $table->unique(['user_id', 'document_key', 'document_version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_acceptances');
        Schema::dropIfExists('workflow_versions');
        Schema::dropIfExists('workflows');
    }
};
