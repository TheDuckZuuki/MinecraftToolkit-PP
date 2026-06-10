<?php

declare(strict_types=1);

namespace BlueWolf\MinecraftToolkit\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MinecraftToolkitPackage extends Model
{
    protected $table = 'minecraft_toolkit_packages';

    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected function casts(): array
    {
        return [
            'dependencies_json' => 'array',
            'is_required_dependency' => 'boolean',
            'is_system_package' => 'boolean',
            'managed' => 'boolean',
            'enabled' => 'boolean',
            'installed_at' => 'datetime',
            'last_checked_at' => 'datetime',
        ];
    }

    public function setup(): BelongsTo
    {
        return $this->belongsTo(MinecraftToolkitSetup::class, 'setup_id');
    }

    public function updateChecks(): HasMany
    {
        return $this->hasMany(MinecraftToolkitUpdateCheck::class, 'package_id');
    }
}
