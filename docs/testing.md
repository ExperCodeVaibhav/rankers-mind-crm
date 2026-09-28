# Testing

## Fast checks without a database

```sh
php tools/composer.phar install
php tools/composer.phar validate --strict
php tools/composer.phar check
php tools/composer.phar audit --locked
```

On XAMPP, replace `php` with `C:\xampp\php\php.exe` and put that directory first
on PATH for Composer's executable scripts. PHPUnit bootstraps only Composer's
autoloader; unit tests neither read `.env` nor connect to the installed database.

The suite covers validation reuse, malformed input, calendar/time validation,
Unicode length, process environment precedence, routing, health response redaction
and actual PDF output from the patched Dompdf dependency. These assertions do not
represent complete CRM coverage. No coverage percentage is claimed or enforced.

`tools/lint.php` checks PHP syntax across application, routes, CLI tools and tests.
PHP_CodeSniffer enforces basic correctness rules. PHPStan level 1 checks application
logic, routes, scheduler and installer; injected view variables are outside its scope.

## Isolated database tests

GitHub Actions builds the Docker image with MariaDB, generates fresh credentials,
installs a `rankers_mind_crm_test` database and runs:

- `tests/http-smoke.php`: health JSON, login form, CSRF rejection, anonymous access,
  protected environment files and a real CSS asset.
- `tests/leave-authorization.php`: staff-owned leave requests, blocked approval
  tampering, staff edit restrictions and HR approval.
- A database-outage probe that must return HTTP 503 without private error details.

To reproduce locally, use a separate environment file and Compose project name so
the volumes cannot be confused with your normal installation. Set `APP_ENV=testing`,
`DB_DATABASE=rankers_mind_crm_test` and random credentials in `.env.integration`:

```sh
docker compose -p crm-tests --env-file .env.integration up --build -d --wait
docker compose -p crm-tests --env-file .env.integration exec --user www-data app php install.php
docker compose -p crm-tests --env-file .env.integration exec -e APP_URL=http://127.0.0.1 app php tests/http-smoke.php
docker compose -p crm-tests --env-file .env.integration exec -e APP_URL=http://127.0.0.1 --user www-data app php tests/leave-authorization.php
docker compose -p crm-tests --env-file .env.integration down --volumes
```

The final command deletes only this explicitly named disposable project's volumes.
Stop any app using host port 8080 before starting this test stack.

## Historical acceptance scripts

`smoke.php`, `workflow.php`, `ticket-assignment.php` and `ticket-resolution.php`
assume the former demo accounts and business fixtures. The current installer removes
those fixtures. These scripts are retained for reference but are **not claimed as
passing on a fresh installation** and are not part of the default CI suite.
All database scripts reject execution unless process environment variables identify
a disposable test database. Never repopulate demo fixtures in a production database.

## Repository score

The user-supplied `auto-repo-score` script is an external static heuristic, not a test
runner. Its PHP test-file patterns and validation/library recognition are incomplete;
its auto-classifier can mistake this CRM for a data engineering project. Keep both
the unmodified auto result and the explicitly selected `fullstack` comparison when
reporting scores. Use clean Git clones to exclude local credentials, vendor code,
browser profiles and runtime data. Do not edit the scorer or add pretend tooling to
increase a number. See [quality report](quality-report.md) for captured results.
