<?php

declare(strict_types=1);

namespace BlueWolf\MinecraftToolkit\Models;

use App\Models\Server;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MinecraftToolkitSetup extends Model
{
    protected $table = 'minecraft_toolkit_setups';

    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected function casts(): array
    {
        return [
            'max_players' => 'integer',
            'online_mode' => 'boolean',
            'whitelist' => 'boolean',
            'pvp' => 'boolean',
            'allow_nether' => 'boolean',
            'spawn_protection' => 'integer',
            'view_distance' => 'integer',
            'simulation_distance' => 'integer',
            'enable_command_block' => 'boolean',
            'allow_flight' => 'boolean',
            'enable_query' => 'boolean',
            'enable_rcon' => 'boolean',
            'primary_allocation_port' => 'integer',
            'bedrock_allocation_port' => 'integer',
            'crossplay_enabled' => 'boolean',
            'geyser_enabled' => 'boolean',
            'floodgate_enabled' => 'boolean',
            'setup_started_at' => 'datetime',
            'setup_completed_at' => 'datetime',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function packages(): HasMany
    {
        return $this->hasMany(MinecraftToolkitPackage::class, 'setup_id');
    }

    public function isComplete(): bool
    {
        return $this->setup_status === 'completed';
    }
}
