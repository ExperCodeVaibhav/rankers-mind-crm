# Contributing

Start with the [setup instructions](README.md) and [architecture](docs/architecture.md).
The project is proprietary; ask the repository owner before reusing its code elsewhere.

## Development workflow

1. Create a focused branch from `main` and explain the user-visible problem.
2. Install dependencies with `php tools/composer.phar install`.
3. Add a regression test when fixing behavior. Unit tests must not load `.env`,
   start a session or connect to the local database.
4. Run `php tools/composer.phar check` and `php tools/composer.phar audit --locked`.
5. For database/authorization work, use the isolated integration environment in
   [docs/testing.md](docs/testing.md). Keep migrations compatible with existing data.
6. Submit a pull request describing behavior, verification and deployment needs.

Use descriptive commits such as `fix: reject invalid attendance dates` or
`test: cover client ownership checks`. Keep commits tied to real changes; do not
backdate work, manufacture contributor identities or add empty activity commits.

## Code conventions

- Keep request authorization in middleware/controllers and business transactions in services.
- Use PDO parameters for values, allowlists for identifiers, CSRF checks on mutations
  and ownership checks in addition to role permissions.
- Escape view output with `e()` and keep private uploads behind permission checks.
- Follow `.editorconfig`; new PHP code should be readable and declare strict types.
- Commit `composer.lock` after dependency changes. Runtime libraries belong in
  `require`; test and analysis tools belong in `require-dev`.
- Never commit `.env`, database exports, attendance photos, client documents,
  browser profiles, screenshots of customer data or real account credentials.

Weekly Dependabot updates cover Composer packages and GitHub Actions. Changes still
need the same checks and review as application code.
