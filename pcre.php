#!/usr/bin/env php
<?php declare(strict_types=1);
/*
 * pcre pattern search through files (w/ replace)
 *
 * cli wrapper around php preg_grep / preg_replace etc. taking a list
 * of files from stdin/file.
 *
 * this work would not be possible w/o the work in libpcre (pcre.org)
 * and the fine people in the php project (php.net) and community (room11).
 *
 * usage: pcre.php [<options>] [<search> [<replace>]]
 *                 [<options>] --print-paths
 *
 * Common options
 *     -n, --dry-run         do not write changes to files
 *     -v                    be verbose
 *     --show-match          show path, line number and match(es)/replacement(s)
 *     --count-matches       count distinct matches in descending order
 *     --print-paths         print paths to stdout, one by line and exit
 *     --lines-not | --lines-only <pattern>
 *                           filter lines b/f doing search and replace
 *     -m, --multiple        search and replace multiple times on the same line
 *
 * File selection options
 *     -T, --files-from=<file>
 *                           read paths of files to operate on from <file>. The
 *                           special filename "-" or given no filename will read
 *                           files from standard input.
 *                           each filename is separated by LF ("\n") in a file.
 *     --fnmatch <pattern>   filter the list of path(s) by fnmatch() pattern
 *     --only <pattern>      only operate on files having a line matching pcre
 *                           pattern
 *     --invert              invert the meaning of --only pcre pattern match,
 *                           operate on files that do not have a line matching
 *                           the pcre pattern
 *     --file-match <pattern>
 *                           only operate on files their contents (not lines)
 *                           matches the pcre pattern
 *     --file-match-invert   invert the meaning of --file-match
 */

/**
 * print usage information
 */
function show_usage()
{
    $lines = file(__FILE__);
    $print = false;
    foreach ($lines as $line) {
        if (!$print && !preg_match('(^ \* usage: )', $line)) continue;
        $print = true;
        echo preg_replace('(^ \*(?: |/?$))', '', $line);
        if (preg_match('(^ \*/$)', $line)) break;
    }
    echo "\n";
}

/**
 * Class iter
 *
 * @method iter fromFile(string $path, string $ending = "\n", bool $terminate = false)
 * @see iter::file()
 * @see iter::__callStatic()
 *
 * @method iter doFilter(callable $cb)
 * @see iter::filter()
 * @see iter::__call()
 *
 * @method iter doFirst(callable $cb)
 * @see iter::first()
 *
 * @method iter doLast(callable $cb)
 * @see iter::last()
 *
 * @method iter doMap(callable $cb)
 * @see iter::map()
 */
class iter implements IteratorAggregate
{
    /**
     * create iterable to read file line-by-line (binary)
     *
     * @param string $path
     * @param string $ending delimiter for each line/chunk
     * @param bool $terminate terminate yields w/ ending (false by default)
     * @return Generator
     */
    public static function file(string $path, string $ending = "\n", bool $terminate = false): Generator
    {
        /* @link https://bugs.php.net/bug.php?id=53465 */
        $path = preg_replace('(^/(?:proc/self|dev)/(fd/\d+))', 'php://\1', $path);

        $fp = fopen($path, 'rb');
        if (false === $fp) return;
        while (!feof($fp) && $line = stream_get_line($fp, 4096, $ending)) {
            if ($terminate) {
                yield $line . $ending;
            } else {
                yield $line;
            }
        }
        fclose($fp);
    }

    /**
     * filter iterable by callback
     *
     * @param iterable $it
     * @param callable $cb
     * @return Generator
     */
    public static function filter(iterable $it, callable $cb): Generator
    {
        foreach ($it as $buffer) {
            if ($result = $cb($buffer)) {
                yield $buffer;
            }
        }
    }


    /**
     * map iterable by callback
     *
     * @param iterable $it
     * @param callable $cb
     * @return Generator
     */
    public static function map(iterable $it, callable $cb): Generator
    {
        foreach ($it as $key => $value) {
            yield $key => $cb($value);
        }
    }

    private $iter;

    public function __construct(iterable $iter)
    {
        $this->iter = $iter;
    }

