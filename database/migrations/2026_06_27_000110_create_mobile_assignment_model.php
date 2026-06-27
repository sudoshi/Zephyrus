<?php

use App\Traits\SafeMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    use SafeMigration;

    /**
     * The assignment model that powers the Hummingbird mobile companion's
     * "For You" queue and notification routing. Today `barriers.owner` and
     * `rtdc_plans.owner` are free-text strings and there is no user<->unit
     * association, so "assigned to me / my unit" is not queryable. This adds:
     *   - prod.user_unit            : which users cover which units
     *   - barriers.owner_user_id    : structured owner alongside the legacy string
     *   - rtdc_plans.owner_user_id  : structured owner alongside the legacy string
     *
     * Strictly additive — the existing free-text `owner` columns are retained.
     * User references follow the house convention (plain unsignedBigInteger, no
     * FK to prod.users, which is treated as externally governed); unit references
     * are real FKs to prod.units.
     */
    public function up(): void
    {
        if (! Schema::hasTable('prod.user_unit')) {
            Schema::create('prod.user_unit', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->foreignId('unit_id')->constrained('prod.units', 'unit_id')->cascadeOnDelete();
                $table->string('role', 40)->nullable();   // charge | bedside | manager | bed_flow | ...
                $table->boolean('is_primary')->default(false);
                $table->timestamps();

                $table->unique(['user_id', 'unit_id'], 'uniq_user_unit');
                $table->index('user_id');
                $table->index('unit_id', 'idx_user_unit_unit');
            });
        }

        if (Schema::hasTable('prod.barriers') && ! Schema::hasColumn('prod.barriers', 'owner_user_id')) {
            Schema::table('prod.barriers', function (Blueprint $table) {
                $table->unsignedBigInteger('owner_user_id')->nullable();
                $table->index('owner_user_id', 'idx_barriers_owner_user');
            });
        }

        if (Schema::hasTable('prod.rtdc_plans') && ! Schema::hasColumn('prod.rtdc_plans', 'owner_user_id')) {
            Schema::table('prod.rtdc_plans', function (Blueprint $table) {
                $table->unsignedBigInteger('owner_user_id')->nullable();
                $table->index('owner_user_id', 'idx_rtdc_plans_owner_user');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('prod.rtdc_plans', 'owner_user_id')) {
            Schema::table('prod.rtdc_plans', function (Blueprint $table) {
                $table->dropColumn('owner_user_id');
            });
        }

        if (Schema::hasColumn('prod.barriers', 'owner_user_id')) {
            Schema::table('prod.barriers', function (Blueprint $table) {
                $table->dropColumn('owner_user_id');
            });
        }

        $this->safeDropIfExists('prod.user_unit');
    }
};
