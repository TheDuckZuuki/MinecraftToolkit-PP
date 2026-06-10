<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('minecraft_toolkit_packages', function (Blueprint $table): void {
            $table->id();
            $table->uuid('server_uuid')->index();
            $table->foreignId('setup_id')->nullable()->constrained('minecraft_toolkit_setups')->nullOnDelete();
            $table->string('source')->index();
            $table->string('source_project_id')->index();
            $table->string('source_project_slug')->nullable();
            $table->string('source_version_id')->nullable();
            $table->string('project_name');
            $table->string('project_type');
            $table->string('package_type')->index();
            $table->string('loader')->nullable();
            $table->string('minecraft_version');
            $table->string('version_number')->nullable();
            $table->string('file_name');
            $table->string('file_path');
            $table->text('download_url')->nullable();
            $table->string('sha1', 40)->nullable();
            $table->string('sha512', 128)->nullable();
            $table->string('side')->nullable();
            $table->json('dependencies_json')->nullable();
            $table->boolean('is_required_dependency')->default(false);
            $table->boolean('is_system_package')->default(false)->index();
            $table->boolean('managed')->default(true)->index();
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('installed_by')->nullable();
            $table->timestamp('installed_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('minecraft_toolkit_packages');
    }
};
