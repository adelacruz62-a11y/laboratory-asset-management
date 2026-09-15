# Laboratory Asset and Service Management System

A PHP implementation of Laboratory 4 Section A. It uses PHP sessions, server-side role checks, JSON persistence for local development, borrowing approval, equipment state transitions, maintenance requests, and an audit trail.

## Run

```powershell
php tests\test.php
php -l index.php
php -l lib\AssetSystem.php
php -S localhost:8000
```

Open `http://localhost:8000/index.php` after starting the server. The login selector provides Administrator, Laboratory Staff, and Requester / Viewer demo accounts. PHP must be installed and available on `PATH`.

## Architecture

- `index.php`: server-rendered interface, session login, server-side guards, forms, and actions.
- `lib/AssetSystem.php`: domain rules, state transitions, audit logging, and JSON persistence.
- `supabase/schema.sql`: production tables, roles, and Row Level Security policies.
- `styles.css`: shared styling for the PHP interface.

## Supabase production mapping

For production, apply `supabase/schema.sql` in Supabase SQL Editor and replace the local JSON repository with Supabase queries. RLS policies and database triggers enforce role checks and state transitions at the database boundary.

## Deployment note

GitHub Pages serves static files and cannot execute PHP. Keep the repository on GitHub, deploy this PHP application to a PHP-capable host such as Render, Railway, InfinityFree, or a school server, and connect that deployment to Supabase. A GitHub Pages URL can document the project, but it cannot host the functional PHP runtime.

## Deliverables

See `docs/design.md` for the ERD, use case diagram, workflow, permission matrix, business rules, and functional test checklist.