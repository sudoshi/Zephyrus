<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up()
    {
        // Set search path to include prod schema
        DB::unprepared('SET search_path TO prod, public');
    }

    public function down()
    {
        // Move migrations table and sequence back to public if they exist
        DB::unprepared('
            DO $$
            BEGIN
                IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = \'prod\' AND table_name = \'migrations\') THEN
                    -- Drop sequence in public schema if it exists
                    DROP SEQUENCE IF EXISTS public.migrations_id_seq;
                    
                    -- Move sequence first
                    ALTER SEQUENCE IF EXISTS prod.migrations_id_seq SET SCHEMA public;
                    
                    -- Then move table
                    ALTER TABLE prod.migrations SET SCHEMA public;
                END IF;
            END
            $$;
        ');
        
        // Drop prod schema
        DB::unprepared('DROP SCHEMA IF EXISTS prod CASCADE');
        
        // Reset search path to public
        DB::unprepared('SET search_path TO public');
    }
};
