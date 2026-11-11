#!/usr/bin/env php
<?php

declare(strict_types=1);

namespace CrapScore;

use DOMDocument;
use DOMElement;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * CRAP = cc^2 * (1 - cov)^3 + cc, where cov is a fraction in [0, 1].
 * crap4j XML stores coverage as a percentage; callers divide by 100 first.
 */
function score(float $complexity, float $coverageFraction): float
{
    if ($coverageFraction < 0.0) {
        $coverageFraction = 0.0;
    } elseif ($coverageFraction > 1.0) {
        $coverageFraction = 1.0;
    }

    return ($complexity ** 2) * ((1.0 - $coverageFraction) ** 3) + $complexity;
}

function cbrt(float $value): float
{
    if ($value === 0.0) {
        return 0.0;
    }

    $negative = $value < 0.0;
    $absolute = $negative ? -$value : $value;
    $guess = exp(log($absolute) / 3.0);

    // Two Newton steps are enough for the equality CRAP(cov_min) ~= threshold.
    for ($step = 0; $step < 3; $step++) {
        $guess = ((2.0 * $guess) + ($absolute / ($guess * $guess))) / 3.0;
    }

    return $negative ? -$guess : $guess;
}

/**
 * Smallest coverage fraction that can bring CRAP under the threshold.
 * Returns null when cc >= threshold: even 100% coverage leaves CRAP = cc.
 * The bound is exact for equality. Coverage must be strictly greater than it.
 *
 * cov_min = 1 - cbrt((threshold - cc) / cc^2)
 */
function minimumCoverage(float $complexity, float $threshold): ?float
{
    if ($complexity >= $threshold) {
        return null;
    }

    if ($complexity <= 0.0) {
        return 0.0;
    }

    $ratio = ($threshold - $complexity) / ($complexity ** 2);
    if ($ratio >= 1.0) {
        return 0.0;
    }

    if ($ratio <= 0.0) {
        return null;
    }

    $bound = 1.0 - cbrt($ratio);
    if ($bound < 0.0) {
        return 0.0;
    }

    if ($bound > 1.0) {
        return 1.0;
    }

    return $bound;
}

function fractionFromPercent(float $percent): float
{
    if ($percent < 0.0) {
        $percent = 0.0;
    } elseif ($percent > 100.0) {
        $percent = 100.0;
    }

    return $percent / 100.0;
}

function formatCrap(float $value): string
{
    return sprintf('%0.2F', $value);
}

function crapValuesMatch(float $recomputed, float $reported): bool
{
    return formatCrap($recomputed) === formatCrap($reported);
}

/**
 * @return list<array{kind: string, namespace: string, name: string, fqcn: string}>
 */
function declaredTypes(string $source): array
{
    if (!function_exists('token_get_all')) {
        throw new RuntimeException('The tokenizer extension is required to map files to classes.');
    }

    if (str_starts_with($source, "\xEF\xBB\xBF")) {
        $source = substr($source, 3);
    }

    $tokens = token_get_all($source);
    $count = count($tokens);
    $namespace = '';
    $depth = 0;
    $namespaceDepth = null;
    $types = [];
    $typeIds = typeTokenIds();

    for ($index = 0; $index < $count; $index++) {
        $token = $tokens[$index];

        if ($token === '{') {
            $depth++;
            continue;
        }

        if ($token === '}') {
            if ($namespaceDepth !== null && $depth === $namespaceDepth) {
                $namespace = '';
                $namespaceDepth = null;
            }
            $depth = max(0, $depth - 1);
            continue;
        }

        if (!is_array($token)) {
            continue;
        }

        if ($token[0] === T_NAMESPACE) {
            $next = nextSignificant($tokens, $index + 1);
            if ($next !== null && is_array($tokens[$next]) && $tokens[$next][0] === T_NS_SEPARATOR) {
                continue;
            }

            $parsed = parseNamespaceDeclaration($tokens, $index + 1);
            $namespace = $parsed['name'];
            $index = $parsed['end'];
            if ($parsed['braced']) {
                $depth++;
                $namespaceDepth = $depth;
            }
            continue;
        }

        if (!in_array($token[0], $typeIds, true)) {
            continue;
        }

        if ($token[0] === T_CLASS && precededByDoubleColon($tokens, $index)) {
            continue;
        }

        if ($token[0] === T_CLASS && precededByNew($tokens, $index)) {
            continue;
        }

        $nameIndex = nextSignificant($tokens, $index + 1);
        if ($nameIndex === null || !is_array($tokens[$nameIndex]) || $tokens[$nameIndex][0] !== T_STRING) {
            continue;
        }

        $short = $tokens[$nameIndex][1];
        $types[] = [
            'kind' => typeKind($token[0]),
            'namespace' => $namespace,
            'name' => $short,
            'fqcn' => $namespace === '' ? $short : $namespace . '\\' . $short,
        ];
        $index = $nameIndex;
    }

    return $types;
}

