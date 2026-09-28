# Changelog

## Unreleased

### Fixed

- Upgraded Dompdf from 3.1.5 to 3.1.6 and refreshed its locked dependencies to address
  the dependency advisories found during the repository audit.
- Clear validation errors between calls; reject non-scalar input and invalid email
  values, calendar dates and times while retaining HTML datetime-local support.
- Use PHP 8.0-compatible native return types for exit-only helpers.
- Return a nonzero CLI exit status for unhandled application exceptions, so failed
  integration checks cannot appear successful.

### Added

- PHPUnit regressions for validation, routing, environment precedence, database
  health reporting and PDF rendering.
- Blocking syntax, correctness, static analysis, dependency audit and secret checks
  in GitHub Actions, plus disposable Apache/MariaDB integration checks.
- Docker installation with persistent storage and a database readiness endpoint.
- Contribution, architecture, test isolation and security documentation.
- Weekly dependency update configuration and review/issue templates.

### Repository history

- Imported the existing CRM in a single baseline commit. Existing modules were
  already present before this Git repository was initialized.
- Subsequent commits record the actual quality work performed after that import.
  They do not reconstruct earlier development dates or contributor history.
