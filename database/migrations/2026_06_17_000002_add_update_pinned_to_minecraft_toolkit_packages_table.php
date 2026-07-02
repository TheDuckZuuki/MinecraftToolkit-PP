<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('minecraft_toolkit_packages')
            || Schema::hasColumn('minecraft_toolkit_packages', 'update_pinned')) {
            return;
        }

        Schema::table('minecraft_toolkit_packages', function (Blueprint $table): void {
            $table->boolean('update_pinned')->default(false)->after('enabled')->index();
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('minecraft_toolkit_packages')
            || !Schema::hasColumn('minecraft_toolkit_packages', 'update_pinned')) {
            return;
        }

        Schema::table('minecraft_toolkit_packages', function (Blueprint $table): void {
            $table->dropColumn('update_pinned');
        });
    }
};