/**
 * @return list<int>
 */
function typeTokenIds(): array
{
    $ids = [T_CLASS, T_TRAIT, T_INTERFACE];
    if (defined('T_ENUM')) {
        $ids[] = constant('T_ENUM');
    }

    return $ids;
}

function typeKind(int $tokenId): string
{
    if ($tokenId === T_TRAIT) {
        return 'trait';
    }
    if ($tokenId === T_INTERFACE) {
        return 'interface';
    }
    if (defined('T_ENUM') && $tokenId === constant('T_ENUM')) {
        return 'enum';
    }

    return 'class';
}

/**
 * @param list<mixed> $tokens
 * @return array{name: string, braced: bool, end: int}
 */
function parseNamespaceDeclaration(array $tokens, int $start): array
{
    $count = count($tokens);
    $parts = '';
    $index = $start;

    while ($index < $count) {
        $token = $tokens[$index];
        if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            $index++;
            continue;
        }
        if ($token === ';' || $token === '{') {
            break;
        }
        if (is_array($token) && in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NS_SEPARATOR], true)) {
            $parts .= ltrim((string) $token[1], '\\');
            $index++;
            continue;
        }
        break;
    }

    $braced = $index < $count && $tokens[$index] === '{';
    $name = trim(str_replace('/', '\\', $parts), '\\');

    return [
        'name' => $name,
        'braced' => $braced,
        'end' => $index,
    ];
}

/**
 * @param list<mixed> $tokens
 */
function nextSignificant(array $tokens, int $start): ?int
{
    $count = count($tokens);
    for ($index = $start; $index < $count; $index++) {
        $token = $tokens[$index];
        if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        return $index;
    }

    return null;
}

/**
 * @param list<mixed> $tokens
 */
function precededByDoubleColon(array $tokens, int $classIndex): bool
{
    $index = $classIndex - 1;
    while ($index >= 0) {
        $token = $tokens[$index];
        if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            $index--;
            continue;
        }

        return is_array($token) && $token[0] === T_DOUBLE_COLON;
    }

    return false;
}

/**
 * @param list<mixed> $tokens
 */
function precededByNew(array $tokens, int $classIndex): bool
{
    $index = $classIndex - 1;
    while ($index >= 0) {
        $token = $tokens[$index];
        if (is_array($token)) {
            $id = $token[0];
            if (
                in_array($id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_ABSTRACT, T_FINAL], true)
                || (defined('T_READONLY') && $id === T_READONLY)
                || (defined('T_ATTRIBUTE') && $id === T_ATTRIBUTE)
            ) {
                $index--;
                continue;
            }
            if ($id === T_NEW) {
                return true;
            }

            return false;
        }

        if ($token === ']') {
            $index--;
            while ($index >= 0) {
                $previous = $tokens[$index];
                if (is_array($previous) && defined('T_ATTRIBUTE') && $previous[0] === T_ATTRIBUTE) {
                    $index--;
                    break;
                }
                $index--;
            }
            continue;
        }

        return false;
    }

    return false;
}

