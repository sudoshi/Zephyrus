<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('process_layouts', function (Blueprint $table) {
            // Drop the old unique constraint
            $table->dropUnique(['user_id', 'process_type']);
            
            // Add a new unique constraint that includes all filter columns
            $table->unique(['user_id', 'process_type', 'hospital', 'workflow', 'time_range'], 'process_layouts_complete_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('process_layouts', function (Blueprint $table) {
            // Drop the new unique constraint
            $table->dropUnique('process_layouts_complete_unique');
            
            // Restore the original unique constraint
            $table->unique(['user_id', 'process_type']);
        });
    }
};
