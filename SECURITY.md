# Security

Security fixes are maintained on `main`. The repository does not claim a completed
security audit or complete application test coverage.

## Reporting an issue

Use the repository's **Security → Report a vulnerability** feature for private reports.
Include affected behavior, reproduction steps with synthetic data and expected access
boundaries. Do not put credentials, customer data or exploit details in a public issue.
If private reporting is unavailable, contact the repository owner through their GitHub
profile before sharing sensitive details.

## Deployment requirements

- Serve only `public/`; use HTTPS and secure session cookies in production.
- Use a supported PHP version and run `composer audit --locked` after updates.
- Set a unique application key and initial administrator password outside Git.
- Use a dedicated database account; do not publish the database port.
- Keep private files, logs and backups outside the public web root.
- Restrict staff/client access by record ownership as well as permissions.
- Test restores in a separate database and keep encrypted off-server backups.

CI runs blocking dependency and secret scans. These checks cannot prove that all
vulnerabilities or secrets are absent. Gitleaks findings must be investigated, and
exposed credentials must be rotated even after removing a file from Git.

## Test isolation

Database integration scripts require explicit `APP_ENV=testing` and a database name
ending in `_test`. Existing demo-credential scripts are historical acceptance tests,
not deployment credentials. Newly created test accounts use random passwords.
