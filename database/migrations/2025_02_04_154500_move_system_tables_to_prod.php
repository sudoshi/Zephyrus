<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up()
    {
        DB::unprepared("
            DO \$\$
            BEGIN
                -- Move users table if it exists in public schema
                IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = 'public' AND table_name = 'users') THEN
                    ALTER TABLE public.users SET SCHEMA prod;
                END IF;

                -- Move sessions table if it exists in public schema
                IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = 'public' AND table_name = 'sessions') THEN
                    ALTER TABLE public.sessions SET SCHEMA prod;
                END IF;

                -- Move password_reset_tokens table if it exists in public schema
                IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = 'public' AND table_name = 'password_reset_tokens') THEN
                    ALTER TABLE public.password_reset_tokens SET SCHEMA prod;
                END IF;
            END
            \$\$
        ");
    }

    public function down()
    {
        DB::unprepared("
            DO \$\$
            BEGIN
                -- Move users table back to public schema if it exists in prod schema
                IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = 'prod' AND table_name = 'users') THEN
                    ALTER TABLE prod.users SET SCHEMA public;
                END IF;

                -- Move sessions table back to public schema if it exists in prod schema
                IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = 'prod' AND table_name = 'sessions') THEN
                    ALTER TABLE prod.sessions SET SCHEMA public;
                END IF;

                -- Move password_reset_tokens table back to public schema if it exists in prod schema
                IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = 'prod' AND table_name = 'password_reset_tokens') THEN
                    ALTER TABLE prod.password_reset_tokens SET SCHEMA public;
                END IF;
            END
            \$\$
        ");
    }
};