    /**
     * @return Traversable
     */
    public function getIterator(): Traversable
    {
        return $this->iter instanceof Traversable ? $this->iter : new ArrayObject($this->iter);
    }

    /**
     * start iteration with first element which is accepted by
     * the callback
     *
     * @param iterable $it
     * @param callable $cb
     * @return Generator
     */
    public static function first(iterable $it, callable $cb)
    {
        foreach ($it as $key => $value) {
            $result = $cb($value);
            if (!$result) {
                continue;
            }
            yield $key => $value;
            yield from $it;
            break;
        }
    }

    /**
     * end iteration by callback
     *
     * yielding stops after the callback returned true
     *
     * @param iterable $it
     * @param callable $cb
     * @return Generator
     */
    public static function last(iterable $it, callable $cb): Generator
    {
        foreach ($it as $key => $value) {
            yield $key => $value;
            $result = $cb($value);
            if ($result) {
                break;
            }
        }
    }

    /**
     * decorate as self
     *
     * @param $name
     * @param $arguments
     * @return iter
     */
    public function __call($name, $arguments): self
    {
        if (preg_match('(^do(.*)$)', $name, $matches)) {
            list(, $short) = $matches;
            $static = [$this, $short];
            if (is_callable($static)) {
                return $this->iter = new self($static($this->iter, ...$arguments));
            }
        }

        throw new BadMethodCallException($name);
    }

    /**
     * create as self
     *
     * @param $name
     * @param $arguments
     * @return iter
     */
    public static function __callStatic($name, $arguments): self
    {
        if (preg_match('(^from(.*)$)', $name, $matches)) {
            list(, $short) = $matches;
            $static = [__CLASS__, $short];
            if (is_callable($static)) {
                return new self($static(...$arguments));
            }
        }

        throw new BadMethodCallException($name);
    }

    public function toArray(): array
    {
        $iterable = $this->iter;
        if ($iterable === null) {
            return [];
        }
        if (is_array($iterable)) {
            return $iterable;
        }

        if ($iterable instanceof Traversable) {
            return iterator_to_array($iterable);
        }

        throw new UnexpectedValueException(sprintf('Neither array nor traversable: %s', gettype($iterable)));
    }

    /**
     * @param callable $callback
     * @return void
     */
    public function each(callable $callback)
    {
        foreach ($this->iter as $value) {
            $callback($value);
        }
    }
}

/**
 * Yield string line-by-line
 *
 * @param string $buffer
 * @return Generator
 */
function string_iter(string $buffer, $ending = "\n")
{
    $start = 0;
    $buffer_len = strlen($buffer);
    $ending_len = strlen($ending);

    if (0 === $buffer_len) {
        yield $buffer;
    }

    while ($start < $buffer_len) {
        $pos = strpos($buffer, $ending, $start);
        if ($pos === false) {
            yield substr($buffer, $start);
            break;
        }
        $stop = $pos;
        yield substr($buffer, $start, $stop - $start);
        $start = $stop + $ending_len;
    }
}

function preg_pattern_valid(string $pattern): bool
{
    error_clear_last();
    if (!$valid = false !== @preg_match($pattern, '')) {
        fprintf(STDERR, "pcre.php: invalid pattern: %s\n", error_get_last()['message']);
    }
    return $valid;
}

/**
 * preg_grep for no (null), one or multiple patterns
 *
 * @param null|string|string[] $pattern(s)
 * @param array $input
 * @param int $flags
 * @return array|string[]
 */
function preg_grep_multiple($pattern, array $input, int $flags = 0)
{
    if (null === $pattern) {
        return $input;
    }

    $buffer = $input;
    /** @noinspection SuspiciousLoopInspection */
    foreach (is_iterable($pattern) ? $pattern : [$pattern] as $pattern) {
        $buffer = preg_grep($pattern, $buffer, $flags);
    }

    return $buffer;
}

function preg_replacement_valid(string $replacement)
{
    return null !== @preg_replace('//', $replacement, '');
}

/**
 * print all matches and replacements per match on separate lines with the
 * actual match only (not the whole like)
 *
 * @param int $index number of line starting at zero
 * @param string $line contents (string)
 * @param string $pattern
 * @param string $replacement
 */
