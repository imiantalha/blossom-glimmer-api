# Blossom Glimmer API

Laravel 12 application for **Blossom Glimmer**: a Sanctum-authenticated REST API with role-based access control, queued email, and a service/repository architecture.

This is an original application repository, not a stock Laravel skeleton README.

## Stack

- PHP 8.2+ / Laravel 12
- Laravel Sanctum
- Spatie Laravel Permission
- Queued email (`SendEmailJob`)
- MongoDB driver (`mongodb/laravel-mongodb`)
- Vite frontend (`resources` + catch-all `app` view)
- PHPUnit

## What it does

- Registration, login, current user, and logout
- Authenticated dashboard endpoint
- User, role, and permission management
- Email sending with attachment/log models and retry
- Request logging
- Service, repository, policy, and job layers

API routes are rate-limited and protected with Sanctum where authentication is required.

## Architecture

```text
HTTP Request
    → FormRequest / middleware (Sanctum, throttle)
    → Controller
    → Service
    → Repository / Model
    → JSON API resource  or  queued job (email)
```

## Requirements

- PHP 8.2+
- Composer 2+
- Node.js and npm
- SQLite for local default, or another Laravel-supported database

## Local setup

```bash
git clone https://github.com/imiantalha/blossom-glimmer-api.git
cd blossom-glimmer-api

composer install
cp .env.example .env
php artisan key:generate

php artisan migrate
npm install
npm run build

php artisan serve
```

Configure mail, queue, and database values in `.env` before using email or background jobs.

```bash
php artisan queue:listen
```

## API surface

Unauthenticated (throttled):

```text
POST /api/register
POST /api/login
```

Authenticated (`auth:sanctum`):

```text
GET    /api/me
POST   /api/logout
GET    /api/dashboard

GET|POST|PUT|PATCH|DELETE /api/users
GET|POST|PUT|PATCH|DELETE /api/roles
GET|POST|PUT|PATCH|DELETE /api/permissions

GET    /api/email-logs
GET    /api/email-logs/{emailLog}
DELETE /api/email-logs/{emailLog}
POST   /api/email-logs/{emailLog}/retry
POST   /api/emails/send
```

## Testing

```bash
php artisan test
```

## Notes

Secrets belong in `.env`, never in source control. The default Laravel framework README was replaced so this repository describes the actual Blossom Glimmer application.

## License

MIT, unless otherwise stated by the repository owner.
