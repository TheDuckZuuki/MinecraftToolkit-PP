<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('minecraft_toolkit_update_checks')
            || Schema::hasColumn('minecraft_toolkit_update_checks', 'candidate_json')) {
            return;
        }

        Schema::table('minecraft_toolkit_update_checks', function (Blueprint $table): void {
            $table->json('candidate_json')->nullable()->after('message');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('minecraft_toolkit_update_checks')
            || !Schema::hasColumn('minecraft_toolkit_update_checks', 'candidate_json')) {
            return;
        }

        Schema::table('minecraft_toolkit_update_checks', function (Blueprint $table): void {
            $table->dropColumn('candidate_json');
        });
    }
};
