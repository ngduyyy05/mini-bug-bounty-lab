# Mini Bug Bounty Lab — PHP edition

Two independent ecommerce-style security labs built with **PHP 8.3, Apache, PDO and MySQL 8.0**. Compare twelve intentionally vulnerable implementations with corresponding remediations. Both provide registration, bearer-token login, profiles, orders, avatars, feedback, admin search, checkout, gift cards, shipment tracking and help articles through a Bootstrap 5 / JavaScript UI and REST API.

## Start locally

Install Docker Desktop with the Linux engine (or Docker Engine with Compose). No host PHP, Composer or Python is required.

```sh
docker run --rm --mount "type=bind,source=${PWD},target=/lab" -w /lab php:8.3-cli php scripts/setup.php
docker compose up -d --build --wait
```

The setup script creates an ignored `.env` with randomly generated signing and database secrets; existing configuration is preserved. Start Apache applications at:

- Vulnerable: http://localhost:8000
- Fixed: http://localhost:8001
- REST reference: `/docs` on either application
- Storefront: `/`; account login: `/login`
- Fixed version and remediation source locations: http://localhost:8001/fixes

| Account | Role | Vulnerable password | Fixed password |
|---|---|---|---|
| alice | user | alice123 | alice12345 |
| bob | user | bob123 | bob12345 |
| staff | staff | staff123 | staff12345 |
| admin | admin | admin123 | admin12345 |

Alice owns orders 1 and 2; Bob owns 3 and 4. Products 1–3 cost $19.99, $39.99 and $12.50. Databases seed automatically once. Each application has its own database container, Docker network and upload volume. Database ports are not published. HTTP ports bind to loopback.

## Explore the application

The vulnerable website behaves like a small store, with products, accounts, orders, avatars, customer feedback and checkout. It retains twelve intentional vulnerability categories, including an upload/path-traversal/LFI execution chain. Pages do not identify vulnerable fields, provide payload presets or display exploitation instructions. Checkout displays the normal catalog total.

The account navigation includes gift cards (`/gift-card`), shipment tracking (`/shipping`) and help (`/help`). Tracking uses a synthetic Mini Delivery service on each application's own Docker network; neither delivery service publishes a host port. Its sample link works without external internet access. The help section contains shipping and returns articles.

The “Bản đã sửa” link opens a separate reference at `http://localhost:8001/fixes`, listing where each remediation lives under `app-fixed/`. This reference is optional and separate from the store workflow. Detailed scenarios, PoCs and the matrix below are solution material for review after independent testing.

## Verify all twelve scenarios

```sh
docker compose --profile test build tests
docker compose run --rm tests
```

This runs PHPUnit against the real Apache/PHP/MySQL services, including paired security tests and legitimate workflows. Individual reproducible proofs of concept:

```sh
docker compose run --rm tests php scripts/poc.php 01
# Substitute 02 through 12, or use all. Scenario 05 also verifies the upload/LFI chain.
```

The test runner writes `artifacts/security-regression.xml` and per-scenario JSON HTTP evidence. Tokens are redacted. The suite clears **only fixed login throttle buckets** before each test; it creates synthetic accounts, orders, feedback and uploaded markers. Run it on this disposable local lab, sequentially. It does not reset existing application data. Failed assertions cause a nonzero exit code. Scenario PoCs write JSON evidence; the full runner also writes JUnit.

| ID | Scenario | Vulnerable behavior | Fixed behavior |
|---|---|---|---|
| 01 | IDOR/BOLA | Read/write another user's profile; read their order | Owner/admin authorization |
| 02 | Broken access control | Ordinary users can list admin data | Server-enforced admin role |
| 03 | SQL injection | Search interpolated into SQL | Native PDO prepared statements |
| 04 | Stored XSS | Raw feedback HTML in wall | HTML output encoding |
| 05 | Upload / path traversal / LFI | Client folder escapes upload root; help includes uploaded PHP inside a PNG | Folder enum, image re-encoding, isolated storage and article allowlist |
| 06 | Weak JWT secret | Known `secret` permits subject impersonation | Random external key; strict algorithm/claims |
| 07 | Business logic | Client determines price and quantity constraints | Catalog prices, integer cents, quantity 1–20 |
| 08 | CORS | Reflect arbitrary credentialed origins | Exact origin allowlist |
| 09 | Missing rate limit | Unlimited authentication attempts | Atomic shared account/IP buckets |
| 10 | Information disclosure | SQL errors, stack traces and DSN exposed | Generic failures and safe conflict responses |
| 11 | SSTI | Gift message compiled as an unrestricted Twig template | Replace supported placeholders as plain text |
| 12 | SSRF | Tracking fetches arbitrary HTTP hosts and follows redirects | Exact carrier URL/path allowlist; redirects rejected |

