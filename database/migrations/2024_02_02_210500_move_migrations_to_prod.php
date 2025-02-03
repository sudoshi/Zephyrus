<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up()
    {
        // Drop existing migrations table if it exists
        DB::statement('DROP TABLE IF EXISTS migrations CASCADE');
        
        // Set search path to prod for new migrations table
        DB::statement('SET search_path TO prod, public');
        
        // Create migrations table in prod schema
        DB::statement('
            CREATE TABLE migrations (
                id SERIAL PRIMARY KEY,
                migration VARCHAR(255) NOT NULL,
                batch INTEGER NOT NULL
            )
        ');
    }

    public function down()
    {
        // Drop migrations table from prod schema
        DB::statement('DROP TABLE IF EXISTS prod.migrations CASCADE');
        
        // Reset search path to public
        DB::statement('SET search_path TO public');
        
        // Recreate migrations table in public schema
        DB::statement('
            CREATE TABLE migrations (
                id SERIAL PRIMARY KEY,
                migration VARCHAR(255) NOT NULL,
                batch INTEGER NOT NULL
            )
        ');
    }
};
