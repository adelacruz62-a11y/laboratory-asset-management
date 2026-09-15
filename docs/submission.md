# Submission Checklist

## 1. GitHub repository URL

Add the repository URL after creating the GitHub repository:

`https://github.com/<your-username>/<your-repository>`

## 2. Live URL

GitHub Pages cannot execute PHP. Deploy this project to a PHP-capable host such as Render, Railway, InfinityFree, or a school server, then add the deployed URL here.

Local development URL: `http://localhost:8000/index.php`

## 3-6. Design deliverables

The updated ERD, use case diagram, role-permission matrix, workflow diagram, and business rules are in [design.md](design.md).

## 7. Audit-log screenshot

Log in as Maria Santos, approve a pending borrowing request, open Audit Logs, and capture the page showing the approval entry. Store the image in this directory and link it here.

## 8. Functional test results

Run:

```powershell
php tests\\test.php
php -l index.php
php -l lib\\AssetSystem.php
```

Expected result:

`All PHP workflow tests passed.`

## Supabase setup

1. Run [reset.sql](../supabase/reset.sql) only for a new development project if an earlier failed run left partial objects.
2. Run [schema.sql](../supabase/schema.sql) in Supabase SQL Editor.
3. Copy [.env.example](../.env.example) to a local environment configuration and fill in the Supabase anon/publishable key.
4. Do not use the service-role key in browser code or commit credentials.

The current PHP demo continues to use `data/state.json`. Supabase tables are prepared for production, but the PHP data layer still needs a Supabase client and Supabase Auth integration before the live application can use them.
