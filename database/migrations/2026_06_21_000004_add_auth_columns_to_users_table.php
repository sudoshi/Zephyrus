<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the auth-flow columns that were applied directly to the prod schema
 * but never tracked as a migration. Required so RefreshDatabase works in testing.
 *
 * must_change_password: forces password reset after admin-provisioned account creation
 * role: varchar role ('user' | 'admin') — separate from Spatie RBAC, drives app-level gates
 * is_active: soft-disable without deletion
 * phone: optional contact number captured on registration
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prod.users', function (Blueprint $table) {
            if (! Schema::hasColumn('prod.users', 'must_change_password')) {
                $table->boolean('must_change_password')->default(true)->after('workflow_preference');
            }
            if (! Schema::hasColumn('prod.users', 'role')) {
                $table->string('role', 50)->default('user')->after('must_change_password');
            }
            if (! Schema::hasColumn('prod.users', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('role');
            }
            if (! Schema::hasColumn('prod.users', 'phone')) {
                $table->string('phone', 50)->nullable()->after('is_active');
            }
        });
    }

    public function down(): void
    {
        Schema::table('prod.users', function (Blueprint $table) {
            $table->dropColumn(['must_change_password', 'role', 'is_active', 'phone']);
        });
    }
};
