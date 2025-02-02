-- Drop the sessions table from prod schema if it exists
DROP TABLE IF EXISTS prod.sessions;

-- Create the sessions table in public schema
CREATE TABLE IF NOT EXISTS public.sessions (
    id varchar(255) NOT NULL,
    user_id bigint NULL,
    ip_address varchar(45) NULL,
    user_agent text NULL,
    payload text NOT NULL,
    last_activity integer NOT NULL,
    CONSTRAINT sessions_pkey PRIMARY KEY (id)
);

-- Grant necessary permissions
GRANT ALL PRIVILEGES ON TABLE public.sessions TO postgres;
