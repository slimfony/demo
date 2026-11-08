-- Server-side guards: they hold even when a client forgets to set a timeout, and they survive PgBouncer's transaction pooling.
ALTER ROLE demo SET statement_timeout = '30s';
ALTER ROLE demo SET lock_timeout = '10s';
ALTER ROLE demo SET idle_in_transaction_session_timeout = '15s';
CREATE EXTENSION IF NOT EXISTS pg_stat_statements;
