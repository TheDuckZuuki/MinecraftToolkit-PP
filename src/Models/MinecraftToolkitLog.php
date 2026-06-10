<?php

declare(strict_types=1);

namespace BlueWolf\MinecraftToolkit\Models;

use Illuminate\Database\Eloquent\Model;

class MinecraftToolkitLog extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'minecraft_toolkit_logs';

    protected $guarded = ['id', 'created_at'];

    protected function casts(): array
    {
        return [
            'context_json' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
