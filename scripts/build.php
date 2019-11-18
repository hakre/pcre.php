#!/usr/bin/env php
<?php declare(strict_types=1);

/*
 * this file is part of pcre.php
 */

$print = false;
$buffer = [];
foreach (file(__DIR__ . '/../pcre.php') as $line) {
    if (!$print && !preg_match('(^ \* usage: )', $line)) continue;
    $print = true;
    $buffer[] = preg_replace('(^ \*(?: |/?$))', '', $line);
    if (preg_match('(^ \*/$)', $line)) break;
}

# echo implode('', $buffer), "\n";

$pathReadme = __DIR__ . '/../README.md';
$readme = file_get_contents($pathReadme);

$start = strpos($readme, "## Usage\n");
if (false === $start) {
    fwrite(STDERR, "build.php: unable to find '## Usage' in README.md\n");
    exit(1);
}
$start += 9;
$end = strpos($readme, "---\n", $start);
if (false === $end) {
    fwrite(STDERR, "build.php: unable to find '## Usage' end in README.md\n");
    exit(1);
}

$readme = substr_replace($readme, "\n~~~\n" . implode('', $buffer) . "~~~\n", $start, $end - $start);

$result = file_put_contents($pathReadme, $readme);
fprintf(STDERR, "build.php: written usage information to readme: %d bytes\n", $result);
