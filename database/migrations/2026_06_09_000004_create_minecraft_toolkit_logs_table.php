<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('minecraft_toolkit_logs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('server_uuid')->index();
            $table->unsignedInteger('user_id')->nullable();
            $table->string('action')->index();
            $table->string('level')->default('info')->index();
            $table->text('message');
            $table->json('context_json')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('minecraft_toolkit_logs');
    }
};
