<?php

declare(strict_types=1);

namespace BlueWolf\MinecraftToolkit\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MinecraftToolkitUpdateCheck extends Model
{
    protected $table = 'minecraft_toolkit_update_checks';

    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected function casts(): array
    {
        return [
            'checked_at' => 'datetime',
            'candidate_json' => 'array',
        ];
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(MinecraftToolkitPackage::class, 'package_id');
    }
}
