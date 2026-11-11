# CRAP score skill for PHP

Claude Code skill that reports the [CRAP](https://www.artima.com/weblogs/viewpost.jsp?thread=210575) (Change Risk Anti-Patterns) score of a PHP project. It runs PHPUnit or Pest with `--coverage-crap4j`, then recomputes each method with `CRAP = cc² × (1 − cov)³ + cc` and recommends what to do first:

- `test` when raising coverage alone can bring the method back under the threshold (default 30), including the minimum coverage that does so
- `refactor` when cyclomatic complexity is already at least the threshold, because 100% coverage is still not enough

The skill only analyzes and reports. It does not change code and it does not write tests. It answers in the language you use.

## Install

Claude Code reads project skills from `.claude/skills/` and user skills from `~/.claude/skills/`. Copy the folder to either place:

```bash
mkdir -p .claude/skills
cp -R .claude/skills/crap-score /path/to/your-project/.claude/skills/crap-score
```

```bash
mkdir -p ~/.claude/skills
cp -R .claude/skills/crap-score ~/.claude/skills/crap-score
```

Ask for the CRAP score, the riskiest methods, or what to test or refactor first. You can point it at the whole project, one file, one class, one method, or the files changed against the main branch.

You need a coverage driver. The skill stops and tells you how to install one if `php -m` lists neither PCOV nor Xdebug. It will not install the driver for you.

## Check the fixture

`tests/fixture/` is a tiny PHPUnit project with three deliberate shapes: a simple fully tested method, a moderately complex partly tested method that only needs more tests, and an untested method whose complexity is at least 30 (refactor). `tests/crap_unit.php` checks the formula and `cov_min` with plain PHP.

```bash
php tests/crap_unit.php

cd tests/fixture
composer install
php -m | grep -i pcov
report="$(mktemp -d /tmp/crap4j.XXXXXX)/crap4j.xml"
vendor/bin/phpunit --coverage-crap4j "$report"
php ../../.claude/skills/crap-score/scripts/crap.php "$report"
```
