<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prod.users', function (Blueprint $table): void {
            if (! Schema::hasColumn('prod.users', 'auth_session_version')) {
                $table->unsignedBigInteger('auth_session_version')->default(0);
            }
            if (! Schema::hasColumn('prod.users', 'is_protected')) {
                $table->boolean('is_protected')->default(false);
            }
            if (! Schema::hasColumn('prod.users', 'deactivated_at')) {
                $table->timestampTz('deactivated_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('prod.users', function (Blueprint $table): void {
            foreach (['deactivated_at', 'is_protected', 'auth_session_version'] as $column) {
                if (Schema::hasColumn('prod.users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