function print_all_matches(int $index, string $line, string $pattern, string $replacement)
{
    $result = preg_match_all($pattern, $line, $matches, PREG_SET_ORDER);
    if (!$result) {
        return;
    }

    foreach ($matches as $matchIndex => $match) {
        $buffer = $match[0];
        printf("    ^%s\$\n", $buffer);
        $changed = preg_replace($pattern, $replacement, $buffer);
        printf("    ^%s\$\n", $changed);
    }
}

/**
 * Class getopt
 *
 * static helper class for parsing command-line arguments w/ php getopt()
 *
 * $opts    - array in the form of getopt() return
 * $options - names of options, including longopts
 */
class getopt
{
    /**
     * get selected option arguments from parsed options
     *
     * @param array $opts getopt result
     * @param string ...$options
     * @return array option argument values, false if an option
     *          was with no argument (switch/flag), string for
     *          a concrete value
     */
    public static function args(array $opts, string ...$options): array
    {
        $reservoir = [];
        foreach ($options as $option) {
            $reservoir[] = (array)($opts[$option] ?? null);
        }

        return $reservoir ? array_merge(...$reservoir) : [];
    }

    /**
     * map parsed options to concrete value w/ flag meaning and default value
     *
     * choose a string (or callback) for $flag to override true
     *
     * choose a string for $default to provide an argument default value
     * (hint: maybe using `getopt::arg(...) ?? $default` is more applicable)
     *
     * string wins over false (option argument over option only); last wins
     *
     * @param array $args all option arguments, key is option name, values
     *              are false for option w/o argument (switch), string for
     *              option argument
     * @param string|true|Closure $flag (optional) value to use for flag
     *              usage (option w/o argument given), defaults to true
     * @param string|bool|Closure $default (optional) default value to use
     *              (no option at all given)
     * @return null|true|string null or default string for default, true for
     *              default flag value or string flag value or string option
     *              argument value
     */
    public static function arg(array $args, $flag = null, $default = null)
    {
        $result = null;
        foreach ($args as $arg) {
            if (false === $arg && is_string($result)) { # option argument overrides option only
                continue;
            }
            $result = $arg;
        }

        false === $result && $result = ($flag instanceof Closure ? $flag() : $flag ?? true);
        return $result ?? ($default instanceof Closure ? $default() : $default);
    }

    /**
     * index getopt() options and long-options
     *
     * parse (short) options string and longopts array into a map
     * containing the option name as key and the flags as value.
     *
     * @param string $options
     * @param array $longopts
     * @return array
     */
    public static function idxopt(string $options, array $longopts): array
    {
        $opts = [];
        $buffer = $options;
        $pos = 0;
        while (isset($buffer[$pos])) {
            $name = $buffer[$pos++];
            $len = 0;
            (':' === ($buffer[$pos] ?? null)) && ++$len && (':' === ($buffer[$pos + 1] ?? null)) && ++$len;
            $opts[$name] = $len ? substr($buffer, $pos, $len) : '';
            $pos += $len;
        }

        foreach ($longopts as $buffer) {
            $len = 0;
            (':' === ($buffer[-1] ?? null)) && ++$len && (':' === ($buffer[-2] ?? null)) && ++$len;
            $name = $len ? substr($buffer, 0, -$len) : $buffer;
            $opts[$name] = $len ? substr($buffer, -$len) : '';
        }

        return $opts;
    }

