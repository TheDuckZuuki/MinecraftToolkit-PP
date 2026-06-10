<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('minecraft_toolkit_update_checks', function (Blueprint $table): void {
            $table->id();
            $table->uuid('server_uuid')->index();
            $table->foreignId('package_id')->constrained('minecraft_toolkit_packages')->cascadeOnDelete();
            $table->string('old_version_id')->nullable();
            $table->string('new_version_id')->nullable();
            $table->string('old_version_number')->nullable();
            $table->string('new_version_number')->nullable();
            $table->string('status')->index();
            $table->text('message')->nullable();
            $table->timestamp('checked_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('minecraft_toolkit_update_checks');
    }
};
