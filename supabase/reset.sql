-- Development-only reset. This deletes the public schema and all data in it.
drop schema if exists public cascade;
create schema public;

-- Run schema.sql immediately after this script.