function normalizeClass(string $name): string
{
    $name = trim(str_replace('/', '\\', $name), " \t\n\r\0\x0B\\");

    return $name;
}

function resolveFqcn(string $className, string $package): string
{
    $className = normalizeClass($className);
    $package = strtolower(trim($package)) === 'global' ? '' : normalizeClass(str_replace('.', '\\', $package));

    if ($className === '') {
        return $package;
    }

    if ($package === '' || str_contains($className, '\\')) {
        return $className;
    }

    return $package . '\\' . $className;
}

function shortName(string $fqcn): string
{
    $fqcn = normalizeClass($fqcn);
    $position = strrpos($fqcn, '\\');
    if ($position === false) {
        return $fqcn;
    }

    return substr($fqcn, $position + 1);
}

/**
 * @return list<array{class: string, package: string, method: string, complexity: float, coverage: float, crap_xml: float, file: ?string}>
 */
function parseReport(string $xml): array
{
    $previous = libxml_use_internal_errors(true);
    $document = new DOMDocument();
    $loaded = $document->loadXML($xml, LIBXML_NONET);
    $errors = libxml_get_errors();
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    if ($loaded === false) {
        $message = 'Invalid crap4j XML';
        if ($errors !== []) {
            $message .= ': ' . trim($errors[0]->message);
        }
        throw new RuntimeException($message);
    }

    $methods = [];
    foreach ($document->getElementsByTagName('method') as $node) {
        if (!$node instanceof DOMElement) {
            continue;
        }

        $complexity = childText($node, 'complexity');
        $coverage = childText($node, 'coverage');
        $crap = childText($node, 'crap');
        $method = childText($node, 'methodName');
        if ($complexity === null || $coverage === null || $crap === null || $method === null) {
            continue;
        }
        if (!is_numeric($complexity) || !is_numeric($coverage) || !is_numeric($crap)) {
            continue;
        }

        $methods[] = [
            'class' => childText($node, 'className') ?? '',
            'package' => childText($node, 'package') ?? '',
            'method' => $method,
            'complexity' => (float) $complexity,
            'coverage' => (float) $coverage,
            'crap_xml' => (float) $crap,
            'file' => ancestorFile($node),
        ];
    }

    return $methods;
}

function childText(DOMElement $node, string $name): ?string
{
    foreach ($node->childNodes as $child) {
        if ($child instanceof DOMElement && $child->tagName === $name) {
            $text = trim($child->textContent);
            return $text === '' ? null : $text;
        }
    }

    return null;
}

function ancestorFile(DOMElement $node): ?string
{
    for ($parent = $node->parentNode; $parent !== null; $parent = $parent->parentNode) {
        if ($parent instanceof DOMElement && $parent->tagName === 'file' && $parent->hasAttribute('name')) {
            $name = trim($parent->getAttribute('name'));
            return $name === '' ? null : $name;
        }
    }

    return null;
}

/**
 * @param array<string, mixed> $args
 * @return array<string, mixed>
 */
