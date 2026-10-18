# VetPharma ERP

A modular Laravel API (nwidart modules) for a veterinary-pharmaceutical
distributor: inventory with FEFO stock, sales, purchasing, CRM, accounting,
expenses, analytics, **recap** (stock write-offs), **manufacturing** and, in
the latest update, **HR** (employees, attendance, payroll posted to the books)
with a **permission on every endpoint**.

| Module | What it covers |
|---|---|
| Core | auth, users, 🔄 roles & per-endpoint permissions, audit log, notifications |
| Inventory | warehouses, products, categories, 🆕 packaging units, batches (FEFO), transfers, 🆕 free-gift stock |
| Sales | sales orders (🔄 multi-warehouse, 🆕 gift lines, 🆕 minimum price), invoices (🆕 void / import), deliveries, collections, returns |
| Purchasing | suppliers, purchase orders (🔄 lines in any unit), receiving (🔄 free gifts, 🔄 input VAT), 🆕 supplier payments |
| CRM | customers, leads, visits, complaints, campaigns |
| Accounting | 🔄 chart of accounts (editable, 🆕 connections map), journal entries (🆕 manual + reversal), 🔄 statements (balance sheet now balances) |
| Expenses | categories, expenses, approval |
| 🆕 Recap | stock write-offs for expired / damaged / lost stock, posted to the chart of accounts |
| 🔄 Manufacturing | recipes with revisions, an order workflow with partial production and backorders, stock reservations, routing / work centers / work orders, quality control, scrap and by-products, costing (optional WIP accounting), material requirements planning, reports |
| 🆕 HR | employees, work schedules, global holidays, attendance, time permissions (late / early leave / partial), leave, monthly payroll connected to the chart of accounts |
| Analytics | dashboard, reports, 🆕 product life cycle |

> **Flags used in the docs and the Postman collection:** 🆕 **NEW** = a new
> endpoint / module, 🔄 **CHANGED** = an existing endpoint that now accepts or
> returns more.

### Permissions

Every API endpoint is guarded by its own permission, named `{resource}.{action}`
(`expenses.view`, `payroll-runs.approve`...), so a role can be given `GET`
on expenses and nothing else. The list is derived from the routes —
`GET /api/v1/permissions` returns it grouped for a role editor, and
[`docs/api/permissions.md`](docs/api/permissions.md) lists every permission, the
endpoints it guards and which seeded roles hold it (regenerate with
`php artisan permissions:docs`). `Administrator` holds all of them.

### Manufacturing

A full manufacturing and planning module: bills of materials (versions, approval, alternatives, multi-level, by-products), manufacturing
orders that move through a validated workflow, batch-level **stock reservations** that sales, transfers and write-offs respect, routings and
work centers with capacity-aware scheduling, work orders on the shop floor, quality checks, scrap, costing against the chart of accounts (cost on receipt or
work-in-progress), **material requirements planning** that proposes purchase and manufacturing orders, and reports. Start with
[`docs/manufacturing/architecture-analysis.md`](docs/manufacturing/architecture-analysis.md); the full endpoint reference is
[`docs/api/manufacturing.md`](docs/api/manufacturing.md), and operating notes (migrations, settings, queue worker, scheduler) are in
[`docs/manufacturing/installation-and-operations.md`](docs/manufacturing/installation-and-operations.md).

### Documentation

- API reference: [`docs/api/README.md`](docs/api/README.md) (overview, conventions, change flags) and one file per module in [`docs/api/`](docs/api) — also served as a site at `/docs`.
- Postman: [`docs/postman/`](docs/postman) — import the collection and the `Local` environment; regenerate with `php docs/postman/generate.php` (never hand-edit the JSON).

### Setup

```bash
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate --seed
```

Upgrading an existing database after the latest update: see
[Upgrading an existing database](docs/api/README.md#upgrading-an-existing-database--new).

### Tests

```bash
php artisan test --compact
```

Feature tests run against MySQL (see `phpunit.xml`).

---

<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

In addition, [Laracasts](https://laracasts.com) contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

You can also watch bite-sized lessons with real-world projects on [Laravel Learn](https://laravel.com/learn), where you will be guided through building a Laravel application from scratch while learning PHP fundamentals.

## Agentic Development

Laravel's predictable structure and conventions make it ideal for AI coding agents like Claude Code, Cursor, and GitHub Copilot. Install [Laravel Boost](https://laravel.com/docs/ai) to supercharge your AI workflow:

```bash
composer require laravel/boost --dev

php artisan boost:install
```

Boost provides your agent 15+ tools and skills that help agents build Laravel applications while following best practices.

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
