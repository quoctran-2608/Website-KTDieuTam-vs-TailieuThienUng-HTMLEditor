# Phase 1 Quickstart — Auth Shell

## Run locally

```bash
php -S 127.0.0.1:8080 -t .
```

Open:

- `http://127.0.0.1:8080/admin/`

## Default dev credentials

- username: `admin`
- password: `admin123`

## Healthcheck

```bash
php admin/includes/healthcheck.php
```

## Expected behavior

- unauthenticated access to `/admin/dashboard.php` redirects to login
- login success redirects to dashboard
- logout clears session and redirects to login
- failed login attempts are logged and lockout triggers after threshold

## Notes

Current coding workspace has no PHP CLI/runtime, so runtime checks must be executed on your machine/CI with PHP 8+.