    /**
     * error on unknown options and missing required option arguments
     *
     * @param string $options
     * @param array $longopts
     * @param int $stop getopt parse stop ($optind)
     * @param callable $handler
     * @return bool
     * @see getopt::erropt_msg()
     */
    public static function erropt(string $options, array $longopts, int $stop, callable $handler = ['getopt', 'erropt_msg']): bool
    {
        $idxopt = self::idxopt($options, $longopts);

        $values = $GLOBALS['argv'];
        $skip = 1; // skip utility name (first argument at index 0)
        foreach ($values as $index => $value) {
            if ($skip && $skip--) continue; // skip routine
            if ($index >= $stop) break;  // stop at stop (halt of getopt() option parsing)
            if ('--' === $value) break;  // stop at delimiter
            if ('-' === $value) { // skip stdin type of argument (bogus)
                trigger_error('bogus "-"');
                continue;
            }
            if ('-' !== ($value[0] ?? null)) { // stop at first non-option (bogus)
                trigger_error('bogus ^[^-].*');
                break;
            }
            if ('-' === ($value[1] ?? null)) { // long-option
                $value = null;
                $name = $buffer = substr($value, 2);
                ($start = strpos($buffer, '=', 1))
                && ($name = substr($buffer, 0, $start))
                && $value = substr($buffer, $start + 1);
                if (!isset($idxopt[$name])) {
                    $handler(sprintf('unknown option: %s', $value));
                    return true;
                }
                $skip = (int)(':' === $idxopt[$name] && (null === $value));
            } else { // (short) option(s)
                $buffer = substr($value, 1);
                for ($pos = 0, $len = strlen($buffer); $pos < $len;) {
                    $name = $buffer[$pos];
                    if (!isset($idxopt[$name])) {
                        $handler(sprintf('unknown option: %s', $value));
                        return true;
                    }
                    if ('=' === ($buffer[++$pos] ?? null)) {
                        if ('' === $idxopt[$name]) { // skip dangling "=" in short options
                            $pos++;
                            continue;
                        }
                        break; // =value
                    }
                    if ('' === $idxopt[$name]) continue; // no value
                    $skip = (int)(':' === $idxopt[$name] && $pos === $len);
                    break;
                }
            }
            if ($skip && !isset($values[$index + 1])) {
                $handler(sprintf('no argument given for -%s%s', 1 === strlen($name) ? '' : '-', $name));
                return true;
            }
        }
        return false;
    }

    /**
     * standard error message callback for @see getopt::erropt() handler
     *
     * @param string $message
     */
    public static function erropt_msg(string $message)
    {
        fprintf(STDERR, "%s\n", $message);
    }
}

/**
 * Printable ASCII representation of a string
 *
 * Highlight high-ascii and C0/C1 characters as escape sequences (\xFF)
 * excluding spaces (x20).
 *
 * @param string $buffer
 * @return string
 */
function pascii(string $buffer): string
{
    return preg_replace_callback('/[\x0-\x1F\x7F-\xFF]|\\\\(?=x[0-9A-F]{2})/', static function($match) {
        return sprintf('\x%02X', ord($match[0]));
    }, $buffer);
}

/**
 * Printable ASCII representation of a string (single line)
 *
 * Highlight high-ascii and C0/C1 characters as escape sequences (\xFF)
 * excluding spaces (x20), excluding an ending "\n".
 *
 * @param string $line
 * @return string
 */
function pascii_line(string $line): string {
    if ('' === $line) {
        return $line;
    }
    $suffix = '';
    $buffer = $line;
    if (substr($buffer, -1) === "\n") {
        $buffer = substr($buffer, 0, -1);
        $suffix = "\n";
    }

    return pascii($buffer) . $suffix;
}

/**
 * (incomplete) test for git core.quotePath quoted path
 *
 * @param string $path to test
 * @return bool test result
 */
function is_quote_path(string $path): bool
{
    $len = strlen($path);
    if ($len < 2) return false;

    if ('"' !== $path[0]) return false;

    return '"' === $path[$len - 1];
}

/**
 * unquote a git core.quotePath styled path
 *
 * @param string $path
 * @return string
 */
function un_quote_path(string $path): string
{
    if (!is_quote_path($path)) return $path;

    $buffer = substr($path, 1, -1);

    return stripcslashes($buffer);
}

/**
 * (early) test for range in path (...:<int> greater zero)
 *
 * @param string $path
 * @return bool
 */
function is_range_path(string $path): bool
{
    list(, $range) = split_range_path($path);

    return $range !== null;
}

/**
 * (early) split range containing path into path prefix and range
 *
 * NOTE: range right now is only a line number (numbering starts at 1)
 *
 * @param string $path
 * @return array array(string $path, string $range) or array(string $path, null)
 */