function buildReport(array $args): array
{
    $input = (string) $args['input'];
    if (!is_file($input) || !is_readable($input)) {
        throw new RuntimeException('Cannot read crap4j XML: ' . $input);
    }

    $xml = file_get_contents($input);
    if ($xml === false) {
        throw new RuntimeException('Cannot read crap4j XML: ' . $input);
    }

    $threshold = (float) $args['threshold'];
    if ($threshold < 0) {
        throw new InvalidArgumentException('Threshold must be zero or greater.');
    }

    $limit = (int) $args['limit'];
    if ($limit < 0) {
        throw new InvalidArgumentException('Limit must be zero or greater (zero means no limit).');
    }

    $root = (string) $args['root'];
    $fileFilter = collectFiles(
        is_array($args['files']) ? $args['files'] : [],
        is_array($args['files_from']) ? $args['files_from'] : [],
        $root,
    );

    $classes = array_map(static fn (string $name): string => normalizeClass($name), array_values($args['classes']));
    $methods = array_values(array_map(static fn (string $name): string => trim($name), $args['methods']));

    $rows = [];
    foreach (parseReport($xml) as $method) {
        $fqcn = resolveFqcn($method['class'], $method['package']);
        if (!passesFileFilter($method['file'], $fqcn, $fileFilter, $root)) {
            continue;
        }
        if (!passesClassFilter($fqcn, $method['class'], $classes)) {
            continue;
        }
        if (!passesMethodFilter($fqcn, $method['method'], $methods)) {
            continue;
        }

        $fraction = fractionFromPercent($method['coverage']);
        $recomputed = score($method['complexity'], $fraction);
        $match = crapValuesMatch($recomputed, $method['crap_xml']);
        $hint = '';
        if (
            !$match
            && $method['coverage'] >= 95.0
            && $method['coverage'] < 100.0
            && crapValuesMatch($method['crap_xml'], $method['complexity'])
        ) {
            $hint = 'php-code-coverage CrapIndex treats coverage >= 95 as full coverage and stores CRAP = cc. The formula does not.';
        } elseif (!$match) {
            $hint = 'Recomputed from the reported complexity and the reported coverage percentage.';
        }

        $rows[] = [
            'class' => $fqcn,
            'method' => $method['method'],
            'complexity' => $method['complexity'],
            'coverage' => $method['coverage'],
            'crap' => $recomputed,
            'crap_xml' => $method['crap_xml'],
            'match' => $match,
            'hint' => $hint,
            'recommendation' => recommend($method['complexity'], $fraction, $threshold),
        ];
    }

    usort($rows, static function (array $left, array $right): int {
        $byCrap = $right['crap'] <=> $left['crap'];
        if ($byCrap !== 0) {
            return $byCrap;
        }
        $byClass = strcmp($left['class'], $right['class']);
        if ($byClass !== 0) {
            return $byClass;
        }

        return strcmp($left['method'], $right['method']);
    });

    $above = array_values(array_filter($rows, static fn (array $row): bool => $row['recommendation'] !== null));
    $shown = $limit === 0 ? $rows : array_slice($rows, 0, $limit);
    $shownAbove = array_values(array_filter($shown, static fn (array $row): bool => $row['recommendation'] !== null));

    return [
        'xml' => $input,
        'threshold' => $threshold,
        'limit' => $limit,
        'classes' => $classes,
        'methods' => $methods,
        'file_filter' => $fileFilter,
        'matched' => count($rows),
        'shown_rows' => $shown,
        'above' => count($above),
        'hidden_above' => count($above) - count($shownAbove),
        'mismatches' => array_values(array_filter($rows, static fn (array $row): bool => $row['match'] === false)),
    ];
}

/**
 * @return array{action: string, cov_min: ?float}|null
 */
function recommend(float $complexity, float $coverageFraction, float $threshold): ?array
{
    if (score($complexity, $coverageFraction) < $threshold) {
        return null;
    }

    if ($complexity >= $threshold) {
        return [
            'action' => 'refactor',
            'cov_min' => null,
        ];
    }

    return [
        'action' => 'test',
        'cov_min' => minimumCoverage($complexity, $threshold),
    ];
}

/**
 * @param list<string> $files
 * @param list<string> $lists
 * @return array{active: bool, fqcns: array<string, string>, paths: array<string, string>, notes: list<string>}
 */
