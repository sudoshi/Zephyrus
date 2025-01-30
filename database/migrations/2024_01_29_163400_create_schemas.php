<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up()
    {
        // Create schemas
        DB::statement('CREATE SCHEMA IF NOT EXISTS prod');
        DB::statement('CREATE SCHEMA IF NOT EXISTS stg');
        
        // Set the search path to include both schemas
        DB::statement('SET search_path TO prod, stg, public');
    }

    public function down()
    {
        // Drop schemas if they exist
        DB::statement('DROP SCHEMA IF EXISTS prod CASCADE');
        DB::statement('DROP SCHEMA IF EXISTS stg CASCADE');
    }
};