function split_range_path(string $path): array
{
    if (! $result = strrpos($path, ':')) return [$path, null];

    $prefix = substr($path, 0, $result);
    $suffix = substr($path, $result + 1);

    $line = (int) $suffix;
    if ($line < 1 || $suffix !== (string) $line) return [$path, null];

    return [$prefix, $suffix];
}

$opt = [
    'T::nvm', ['files-from::', 'dry-run', 'show-match', 'count-matches', 'print-paths',
    'multiple', 'fnmatch:', 'only:', 'invert', 'file-match:', 'file-match-invert',
    'lines-only:', 'lines-not:']
];
$opts = getopt($opt[0], $opt[1], $optind);
if (getopt::erropt($opt[0], $opt[1], $optind)) {
    show_usage();
    exit(1);
}
$opts['verbose'] = getopt::arg(getopt::args($opts, 'v'), true, false);

$input = getopt::arg(getopt::args($opts, 'T', 'files-from'), '-', '-');
if ($input !== '-' && !is_readable($input)) {
    fprintf(STDERR, "can not read files from '%s'\n", $input);
    exit(1);
}
if ('-' === $input) {
    $input = 'php://stdin';
    $opts['verbose'] && fprintf(STDERR, "info: reading paths from standard input\n");
}

$dryRun = false === ($opts['n'] ?? $opts['dry-run'] ?? true);
$showMatch = false === ($opts['show-match'] ?? true);
$countMatches = false === ($opts['count-matches'] ?? true);
$multiple = getopt::arg(getopt::args($opts, 'm', 'multiple'));
$linesOnly = $opts['lines-only'] ?? null;
$linesNot = $opts['lines-not'] ?? null;

foreach (is_iterable($linesOnly) ? $linesOnly : (array)$linesOnly as $pattern) if (!preg_pattern_valid($pattern)) {
    fprintf(STDERR, 'fatal: invalid --lines-only pattern: `%s`' . "\n", $pattern);
    exit(1);
}

foreach (is_iterable($linesNot) ? $linesNot : (array)$linesNot as $pattern) if (!preg_pattern_valid($pattern)) {
    fprintf(STDERR, 'fatal: invalid --lines-not pattern: `%s`' . "\n", $pattern);
    exit(1);
}

$pattern = $argv[$optind] ?? null;
$replacement = $argv[$optind + 1] ?? null;

if (null !== $pattern && !preg_pattern_valid($pattern)) {
    fprintf(STDERR, 'fatal: invalid pattern: `%s`' . "\n", $pattern);
    exit(1);
}

if (null !== $replacement && !preg_replacement_valid($replacement)) {
    fprintf(STDERR, 'fatal: invalid replacement: `%s`' . "\n", $replacement);
    exit(1);
}

$stats = [
    'count_paths' => 0,
    'count_paths_having_match' => 0,
    'count_paths_not_having_match' => 0,
    'count_paths_having_replacement' => 0,
    'openerror_paths' => [],
    'skipped_paths' => [],
    'filtered_paths' => [],
    'count_matches' => [],
];

$paths = iter::fromFile($input);
$pathsFilter = static function (callable $filter) use ($paths, &$stats) {
    $paths->doFilter(static function (string $path) use ($filter, &$stats) {
        $result = $filter($path);
        $result || $stats['filtered_paths'][] = $path;
        return $result;
    });
};

if (isset($opts['fnmatch'])) {
    $pathsFilter(static function (string $path) use ($opts): bool {
        $fnmatch = fnmatch($opts['fnmatch'], $path);
        if (!$fnmatch && $opts['verbose']) {
            fprintf(STDERR, "filter: --fnmatch %s: %s\n", $opts['fnmatch'], $path);
        }
        return $fnmatch;
    });
}

if (isset($opts['only'])) {
    if (!preg_pattern_valid($opts['only'])) {
        fprintf(STDERR, 'fatal: invalid only pattern: `%s`' . "\n", $opts['only']);
        exit(1);
    }
    $pathsFilter(static function (string $path) use ($opts, &$stats): bool {
        $lines = file($path);
        if ($lines === false) {
            fprintf(STDERR, "i/o error: can not read file '%s'\n", $path);
            return false;
        }
        $matches = preg_grep($opts['only'], $lines);
        $result = (bool)count($matches);
        if (false === ($opts['invert'] ?? true)) {
            $result = !$result;
        }
        if (!$result && $opts['verbose']) {
            fprintf(STDERR, "filter: --only %s /--invert: %s\n", $opts['only'], $path);
        }
        return $result;
    });
}

