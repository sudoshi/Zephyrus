<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use App\Traits\SafeMigration;

return new class extends Migration
{
    use SafeMigration;
    public function up()
    {
        // Location
        if (!Schema::hasTable('prod.locations')) {
            Schema::create('prod.locations', function (Blueprint $table) {
            $table->id('location_id');
            $table->string('name');
            $table->string('abbreviation');
            $table->string('type');
            $table->string('pos_type');
            $table->boolean('active_status')->default(true);
            $table->timestamps();
            $table->string('created_by')->nullable();
            $table->string('modified_by')->nullable();
            $table->boolean('is_deleted')->default(false);
        });
        }

        // Room
        if (!Schema::hasTable('prod.rooms')) {
            Schema::create('prod.rooms', function (Blueprint $table) {
            $table->id('room_id');
            $table->foreignId('location_id')->constrained('prod.locations', 'location_id');
            $table->string('name');
            $table->string('type');
            $table->boolean('active_status')->default(true);
            $table->timestamps();
            $table->string('created_by')->nullable();
            $table->string('modified_by')->nullable();
            $table->boolean('is_deleted')->default(false);
        });
        }

        // Provider
        if (!Schema::hasTable('prod.providers')) {
            Schema::create('prod.providers', function (Blueprint $table) {
            $table->id('provider_id');
            $table->string('npi')->unique();
            $table->string('name');
            $table->foreignId('specialty_id')->constrained('prod.specialties', 'specialty_id');
            $table->string('type');
            $table->boolean('active_status')->default(true);
            $table->timestamps();
            $table->string('created_by')->nullable();
            $table->string('modified_by')->nullable();
            $table->boolean('is_deleted')->default(false);
        });

            // Add provider type check constraint
            if (!$this->constraintExists('prod.providers', 'check_provider_type')) {
                DB::statement("ALTER TABLE prod.providers ADD CONSTRAINT check_provider_type 
                    CHECK (type IN ('surgeon', 'anesthesiologist', 'nurse'))");
            }
        }

        // BlockTemplate
        if (!Schema::hasTable('prod.block_templates')) {
            Schema::create('prod.block_templates', function (Blueprint $table) {
            $table->id('block_id');
            $table->foreignId('room_id')->constrained('prod.rooms', 'room_id');
            $table->foreignId('service_id')->constrained('prod.services', 'service_id');
            $table->foreignId('surgeon_id')->nullable()->constrained('prod.providers', 'provider_id');
            $table->string('group_id')->nullable();
            $table->date('block_date');
            $table->time('start_time');
            $table->time('end_time');
            $table->boolean('is_public')->default(false);
            $table->string('title');
            $table->string('abbreviation');
            $table->timestamps();
            $table->string('created_by')->nullable();
            $table->string('modified_by')->nullable();
            $table->boolean('is_deleted')->default(false);
        });

            // Add room type check constraint
            if (!$this->constraintExists('prod.rooms', 'check_room_type')) {
                DB::statement("ALTER TABLE prod.rooms ADD CONSTRAINT check_room_type 
                    CHECK (type IN ('general', 'OR', 'pre_op', 'post_op', 'cath_lab', 'L&D'))");
            }
        }
    }

    public function down()
    {
        // Only drop tables in local environment
        $this->safeDropIfExists('prod.block_templates');
        $this->safeDropIfExists('prod.providers');
        $this->safeDropIfExists('prod.rooms');
        $this->safeDropIfExists('prod.locations');
    }
};
