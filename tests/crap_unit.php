<?php

declare(strict_types=1);

use function CrapScore\buildReport;
use function CrapScore\crapValuesMatch;
use function CrapScore\declaredTypes;
use function CrapScore\formatReport;
use function CrapScore\minimumCoverage;
use function CrapScore\score;

require_once dirname(__DIR__) . '/.claude/skills/crap-score/scripts/crap.php';

$failures = 0;

function check(bool $condition, string $message): void
{
    global $failures;
    if ($condition) {
        fwrite(STDOUT, "ok  $message\n");
        return;
    }

    $failures++;
    fwrite(STDERR, "FAIL $message\n");
}

function near(float $actual, float $expected, float $epsilon = 1e-9): bool
{
    return abs($actual - $expected) <= $epsilon;
}

check(score(1.0, 1.0) === 1.0, 'full coverage leaves CRAP equal to complexity');
check(score(1.0, 0.0) === 2.0, 'uncovered complexity 1 is cc^2 + cc');
check(score(10.0, 0.0) === 110.0, 'uncovered complexity 10 is 110');
check(score(10.0, 1.0) === 10.0, 'covered complexity 10 is 10');
check(score(10.0, 0.5) === 22.5, 'half coverage of complexity 10 is 22.5');
check(score(12.0, 0.0) === 156.0, 'uncovered complexity 12 is 156');
check(score(30.0, 0.0) === 930.0, 'uncovered complexity 30 is 930');
check(score(30.0, 1.0) === 30.0, 'covered complexity 30 stays 30');

$bound = minimumCoverage(12.0, 30.0);
check($bound !== null && near($bound, 0.5), 'cov_min for cc 12 and threshold 30 is 0.5');
check($bound !== null && near(score(12.0, $bound), 30.0), 'CRAP equals the threshold exactly at cov_min');
check($bound !== null && score(12.0, $bound + 1e-6) < 30.0, 'coverage just above cov_min is under the threshold');
check($bound !== null && score(12.0, $bound - 1e-6) > 30.0, 'coverage just below cov_min stays over the threshold');

$other = minimumCoverage(8.0, 30.0);
check(
    $other !== null && $other > 0.0 && $other < 1.0 && near(score(8.0, $other), 30.0, 1e-6),
    'cov_min solves the formula for a non-cube ratio',
);
check(minimumCoverage(30.0, 30.0) === null, 'cc equal to the threshold cannot be tested under it');
check(minimumCoverage(31.0, 30.0) === null, 'cc above the threshold cannot be tested under it');
check(minimumCoverage(2.0, 30.0) === 0.0, 'complexity whose worst CRAP is already under the threshold needs no coverage');

check(crapValuesMatch(156.0, 156.0), 'integer crap4j values match the rounded formula');
check(crapValuesMatch(22.5, 22.50), 'two-decimal crap4j values match');
check(!crapValuesMatch(10.0064, 10.0), 'a 95% shortcut value does not match the formula');

$sample = <<<'PHP'
<?php
namespace Foo\Bar;

// class NotReal
$label = 'class AlsoNot';

#[Example]
final class Order
{
}

new #[Deprecated] class {
    public function ignored(): void {}
};

$reference = Order::class;

trait Extra {}
interface Contract {}
enum Kind {}
PHP;

$found = array_column(declaredTypes($sample), 'fqcn');
sort($found);
check($found === ['Foo\Bar\Contract', 'Foo\Bar\Extra', 'Foo\Bar\Kind', 'Foo\Bar\Order'], 'declarations skip comments, strings, anonymous classes, and ::class');

$braced = <<<'PHP'
<?php
namespace A {
    class One {}
}
class Two {}
namespace B;
class Three {}
PHP;
$bracedNames = array_column(declaredTypes($braced), 'fqcn');
check($bracedNames === ['A\One', 'Two', 'B\Three'], 'braced and semicolon namespaces bind the following types');

$directory = sys_get_temp_dir() . '/crap-unit-' . getmypid();
mkdir($directory);
$root = $directory . '/project';
mkdir($root);
file_put_contents($root . '/PricingEngine.php', <<<'PHP'
<?php
namespace Demo\Billing;

final class PricingEngine
{
    public function quote(string $code): int
    {
        return 1;
    }
}
PHP);
file_put_contents($root . '/notes.txt', "not php\n");
file_put_contents($directory . '/list.txt', "PricingEngine.php\nnotes.txt\nmissing.php\n");

$xmlPath = $directory . '/crap4j.xml';
file_put_contents($xmlPath, <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<crap_result>
  <project>sample</project>
  <timestamp>2026-01-01 00:00:00</timestamp>
  <stats><name>Method Crap Stats</name></stats>
  <methods>
    <method>
      <package>Demo.Billing</package>
      <className>PricingEngine</className>
      <methodName>quote</methodName>
      <crap>999</crap>
      <complexity>12</complexity>
      <coverage>0</coverage>
    </method>
    <method>
      <package>Demo\Billing</package>
      <className>Demo\Billing\SimpleCalculator</className>
      <methodName>add</methodName>
      <crap>1</crap>
      <complexity>1</complexity>
      <coverage>100</coverage>
    </method>
    <method>
      <package>Demo\Billing</package>
      <className>Demo\Billing\OrderProcessor</className>
      <methodName>process</methodName>
      <crap>930</crap>
      <complexity>30</complexity>
      <coverage>0</coverage>
    </method>
    <method>
      <package>Demo\Billing</package>
      <className>Demo\Billing\Almost</className>
      <methodName>edge</methodName>
      <crap>10</crap>
      <complexity>10</complexity>
      <coverage>96</coverage>
    </method>
  </methods>
</crap_result>
XML);