function collectFiles(array $files, array $lists, string $root): array
{
    $requested = $files;
    foreach ($lists as $list) {
        foreach (readList($list) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $requested[] = $line;
        }
    }

    $fqcns = [];
    $paths = [];
    $notes = [];

    foreach ($requested as $requestedPath) {
        $resolved = resolvePath($requestedPath, $root);
        if ($resolved === null || !is_file($resolved)) {
            $notes[] = $requestedPath . ' (missing)';
            continue;
        }
        if (!str_ends_with(strtolower($resolved), '.php')) {
            $notes[] = $requestedPath . ' (not a PHP file)';
            continue;
        }

        $source = file_get_contents($resolved);
        if ($source === false) {
            $notes[] = $requestedPath . ' (unreadable)';
            continue;
        }

        $paths[$resolved] = $resolved;
        $types = declaredTypes($source);
        if ($types === []) {
            $notes[] = $requestedPath . ' (no class, trait, interface, or enum)';
            continue;
        }

        $names = [];
        foreach ($types as $type) {
            $fqcns[strtolower($type['fqcn'])] = $type['fqcn'];
            $names[] = $type['fqcn'];
        }
        $notes[] = $requestedPath . ' -> ' . implode(', ', $names);
    }

    return [
        'active' => $requested !== [],
        'fqcns' => $fqcns,
        'paths' => $paths,
        'notes' => $notes,
    ];
}

/**
 * @return list<string>
 */
function readList(string $path): array
{
    if ($path === '-') {
        $data = stream_get_contents(STDIN);
    } else {
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('Cannot read file list: ' . $path);
        }
        $data = file_get_contents($path);
    }

    if ($data === false) {
        throw new RuntimeException('Cannot read file list: ' . $path);
    }

    $lines = preg_split("/\r\n|\n|\r/", $data);
    if ($lines === false) {
        return [];
    }

    return $lines;
}

function resolvePath(string $path, string $root): ?string
{
    $path = trim($path);
    if ($path === '') {
        return null;
    }
    if (!str_starts_with($path, '/')) {
        $path = rtrim($root, '/') . '/' . $path;
    }

    $real = realpath($path);

    return $real === false ? $path : $real;
}

/**
 * @param array{active: bool, fqcns: array<string, string>, paths: array<string, string>, notes: list<string>} $fileFilter
 */
function passesFileFilter(?string $xmlFile, string $fqcn, array $fileFilter, string $root): bool
{
    if ($fileFilter['active'] === false) {
        return true;
    }

    if ($xmlFile !== null) {
        $resolved = resolvePath($xmlFile, $root);
        if ($resolved !== null && isset($fileFilter['paths'][$resolved])) {
            return true;
        }
    }

    return isset($fileFilter['fqcns'][strtolower($fqcn)]);
}

/**
 * @param list<string> $wanted
 */
function passesClassFilter(string $fqcn, string $rawClass, array $wanted): bool
{
    if ($wanted === []) {
        return true;
    }

    $short = shortName($fqcn);
    $raw = normalizeClass($rawClass);
    foreach ($wanted as $name) {
        if ($name === '') {
            continue;
        }
        if (
            strcasecmp($name, $fqcn) === 0
            || strcasecmp($name, $short) === 0
            || strcasecmp($name, $raw) === 0
        ) {
            return true;
        }
    }

    return false;
}

/**
 * @param list<string> $wanted
 */
function passesMethodFilter(string $fqcn, string $method, array $wanted): bool
{
    if ($wanted === []) {
        return true;
    }

    $short = shortName($fqcn);
    foreach ($wanted as $name) {
        if ($name === '') {
            continue;
        }
        if (strcasecmp($name, $method) === 0) {
            return true;
        }
        if (!str_contains($name, '::')) {
            continue;
        }
        [$class, $methodName] = explode('::', $name, 2);
        $class = normalizeClass($class);
        if (strcasecmp($methodName, $method) !== 0) {
            continue;
        }
        if (strcasecmp($class, $fqcn) === 0 || strcasecmp($class, $short) === 0) {
            return true;
        }
    }

    return false;
}

/**
 * @param array<string, mixed> $report
 */