See [scenario walkthroughs](docs/scenarios.md) for payloads, source explanations and verification criteria, [the demo playbook](docs/demo-playbook.md), [the sample pentest report](docs/pentest-report.md), and [security boundaries](docs/security-boundaries.md).

## Structure

```text
app-vulnerable/          Independent intentionally insecure PHP application
app-fixed/              Independent remediated PHP application
  public/index.php      Front controller and exception boundary
  src/Application.php   REST routes, authorization, upload and business rules
  src/Auth.php          Password hashing, JWT authentication, login throttling
  src/Database.php      PDO connection and query execution
  src/CustomerTools.php Gift previews, carrier requests and help articles
  content/help/         Shipping and returns article files
  templates/            PHP templates with HTML5, CSS3, Bootstrap 5, JavaScript
  database/schema.sql   MySQL/InnoDB schema
  bin/seed.php          Idempotent synthetic-data initialization
  composer.*            Locked application dependencies
scripts/setup.php       Generate local secrets without overwriting .env
scripts/poc.php         Execute one/all paired scenario proofs
services/delivery/      Synthetic carrier, deployed separately on each app network
tests/                  PHPUnit HTTP integration and security tests
Dockerfile.test         PHP 8.3 test environment
docker-compose.yml      Apache/PHP apps plus isolated MySQL services
```

Each application builds from its own directory and imports no files from the other. Fixed authentication, authorization and SQL paths are implemented independently; there is no runtime vulnerable/fixed switch. The vulnerable app uses a minimal HS256 codec to retain the intentionally weak short key; the fixed app uses the maintained `firebase/php-jwt` library. Composer security advisory blocking remains enabled, with committed lockfiles. PHPUnit 12.5 supports the PHP 8.3 runtime used here.

API paths and normal responses are retained where practical. MySQL DECIMAL amounts in order list/detail JSON are represented as decimal strings to preserve precision; checkout returns a JSON numeric `charged_amount`. `/docs` is now an explicit REST reference instead of FastAPI's generated Swagger page. There is no Python application or tooling dependency.

## Development and operations

Rebuild after edits with `docker compose up -d --build --wait`. Run checks as above. CI builds and runs the same Compose stack and uploads JSON/JUnit evidence. `docker compose logs app-fixed` shows server diagnostics, which the fixed API does not expose to clients. `docker compose stop` preserves lab data. `docker compose down` removes containers and networks while keeping named volumes.

To intentionally erase this PHP lab's database and upload volumes and reseed:

```sh
docker compose down -v
docker compose up -d --build --wait
```

Back up anything you want to retain first. Existing SQLite databases from older revisions are not imported or deleted. The old source remains available through Git history.

## Scope

This is a local training lab containing deliberate vulnerabilities, including PHP upload execution. Keep the loopback bindings. Use synthetic data. The fixed app demonstrates the twelve remediations, with documented limits around token storage, logout and operational deployment; it is not a production ecommerce platform.

Implementation references: [PDO prepared statements](https://www.php.net/manual/en/pdo.prepared-statements.php), [PHP password hashing](https://www.php.net/manual/en/function.password-hash.php), [JWT library](https://github.com/googleapis/php-jwt), [Twig template API](https://twig.symfony.com/doc/3.x/api.html), [PHP include](https://www.php.net/manual/en/function.include.php), [PHPUnit installation](https://docs.phpunit.de/en/12.5/installation.html).
