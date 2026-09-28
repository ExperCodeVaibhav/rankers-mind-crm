# Repository quality report

Audit date: 2026-09-28. Scorer: the user-supplied `auto-repo-score.zip`.
All seven Python scripts were verified byte-for-byte against the archive; the scorer
and its formulas were not modified. No empty, backdated or fabricated commits were added.

## Before and after

| Evaluation | Baseline | Improved | Change |
| --- | ---: | ---: | ---: |
| Original automatic classification | 34.4 / 100 (F) | 51.0 / 100 (D) | +16.6 |
| Explicit `--class fullstack` | 33.6 / 100 (F) | 54.7 / 100 (D) | +21.1 |

Baseline commit: `ccc457f`. Improved application commit: `f185e86`.
Both measurements used clean local Git clones, without `.env`, installed dependencies,
database dumps, screenshots or browser profiles. The baseline is the existing CRM
import with publication-safe Git exclusions, not an invented development history.
Subsequent documentation commits record this report; they do not change the evaluated code.

The automatic classifier chooses `data_engineering` because it recognizes the SQL
schema but not this custom PHP MVC stack. The explicit fullstack result is shown
separately rather than silently changing the evaluation profile.

| Dimension | Baseline | Improved |
| --- | ---: | ---: |
| Test coverage heuristic | 0.0 | 0.0 |
| Code cleanliness | 51.6 | 66.8 |
| Architecture and robustness | 33.2 | 48.4 |
| Dependency health | 75.0 | 75.0 |
| Documentation and onboarding | 58.0 | 100.0 |
| CI maturity heuristic | 0.0 | 20.0 |
| Security hygiene | 8.0 | 78.0 |
| History and maintenance | 58.0 | 58.0 |

Full machine reports and SHA-256 provenance are in [quality/](quality/).
Report paths are normalized for portability; scores, evidence and raw signals are retained.

## Actual verification

[GitHub Actions run 36398921437](https://github.com/ExperCodeVaibhav/rankers-mind-crm/actions/runs/36398921437)
passed for commit `f185e86`:

- PHP 8.0, 8.3 and 8.4: Composer manifest validation, PHP syntax/correctness,
  PHPStan level 1, 29 PHPUnit tests with 36 assertions, and dependency audit.
- Full Git history scan with Gitleaks: no leaks found.
- Docker image build and Apache/MariaDB startup from a fresh database.
- Schema installation, generated administrator credentials, six HTTP checks,
  leave authorization checks and a real database-outage test requiring HTTP 503.

The local XAMPP login also returned HTTP 200 after the changes. Dompdf was upgraded
from 3.1.5 to 3.1.6; the initial six dependency advisories were absent in the final audit.

## Confirmed scorer limitations

1. `repo_stats.py` has no PHP test-file pattern in `TEST_PATTERNS`. It recognizes
   `phpunit.xml`, but still reports zero test files. This is not a coverage measurement.
2. `CI_CONFIGS` lists `.github/workflows` without a trailing slash, while `analyze_ci`
   recognizes directory entries using `endswith('/')`. It attempts to read the
   directory as a file, then skips its fallback because GitHub Actions was already
   registered. Consequently it reports no lint/test/typecheck steps despite the
   successfully executed workflow.
3. Dependency counts and validation/logging recognition are incomplete for PHP.
   Some static signals also match generic words rather than actual instrumentation.
4. The baseline secret hits were fixed passwords for temporary test accounts.
   Those accounts now receive randomly generated passwords. Private runtime data
   and `.env` were excluded from the initial commit; Gitleaks also found no leaks.

No score was manually adjusted to compensate for these limitations. The project
still has legacy compact controllers/templates and demo-dependent historical tests.
Whole-application code coverage has not been measured. A higher heuristic score is
not a claim that every workflow has been reviewed or that the CRM has no vulnerabilities.

## Reproduce

Extract the original scorer outside the checkout, create a clean clone and run:

```sh
python /path/to/auto-repo-score/scripts/auto_score.py /path/to/clean-clone --report auto.json
python /path/to/auto-repo-score/scripts/auto_score.py /path/to/clean-clone --class fullstack --report fullstack.json
```

On Windows, set `PYTHONUTF8=1`. Use the recorded commits for exact comparisons.
History recency can change with time. Keep reports outside the scanned tree when
reproducing a particular commit's result.
