<?php

use App\Traits\SafeMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    use SafeMigration;

    public function up(): void
    {
        Schema::create('prod.improvement_opportunities', function (Blueprint $table) {
            $table->id('opportunity_id');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('department')->nullable();
            $table->string('priority')->default('Medium'); // High | Medium | Low
            $table->string('status')->default('Open');      // Open | In Progress | Closed
            $table->integer('estimated_impact')->nullable(); // relative score 0-100
            $table->timestamps();
            $table->string('created_by')->nullable();
            $table->string('modified_by')->nullable();
            $table->boolean('is_deleted')->default(false);
            $table->index(['status', 'priority']);
        });

        Schema::create('prod.improvement_resources', function (Blueprint $table) {
            $table->id('resource_id');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('category')->default('Templates');
            $table->string('type')->default('Document'); // Document | Guide | Video | Link
            $table->date('date_added')->nullable();
            $table->timestamps();
            $table->string('created_by')->nullable();
            $table->string('modified_by')->nullable();
            $table->boolean('is_deleted')->default(false);
            $table->index('category');
        });
    }

    public function down(): void
    {
        $this->safeDropIfExists('prod.improvement_resources');
        $this->safeDropIfExists('prod.improvement_opportunities');
    }
};