function formatReport(array $report): string
{
    $threshold = (float) $report['threshold'];
    $lines = [];
    $lines[] = 'CRAP score';
    $lines[] = 'Source: ' . $report['xml'];
    $lines[] = 'Threshold: ' . formatPlain($threshold);
    $lines[] = 'Limit: ' . ((int) $report['limit'] === 0 ? 'none' : (string) $report['limit']);
    $lines[] = 'Matched methods: ' . $report['matched'];
    $lines[] = 'Showing: ' . count($report['shown_rows']);

    $classFilter = $report['classes'];
    $methodFilter = $report['methods'];
    $lines[] = 'Class filter: ' . ($classFilter === [] ? 'none' : implode(', ', $classFilter));
    $lines[] = 'Method filter: ' . ($methodFilter === [] ? 'none' : implode(', ', $methodFilter));

    $fileFilter = $report['file_filter'];
    if ($fileFilter['active'] === false) {
        $lines[] = 'File filter: none';
    } else {
        $lines[] = 'File filter:';
        if ($fileFilter['notes'] === []) {
            $lines[] = '  (no paths)';
        }
        foreach ($fileFilter['notes'] as $note) {
            $lines[] = '  ' . $note;
        }
    }

    $lines[] = '';
    $lines[] = formatTable($report['shown_rows']);
    $lines[] = '';
    $lines[] = formatRecommendations($report['shown_rows'], $threshold, (int) $report['hidden_above']);
    $lines[] = '';
    $lines[] = formatMismatches($report['mismatches']);
    $lines[] = '';
    $lines[] = 'This report does not modify code and does not write tests.';
    $lines[] = '';

    return implode("\n", $lines);
}

/**
 * @param list<array<string, mixed>> $rows
 */
function formatTable(array $rows): string
{
    $header = ['CRAP', 'XML', 'CC', 'Cov%', 'OK', 'Action', 'Method'];
    $body = [];
    foreach ($rows as $row) {
        $action = '-';
        if (is_array($row['recommendation'])) {
            $action = (string) $row['recommendation']['action'];
        }
        $body[] = [
            formatCrap((float) $row['crap']),
            formatCrap((float) $row['crap_xml']),
            formatComplexity((float) $row['complexity']),
            sprintf('%0.2F%%', (float) $row['coverage']),
            $row['match'] ? 'yes' : 'MISMATCH',
            $action,
            $row['class'] . '::' . $row['method'],
        ];
    }

    $widths = [];
    foreach ($header as $index => $label) {
        $widths[$index] = strlen($label);
    }
    foreach ($body as $cells) {
        foreach ($cells as $index => $cell) {
            $widths[$index] = max($widths[$index], strlen($cell));
        }
    }

    $render = static function (array $cells, array $widths, bool $headerRow): string {
        $parts = [];
        foreach ($cells as $index => $cell) {
            $right = !$headerRow && $index <= 3;
            $parts[] = align((string) $cell, $widths[$index], $right);
        }

        return implode('  ', $parts);
    };

    $lines = [$render($header, $widths, true), $render(array_map(static fn (int $width): string => str_repeat('-', $width), $widths), $widths, true)];
    if ($body === []) {
        $lines[] = '(no methods)';
    }
    foreach ($body as $cells) {
        $lines[] = $render($cells, $widths, false);
    }

    return implode("\n", $lines);
}

function align(string $text, int $width, bool $right): string
{
    $length = strlen($text);
    if ($length >= $width) {
        return $text;
    }
    $pad = str_repeat(' ', $width - $length);

    return $right ? $pad . $text : $text . $pad;
}

function formatComplexity(float $complexity): string
{
    if (abs($complexity - round($complexity)) < 0.001) {
        return (string) (int) round($complexity);
    }

    return sprintf('%0.2F', $complexity);
}

function formatPlain(float $value): string
{
    if (abs($value - round($value)) < 0.001) {
        return (string) (int) round($value);
    }

    return sprintf('%0.2F', $value);
}

/**
 * @param list<array<string, mixed>> $rows
 */
