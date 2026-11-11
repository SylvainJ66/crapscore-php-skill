---
name: crap-score
description: >
  Compute the CRAP (Change Risk Anti-Patterns) score for a PHP codebase.
  Use when the user asks for the CRAP score, which methods are risky, or what to test or refactor first, for the whole project or for a target: one file, one class, one method, or the files changed against the main branch.
  Do not use when the user only wants the tests run, a plain coverage report, or tests written.
---

# CRAP score

Report only. Never modify project code, and never write or edit tests.

Answer in the language the user is using. The script prints English labels; translate the table headers, notes, and recommendation sentences. Keep the action names `test` and `refactor`.

## 1. Detect the context

Do not assume PHPUnit, Pest, a config file, or a coverage driver. From the project root, check:

- Runner: `vendor/bin/pest`, `vendor/bin/phpunit`, and `pestphp/pest` or `phpunit/phpunit` in `composer.json` (`require` and `require-dev`). If Pest is installed, use Pest; otherwise use PHPUnit. Both take `--coverage-crap4j`.
- Config: `phpunit.xml`, otherwise `phpunit.xml.dist`.
- Driver: `php -m`. Prefer PCOV when it is listed. Use Xdebug only when PCOV is absent.

If neither `pcov` nor `xdebug` is listed, stop. Do not install anything. Say:

> No coverage driver is loaded (`php -m` shows neither pcov nor xdebug). CRAP needs line coverage, so this stops here. Nothing was installed.
>
> Install one driver, then run this skill again:
>
> - PCOV (preferred): `pecl install pcov`, then enable it in php.ini with `extension=pcov.so` and `pcov.enabled=1`. On Debian/Ubuntu: `sudo apt install php-pcov`.
> - Xdebug: `pecl install xdebug`, then set `xdebug.mode=coverage`, or prefix the test command with `XDEBUG_MODE=coverage`.

If the runner binary is missing, stop and tell the user to install dev dependencies (`composer install`). Do not install them yourself.

## 2. Run the tests with coverage

Write the report outside the repository:

```bash
report="$(mktemp -d /tmp/crap4j.XXXXXX)/crap4j.xml"
```

PCOV:

```bash
vendor/bin/phpunit --coverage-crap4j "$report"
```

Xdebug, only when PCOV is not loaded (use `vendor/bin/pest` when step 1 selected Pest):

```bash
XDEBUG_MODE=coverage vendor/bin/phpunit --coverage-crap4j "$report"
```

If the command's exit code is non-zero, say clearly that the tests failed and that coverage, and therefore every CRAP score, is skewed. Keep that warning visible. You may still parse the report so the user can see the numbers.

## 3. Parse and recompute

Run `scripts/crap.php` from this skill directory. It is pure PHP and has no dependencies. Per method it prints class, method, complexity, coverage %, and CRAP. It recomputes `CRAP = cc² × (1 − cov)³ + cc` (`cov` is the coverage fraction; the XML percentage is divided by 100) and flags any mismatch with the crap4j value. php-code-coverage's CrapIndex also treats coverage of at least 95 as fully covered; the script flags that when it disagrees with the formula.

```bash
php /ABSOLUTE/PATH/TO/crap-score/scripts/crap.php "$report"
```

## 4. Filter, then show the table

Defaults: threshold `30`, top `20`, whole project. Pass only the target the user asked for.

- `--file=PATH` (repeatable)
- `--files-from=PATH` for a newline-separated list. Changed files: resolve the base branch (`origin/HEAD`, else `main`, else `master`) and use `git diff --name-only <base>...HEAD`
- `--class=NAME` short name or FQCN (repeatable)
- `--method=NAME` or `--method=Class::method` (repeatable)
- `--threshold=N` and `--limit=N` (`--limit=0` shows every match)

crap4j XML does not put a file path on each method. For `--file` and `--files-from`, the script reads those PHP files, collects `namespace` and `class` / `trait` / `interface` / `enum` declarations (`token_get_all`; it skips anonymous classes and `::class`), and keeps methods whose `className` (often an FQCN) or `package` plus short name matches. A dotted package such as `Foo.Bar` is treated as the namespace `Foo\Bar`. If a report nests `<method>` under `<file name="...">`, that path is used as well. Non-PHP paths and missing files are skipped and named in the output.

Show the table the script prints, sorted by CRAP descending.

## 5. Recommendations

For each shown method whose CRAP is not under the threshold (`CRAP >= threshold`), the script says:

- `test` when coverage alone can bring CRAP under the threshold. It gives `cov_min = 1 − cbrt((threshold − cc) / cc²)`. Coverage must be strictly greater than `cov_min` (equality leaves CRAP equal to the threshold).
- `refactor` when `cc >= threshold`, because 100% coverage still leaves CRAP equal to `cc`, which is not under the threshold.

Relay that table and those recommendations. Do not change the code.
