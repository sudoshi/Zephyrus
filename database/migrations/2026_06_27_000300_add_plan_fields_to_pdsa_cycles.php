<?php

use App\Traits\SafeMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Capture the full PDSA "Plan" phase. The New PDSA Cycle form collects the
 * change rationale and the predicted outcome alongside the objective; persist
 * them so the cycle is created with a complete Plan rather than discarding input.
 */
return new class extends Migration
{
    use SafeMigration;

    public function up(): void
    {
        Schema::table('prod.pdsa_cycles', function (Blueprint $table) {
            if (! Schema::hasColumn('prod.pdsa_cycles', 'rationale')) {
                $table->text('rationale')->nullable()->after('objective');
            }
            if (! Schema::hasColumn('prod.pdsa_cycles', 'prediction')) {
                $table->text('prediction')->nullable()->after('rationale');
            }
            if (! Schema::hasColumn('prod.pdsa_cycles', 'target_date')) {
                $table->date('target_date')->nullable()->after('started_at');
            }
        });
    }

    public function down(): void
    {
        if (! $this->isLocalEnvironment()) {
            return;
        }

        Schema::table('prod.pdsa_cycles', function (Blueprint $table) {
            $table->dropColumn(['rationale', 'prediction', 'target_date']);
        });
    }
};