function formatRecommendations(array $rows, float $threshold, int $hiddenAbove): string
{
    $lines = ['Recommendations (CRAP >= ' . formatPlain($threshold) . '):'];
    $count = 0;
    foreach ($rows as $row) {
        $recommendation = $row['recommendation'];
        if (!is_array($recommendation)) {
            continue;
        }
        $count++;
        $name = $row['class'] . '::' . $row['method'];
        $complexity = (float) $row['complexity'];
        $lines[] = $count . '. ' . $name . ' — ' . $recommendation['action'];
        if ($recommendation['action'] === 'refactor') {
            $lines[] = '   Cyclomatic complexity ' . formatComplexity($complexity)
                . ' is greater than or equal to the threshold ' . formatPlain($threshold) . '.';
            $lines[] = '   At 100% coverage, CRAP = ' . formatComplexity($complexity)
                . ', which is not under the threshold, so more tests will not clear it.';
            continue;
        }

        $covMin = $recommendation['cov_min'];
        $lines[] = '   Coverage alone can bring CRAP under ' . formatPlain($threshold) . '.';
        $lines[] = '   Current coverage is ' . sprintf('%0.2F%%', (float) $row['coverage']) . '.';
        if ($covMin === null) {
            $lines[] = '   cov_min could not be computed.';
            continue;
        }
        $ratio = ($threshold - $complexity) / ($complexity ** 2);
        $lines[] = '   cov_min = 1 - cbrt((' . formatPlain($threshold) . ' - ' . formatComplexity($complexity)
            . ') / ' . formatComplexity($complexity) . '^2) = 1 - cbrt(' . sprintf('%0.6F', $ratio) . ') = '
            . sprintf('%0.6F', $covMin) . ' (' . sprintf('%0.4F%%', $covMin * 100.0) . ').';
        $lines[] = '   Coverage must be strictly greater than ' . sprintf('%0.4F%%', $covMin * 100.0)
            . '. At exactly that coverage, CRAP equals the threshold.';
    }

    if ($count === 0) {
        $lines[] = 'None. Every shown method is under the threshold.';
    }
    if ($hiddenAbove > 0) {
        $lines[] = $hiddenAbove . ' more ' . ($hiddenAbove === 1 ? 'method was' : 'methods were') . ' omitted by the limit despite CRAP >= the threshold.';
    }

    return implode("\n", $lines);
}

/**
 * @param list<array<string, mixed>> $mismatches
 */
function formatMismatches(array $mismatches): string
{
    if ($mismatches === []) {
        return 'crap4j check: ok. Every matched method agrees with CRAP = cc^2 * (1 - cov)^3 + cc.';
    }

    $count = count($mismatches);
    $lines = ['crap4j check: MISMATCH. ' . $count . ' ' . ($count === 1 ? 'method disagrees' : 'methods disagree') . ' with the formula.'];
    foreach ($mismatches as $row) {
        $lines[] = '- ' . $row['class'] . '::' . $row['method']
            . ' recomputed ' . formatCrap((float) $row['crap'])
            . ', XML ' . formatCrap((float) $row['crap_xml'])
            . ' (cc ' . formatComplexity((float) $row['complexity'])
            . ', coverage ' . sprintf('%0.2F%%', (float) $row['coverage']) . ').';
        if ($row['hint'] !== '') {
            $lines[] = '  ' . $row['hint'];
        }
    }

    return implode("\n", $lines);
}

/**
 * @return array<string, mixed>
 */
