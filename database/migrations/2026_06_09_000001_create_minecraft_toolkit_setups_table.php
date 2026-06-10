<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('minecraft_toolkit_setups', function (Blueprint $table): void {
            $table->id();
            $table->uuid('server_uuid')->unique();
            $table->unsignedInteger('server_id')->nullable();
            $table->unsignedInteger('user_id')->nullable();
            $table->string('edition')->default('java');
            $table->string('software')->index();
            $table->string('minecraft_version')->index();
            $table->string('loader')->nullable();
            $table->string('loader_version')->nullable();
            $table->string('server_jar_path')->nullable();
            $table->string('server_binary_path')->nullable();
            $table->string('motd')->default('A Minecraft Server');
            $table->string('level_name')->default('world');
            $table->unsignedInteger('max_players')->default(20);
            $table->string('gamemode')->default('survival');
            $table->string('difficulty')->default('easy');
            $table->boolean('online_mode')->default(true);
            $table->boolean('whitelist')->default(false);
            $table->boolean('pvp')->default(true);
            $table->boolean('allow_nether')->default(true);
            $table->unsignedInteger('spawn_protection')->default(16);
            $table->unsignedInteger('view_distance')->default(10);
            $table->unsignedInteger('simulation_distance')->default(10);
            $table->boolean('enable_command_block')->default(false);
            $table->boolean('allow_flight')->default(false);
            $table->boolean('enable_query')->default(false);
            $table->boolean('enable_rcon')->default(false);
            $table->string('primary_allocation_ip')->nullable();
            $table->unsignedInteger('primary_allocation_port');
            $table->string('bedrock_allocation_ip')->nullable();
            $table->unsignedInteger('bedrock_allocation_port')->nullable();
            $table->boolean('crossplay_enabled')->default(false);
            $table->boolean('geyser_enabled')->default(false);
            $table->boolean('floodgate_enabled')->default(false);
            $table->string('icon_path')->nullable();
            $table->string('setup_status')->default('pending')->index();
            $table->timestamp('setup_started_at')->nullable();
            $table->timestamp('setup_completed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->foreign('server_id')->references('id')->on('servers')->nullOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('minecraft_toolkit_setups');
    }
};