if (isset($opts['file-match'])) {
    if (!preg_pattern_valid($opts['file-match'])) {
        fprintf(STDERR, 'fatal: invalid file-match pattern: `%s`' . "\n", $opts['file-match']);
        exit(1);
    }
    $pathsFilter(static function (string $path) use ($opts, &$stats): bool {
        $buffer = file_get_contents($path);
        if ($buffer === false) {
            fprintf(STDERR, "i/o error: can not read file '%s'\n", $path);
            return false;
        }
        $result = preg_match($opts['file-match'], $buffer);
        $result = (bool)$result;
        if (false === ($opts['file-match-invert'] ?? true)) {
            $result = !$result;
        }
        if (!$result && $opts['verbose']) {
            fprintf(STDERR, "filter: --file-match %s /-invert: %s\n", $opts['file-match'], $path);
        }
        return $result;
    });
}

if (false === ($opts['print-paths'] ?? true)) {
    $pathCounter = 0;
    $paths->each(static function(string $path) use (&$pathCounter) {
        echo $path, "\n";
        $pathCounter++;
    });
    $opts['verbose'] && fprintf(
        STDERR,
        "info: printed %d path(s), %d filtered\n",
        $pathCounter,
        count($stats['filtered_paths'])
    );
    unset($pathCounter);
    exit(0);
}

foreach ($paths as $path) {
    $stats['count_paths']++;

    // git core.quotePath handling
    if (!file_exists($path) && is_quote_path($path)) {
        $test = un_quote_path($path);
        if (file_exists($test)) $path = $test;
        unset($test);
    }

    $range = null;
    if (!file_exists($path) && is_range_path($path)) {
        list($test, $testRange) = split_range_path($path);
        if (file_exists($test)) {
            $path = $test;
            $range = (int) $testRange;
        }
        unset($test, $testRange);
    }

    // empty pattern, just output the file; output by default
    if ($pattern === null) {
        echo pascii($path), "\n";
        continue;
    }

    $lines = @file($path);
    if (false === $lines) {
        $opts['verbose'] && fprintf(STDERR, "error: %s\n", error_get_last()['message']);
        $stats['openerror_paths'][] = $path;
        $stats['skipped_paths'][] = $path;
        fprintf(STDERR, "skipping path: error opening file '%s'\n", $path);
        continue;
    }

    $lineCount = count($lines);
    if ("\n" !== ($lines[$lineCount - 1][-1] ?? null)) {
        $stats['skipped_paths'][] = $path;
        fprintf(STDERR, "skipping path: no newline at end of file '%s'\n", $path);
        continue;
    }

    // filter lines by --lines-only <pattern>
    if (null !== $linesOnly) {
        $lines = preg_grep_multiple($linesOnly, $lines);
    }

    // filter lines by --lines-not <pattern>
    if (null !== $linesNot) {
        $lines = preg_grep_multiple($linesNot, $lines, PREG_GREP_INVERT);
    }

    $matchLines = $lines;
    if (null !== $range) {
        $matchLines = array_filter($matchLines, static function (int $offset) use ($range) {
            return $offset + 1 === $range;
        }, ARRAY_FILTER_USE_KEY);
    }

    $matchedLines = preg_grep($pattern, $matchLines);
    if (!$matchedLines) {
        $stats['count_paths_not_having_match']++;
        continue;
    }

    $stats['count_paths_having_match']++;
    $count = count($matchedLines);

    if ($countMatches) foreach ($matchedLines as $index => $line) {
        $result = preg_match($pattern, $line, $matches);
        if ($result && strlen($matches[0])) {
            $reservoir = &$stats['count_matches']['matches'][$matches[0]];
            $reservoir[] = [$path, $index + 1];
            $reservoir = &$stats['count_matches']['paths'][$matches[0]][$path];
            $reservoir++;
            unset($reservoir);
        }
    }

    if (null === $replacement) {
        if (!$countMatches) echo $path, "\n";
        if ($showMatch) foreach ($matchedLines as $index => $line) {
            printf(' % 4d: %s', $index + 1, pascii_line($line));
        }
        continue;
    }

    $changedLines = [];

    if ($showMatch) {
        printf("%s: %d line(s) match\n", $path, count($matchedLines));
    }
    foreach ($matchedLines as $index => $line) {
        $buffer = $line;
        $printedFile = false;
        do {
            $result = preg_replace($pattern, $replacement, $buffer);
            $unchanged = $result === $buffer;
            if (!$unchanged && $showMatch) {
                $printedFile || (
                    printf(' % 4d: %s', $index + 1, pascii_line($line))
                    && $printedFile = true
                );
                print_all_matches($index, $buffer, $pattern, $replacement);
            }
            $buffer = $result;
        } while (!$unchanged && $multiple);

        if ($result === $line) {
            continue;
        }
        $changedLines[$index] = $result;
    }

    $changedLines && $stats['count_paths_having_replacement']++;

    if (!$dryRun && $changedLines) {
        $result = array_replace($lines, $changedLines);
        file_put_contents($path, $result);
    }
}