$report = buildReport([
    'input' => $xmlPath,
    'threshold' => 30.0,
    'limit' => 20,
    'files' => [],
    'files_from' => [],
    'classes' => [],
    'methods' => [],
    'root' => $root,
]);
check($report['matched'] === 4, 'parser reads every method element');

$byMethod = [];
foreach ($report['shown_rows'] as $row) {
    $byMethod[$row['method']] = $row;
}
check($byMethod['process']['recommendation']['action'] === 'refactor', 'cc >= threshold recommends refactor');
check($byMethod['quote']['recommendation']['action'] === 'test', 'partial complexity recommends test');
check(near((float) $byMethod['quote']['recommendation']['cov_min'], 0.5), 'quote cov_min is 0.5');
check($byMethod['quote']['match'] === false, 'a wrong crap4j value is flagged');
check($byMethod['add']['recommendation'] === null, 'a fully covered simple method is under the threshold');
check($byMethod['add']['match'] === true, 'a matching crap4j value is accepted');
check($byMethod['edge']['match'] === false, 'the 95% shortcut disagrees with the formula');
check(str_contains((string) $byMethod['edge']['hint'], 'coverage >= 95'), 'the 95% shortcut is explained');
check($report['shown_rows'][0]['method'] === 'process', 'rows are sorted by recomputed CRAP descending');

$text = formatReport($report);
check(str_contains($text, 'crap4j check: MISMATCH'), 'the rendered report names mismatches');
check(str_contains($text, 'quote — test'), 'the rendered report includes the test recommendation');
check(str_contains($text, 'process — refactor'), 'the rendered report includes the refactor recommendation');
check(str_contains($text, 'cov_min = 1 - cbrt((30 - 12) / 12^2)'), 'the rendered report shows the solved coverage');

$filtered = buildReport([
    'input' => $xmlPath,
    'threshold' => 30.0,
    'limit' => 20,
    'files' => [],
    'files_from' => [$directory . '/list.txt'],
    'classes' => [],
    'methods' => [],
    'root' => $root,
]);
check($filtered['matched'] === 1 && $filtered['shown_rows'][0]['method'] === 'quote', 'a file list keeps only classes declared in those files');
$filteredText = formatReport($filtered);
check(str_contains($filteredText, 'notes.txt (not a PHP file)'), 'non-PHP paths are skipped');
check(str_contains($filteredText, 'missing.php (missing)'), 'missing paths are skipped');
check(str_contains($filteredText, 'Demo\Billing\PricingEngine'), 'the file note shows the declared class');

$classFiltered = buildReport([
    'input' => $xmlPath,
    'threshold' => 100.0,
    'limit' => 1,
    'files' => [],
    'files_from' => [],
    'classes' => ['OrderProcessor'],
    'methods' => ['Demo\Billing\OrderProcessor::process'],
    'root' => $root,
]);
check($classFiltered['matched'] === 1, 'class and method filters combine');
check($classFiltered['shown_rows'][0]['recommendation']['action'] === 'test', 'raising the threshold turns a complex uncovered method into test');
check($classFiltered['hidden_above'] === 0, 'a single match is not hidden by the limit');

$limited = buildReport([
    'input' => $xmlPath,
    'threshold' => 1.0,
    'limit' => 1,
    'files' => [],
    'files_from' => [],
    'classes' => [],
    'methods' => [],
    'root' => $root,
]);
check($limited['hidden_above'] >= 1, 'the limit reports methods above the threshold that were not shown');

$script = dirname(__DIR__) . '/.claude/skills/crap-score/scripts/crap.php';
$command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($xmlPath) . ' --threshold=30 --class=SimpleCalculator';
exec($command, $cliOutput, $cliCode);
$cliText = implode("\n", $cliOutput);
check($cliCode === 0, 'the CLI exits 0 for a readable report');
check(str_contains($cliText, 'SimpleCalculator::add'), 'the CLI class filter keeps the requested class');
check(!str_contains($cliText, 'OrderProcessor::process'), 'the CLI class filter drops other classes');

$help = [];
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' --help', $help, $helpCode);
check($helpCode === 0 && str_contains(implode("\n", $help), 'cov_min'), 'help text documents the coverage bound');

$missing = [];
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($directory . '/nope.xml') . ' 2>/dev/null', $missing, $missingCode);
check($missingCode === 1, 'a missing report exits 1');

$skill = (string) file_get_contents(dirname(__DIR__) . '/.claude/skills/crap-score/SKILL.md');
foreach ([
    'coverage-crap4j',
    'XDEBUG_MODE=coverage',
    'pecl install pcov',
    'php -m',
    'cov_min',
    'refactor',
    'Never modify project code',
    'git diff --name-only',
] as $phrase) {
    check(str_contains($skill, $phrase), 'SKILL.md contains: ' . $phrase);
}

if ($failures > 0) {
    fwrite(STDERR, $failures . " check(s) failed.\n");
    exit(1);
}

fwrite(STDOUT, "All checks passed.\n");
exit(0);