function parseArgs(array $argv): array
{
    $args = [
        'help' => false,
        'threshold' => 30.0,
        'limit' => 20,
        'files' => [],
        'files_from' => [],
        'classes' => [],
        'methods' => [],
        'root' => getcwd() ?: '.',
        'input' => null,
    ];

    $tokens = array_slice($argv, 1);
    $count = count($tokens);
    for ($index = 0; $index < $count; $index++) {
        $token = $tokens[$index];
        if ($token === '--help' || $token === '-h') {
            $args['help'] = true;
            continue;
        }

        $name = $token;
        $value = null;
        if (str_starts_with($token, '--') && str_contains($token, '=')) {
            [$name, $value] = explode('=', $token, 2);
        }

        $take = static function () use (&$index, $count, $tokens, $value, $name): string {
            if ($value !== null) {
                return $value;
            }
            $index++;
            if ($index >= $count) {
                throw new InvalidArgumentException('Missing value for ' . $name);
            }

            return $tokens[$index];
        };

        switch ($name) {
            case '--threshold':
                $raw = $take();
                if (!is_numeric($raw)) {
                    throw new InvalidArgumentException('Threshold must be a number, got: ' . $raw);
                }
                $args['threshold'] = (float) $raw;
                break;
            case '--limit':
                $raw = $take();
                if (!preg_match('/^\d+$/', $raw)) {
                    throw new InvalidArgumentException('Limit must be a non-negative integer, got: ' . $raw);
                }
                $args['limit'] = (int) $raw;
                break;
            case '--file':
                $args['files'][] = $take();
                break;
            case '--files-from':
                $args['files_from'][] = $take();
                break;
            case '--class':
                $args['classes'][] = $take();
                break;
            case '--method':
                $args['methods'][] = $take();
                break;
            case '--root':
                $args['root'] = $take();
                break;
            default:
                if (str_starts_with($token, '-')) {
                    throw new InvalidArgumentException('Unknown option: ' . $token);
                }
                if ($args['input'] !== null) {
                    throw new InvalidArgumentException('Unexpected argument: ' . $token);
                }
                $args['input'] = $token;
        }
    }

    return $args;
}

function usage(): string
{
    return <<<TXT
Usage: php crap.php <crap4j.xml> [options]

Recompute CRAP = cc^2 * (1 - cov)^3 + cc from a PHPUnit/Pest --coverage-crap4j report.
cov is the coverage fraction. The XML coverage element is a percentage and is divided by 100.
Does not modify code and does not write tests.

A method with CRAP >= --threshold is recommended as:
  refactor  when cc >= threshold (100% coverage still leaves CRAP = cc)
  test      otherwise, with cov_min = 1 - cbrt((threshold - cc) / cc^2)
            Coverage must be strictly greater than cov_min.

Options:
  --threshold=N     Methods with CRAP >= N need a recommendation (default 30)
  --limit=N         Show the N highest CRAP methods (default 20, 0 = all)
  --file=PATH       Keep methods whose class is declared in this PHP file (repeatable)
  --files-from=PATH Newline-separated paths, or - for stdin (repeatable)
  --class=NAME      Short name or FQCN (repeatable)
  --method=NAME     Method name, or Class::method (repeatable)
  --root=PATH       Project root for relative paths (default: cwd)
  --help            Show this help

File targeting reads namespace and class/trait/interface/enum declarations from the
given PHP files (token_get_all). Anonymous classes and ::class are ignored. The
crap4j method list usually has no file path; className is matched as an FQCN, or
as package plus the short class name. A <file name="..."> ancestor is honored when present.

TXT;
}

function main(array $argv): int
{
    try {
        $args = parseArgs($argv);
    } catch (InvalidArgumentException $exception) {
        fwrite(STDERR, $exception->getMessage() . "\n");
        fwrite(STDERR, usage());

        return 1;
    }

    if ($args['help'] === true) {
        fwrite(STDOUT, usage());

        return 0;
    }

    if ($args['input'] === null) {
        fwrite(STDERR, "Missing crap4j XML path.\n");
        fwrite(STDERR, usage());

        return 1;
    }

    try {
        $report = buildReport($args);
    } catch (Throwable $exception) {
        fwrite(STDERR, $exception->getMessage() . "\n");

        return 1;
    }

    fwrite(STDOUT, formatReport($report));

    return 0;
}

if (PHP_SAPI === 'cli' && isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    exit(main($argv));
}