if ($countMatches) {
    if ($stats['count_matches']['matches'] ?? null) {
        $stats['count_matches_count']['matches'] = array_map('count', $stats['count_matches']['matches']);
        arsort($stats['count_matches_count']['matches'], SORT_NUMERIC);
        $stats['count_matches_count']['total'] = array_sum($stats['count_matches_count']['matches']);
        foreach ($stats['count_matches_count']['matches'] as $match => $count) {
            $matchD = $match;
            ($matchD === $matchA = pascii($match)) || $matchD .= "' '$matchA";
            fprintf(STDOUT, "'%s': %d (%.1F%% / files: %.1F%%)\n", $matchD, $count,
                100 * $count / $stats['count_matches_count']['total'],
                100 * count($stats['count_matches']['paths'][$match]) / $stats['count_paths']
            );
        }
    }
    if ($stats['count_paths_not_having_match']) {
        fprintf(STDOUT, "non-skipped files w/o matches: %d (%.1F%%) \n",
            $stats['count_paths_not_having_match'],
            100 * $stats['count_paths_not_having_match'] / $stats['count_paths']
        );
    }
}

if ($skipped = count($stats['skipped_paths'])) {
    $message = $skipped === $stats['count_paths']
        ? 'all files were skipped'
        : sprintf('%d out of %d files were skipped', $skipped, $stats['count_paths']);
    fprintf(STDERR, "skipped paths: %s\n", $message);
}

$filtered_paths_count = count($stats['filtered_paths']);
if ($dryRun) {
    fprintf(STDERR, "dry run: not writing changes to %d of %d files%s, %d filtered (%.1F%%)\n",
        $stats['count_paths_having_replacement'],
        $stats['count_paths'],
        $stats['count_paths'] ? sprintf(' (%.1F%%)', 100 * $stats['count_paths_having_replacement'] / $stats['count_paths']) : '',
        $filtered_paths_count,
        $stats['count_paths'] ? 100 * $filtered_paths_count / $stats['count_paths'] : ($filtered_paths_count ? 100 : 0)
    );
    return;
}

if ($filtered_paths_count) {
    fprintf(
        STDERR,
        "filtered path(s): %d (%.1F%% / 1:%.2F)\n",
        $filtered_paths_count,
        100 * $filtered_paths_count / ($filtered_paths_count + $stats['count_paths']),
        $stats['count_paths'] / $filtered_paths_count
    );
}

if (null === $replacement) {
    fprintf(STDERR, "matches in %d out of %d files%s\n",
        $stats['count_paths_having_match'],
        $stats['count_paths'],
        $stats['count_paths'] ? sprintf(' (%.1F%%)',
            100 * $stats['count_paths_having_match'] / $stats['count_paths']) : ''

    );
} else {
    fprintf(STDERR, "changes in %d of %d files\n",
        $stats['count_paths_having_replacement'],
        $stats['count_paths']);
}
