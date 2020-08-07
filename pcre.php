#!/usr/bin/env php
<?php declare(strict_types=1);
/*
 * pcre pattern search through files (w/ replace)
 *
 * $Ver: v0.1.0 $ $Id$
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
 *                           files from standard input
 *                           each filename is separated by LF ("\n") in a file
 *     --fnmatch <pattern>   filter the list of path(s) by fnmatch() pattern
 *     --fnpcre <pattern>    filter the list of path(s) by pcre pattern
 *     --only <pattern>      only operate on files having a line matching pcre
 *                           pattern
 *     --invert              invert the meaning of --only pcre pattern match,
 *                           operate on files that do not have a line matching
 *                           the pcre pattern
 *     --file-match <pattern>
 *                           only operate on files their contents (not lines)
 *                           matches the pcre pattern
 *     --file-match-invert   invert the meaning of --file-match
 *
 * Operational options
 *     -C <path>             run as if pcre.php was started in <path> instead
 *                           of the current working directory
 *     --version             display version information and exit
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

function source_marker_read(string $buffer, string $name): array
{
    $pattern = sprintf('(\$%s(?:: ([^ $]*) )?\$)', preg_quote($name, '()'));
    $result = preg_match($pattern, $buffer, $matches);
    assert(false !== $result);
    if (!$result) {
        return [];
    }

    return [$matches[0], $matches[1] ?? null];
}

function source_marker_write(string $buffer, string $name, string $value = null): string
{
    $result = source_marker_read($buffer, $name);
    if ($result === [] || $value === $result[1]) {
        return $buffer;
    }

    return substr_replace(
        $buffer,
        sprintf('$%s%s$', $name, null === $value ? '' : ": $value "),
        strpos($buffer, $result[0]),
        strlen($result[0])
    );
}

function source_markers_read(array $markers, array $lines, int $cap = null)
{
    assert(0 < count($lines));

    $cap = $cap ?? count($lines);
    assert(0 <= $cap);

    foreach ($lines as $index => $line) {
        if ($index <= $cap) foreach ($markers as $name => $value) {
            $value || $markers[$name] = source_marker_read($line, $name);
        }
    }

    return $markers;
}

function source_git_version(): string
{
    return exec('git describe --long --tags --dirty --always --match \'v[0-9]\.[0-9]*\' 2>/dev/null');
}

function source_version(array $lines, int $cap = null): string
{
    assert(0 < count($lines));

    $cap = $cap ?? count($lines);
    assert(0 <= $cap);

    $markers = ['Ver' => null, 'Id' => null];
    $markers = source_markers_read($markers, $lines, $cap);
    assert($markers['Ver'] !== null);
    assert($markers['Id'] !== null);
    $id = &$markers['Id'];
    $version = &$markers['Ver'];

    // source version, always read fresh
    if ($id && $id[1] === null) {
        return source_git_version();
    }

    // packaged version, read version
    if ($version && $version[1] !== null) {
        return $version[1];
    }

    // fall-back to source version as there is no version in file
    return source_git_version();
}

function file_version(): string
{
    $lines = file(__FILE__);
    $cap = 12;

    return source_version($lines, $cap);
}

/**
 * mangle a system path into one php understands
 * @param string $path
 * @return string
 */
function php_fs_path(string $path): string
{
    /* @link https://bugs.php.net/bug.php?id=53465 */
    $result = preg_replace('(^/(?:proc/self|dev)/(fd/\d+))', 'php://\1', $path);
    if (null === $result) {
        throw new UnexpectedValueException('internal: failed to handle path by pattern/preg_replace');
    }
    return $result;
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
        $path = php_fs_path($path);

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
        yield from $this->iter;
    }

    /**
     * start iteration with first element which is accepted by
     * the callback
     *
     * @param iterable $it
     * @param callable $cb
     * @return Generator
     */
    public static function first(iterable $it, callable $cb): ?\Generator
    {
        $iit = new self($it);
        $nri = new NoRewindIterator(new IteratorIterator($iit));
        foreach ($nri as $key => $value) {
            $result = $cb($value);
            if (!$result) {
                continue;
            }
            yield $key => $value;
            $nri->next();
            yield from $nri;
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
     * combine all arguments into a list
     *
     * @param array $args all option arguments, key is option name, values
     *        are false for option w/o argument (switch, ignored), string for
     *        option argument
     * @param Closure $filter (optional) filter closure that maps each entry,
     *        non-string returns will remove the element from the result
     * @return array
     */
    public static function arglst(array $args, $filter = null)
    {
        $result = [];
        foreach ($args as $arg) {
            if (!is_string($arg)) { // really, only string arguments
                continue;
            }

            if ($filter instanceof Closure) {
                $arg = $filter($arg);
                if (!is_string($arg)) {
                    continue;
                }
            }

            $result[] = $arg;
        }

        return $result;
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
    public static function erropt(string $options, array $longopts, int $stop, callable $handler = null): bool
    {
        (null === $handler) && $handler = ['getopt', 'erropt_msg'];

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
                $argValue = null;
                $name = $buffer = substr($value, 2);
                ($start = strpos($buffer, '=', 1))
                && ($name = substr($buffer, 0, $start))
                && $argValue = substr($buffer, $start + 1);
                if (!isset($idxopt[$name])) {
                    call_user_func($handler, sprintf('unknown option: %s', $value));
                    return true;
                }
                $skip = (int)(':' === $idxopt[$name] && (null === $argValue));
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
 * some paths are just not valid. for example those that
 * contain a NUL-byte.
 *
 * the rest depends on configuration, see PATHCHK(1), which
 * is excluded here.
 *
 * @param string $path
 * @return bool
 */
function is_valid_path(string $path): bool
{
    return false === strpos($path, "\0");
}

/**
 * (incomplete) test for git core.quotePath quoted path
 *
 * @param string $path to test
 * @return bool test result
 */
function is_quote_path(string $path): bool
{
    if (!is_valid_path($path)) return false;

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
 * git core.quotePath handling (fuzzy guess work)
 */
function fuzzy_quote_path_normalizer(string $path) {
    if (is_valid_path($path) && !file_exists($path) && is_quote_path($path)) {
        $test = un_quote_path($path);
        if (file_exists($test)) return $test;
    }
    return $path;
}

/**
 * read from string until ending from offset
 *
 * returns the string read. forward offset after ending, if ending is at
 * the end of string or no ending is found from offset, offset is unchanged
 * and returned string is null.
 *
 * @param string $string
 * @param int $offset
 * @param string $ending
 * @return string
 */
function string_get_line(string $string, int &$offset, string $ending = "\n"): ?string
{
    $pos = strpos($string, $ending, $offset);
    if (false === $pos) {
        return null;
    }
    $buffer = substr($string, $offset, $pos - $offset);

    $offset = $pos + strlen($ending);
    return $buffer;
}

/**
 * read paths from a file in auto-detect mode
 *
 * path represents a file containing a list of paths. that list is either
 * newline separated (common, special characters in each path then might be
 * quoted which is handled elsewhere, compare git core.quotePath) or NUL
 * byte separated.
 *
 * strategy here is to first look for NUL bytes because if they exist, this
 * is a considered a clear bet. if not, fall-back to newline as separator
 *
 * @see Iter::file()
 * @param string $path
 * @return Generator
 */
function fuzzy_paths_from_file(string $path): Generator
{
    $path = php_fs_path($path);

    $fp = fopen($path, 'rb');
    if (false === $fp) return;

    if (feof($fp)) {
        fclose($fp);
        return;
    }

    $buffer = fread($fp, 4096);
    if ('' === $buffer && feof($fp)) {
        fclose($fp);
        return;
    }

    $ending = "\0";
    $pos = strpos($buffer, $ending);
    if (false === $pos) { // fall back to newline
        $ending = "\n";
    }
    $offset = 0;
    while (null !== $line = string_get_line($buffer, $offset, $ending)) {
        yield $line;
    }
    $buffer = substr($buffer, $offset);
    if (feof($fp)) {
        yield $buffer;
    }

    while (!feof($fp) && false !== $line = stream_get_line($fp, 4096, $ending)) {
        if (isset($buffer)) {
            yield $buffer . $line;
            unset($buffer);
            continue;
        }
        yield $line;
    }
    fclose($fp);
}

/**
 * wrapper for @see file()
 *
 * @param string $path
 * @return array|bool|false
 */
function pcrephp_file(string $path) {
    if (!is_valid_path($path)) {
        trigger_error('invalid path');
        return false;
    }
    return file($path);
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

/* include mode */
if (count(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1))) {
    return;
}

$opt = [
    'C:T::nvm', ['files-from::', 'dry-run', 'show-match', 'count-matches', 'print-paths',
        'multiple', 'fnmatch:', 'fnpcre:', 'only:', 'invert', 'file-match:', 'file-match-invert',
        'lines-only:', 'lines-not:', 'version']
];
$opts = getopt($opt[0], $opt[1], $optind);
if (getopt::erropt($opt[0], $opt[1], $optind)) {
    show_usage();
    exit(1);
}
$opts['verbose'] = getopt::arg(getopt::args($opts, 'v'), true, false);
if (getopt::arg(getopt::args($opts, 'version'), true, false)) {

    fprintf(STDOUT, "pcre.php %s\n", file_version());
    if ($opts['verbose']) {
        fprintf(STDOUT, "    pcre version: %s\n", PCRE_VERSION);
        fprintf(STDOUT, "    pcre ext: %s\n", phpversion('pcre'));
    }
    exit(0);
}

getopt::arglst(getopt::args($opts, 'C'), static function ($arg) use ($opts) {
    $result = @chdir($arg);
    if (!$result) {
        vfprintf(STDERR, "fatal: can not change to '%s': %s\n", [
            $arg, preg_replace('(^chdir\(\): | \(errno \d+\)$)', '', error_get_last()['message'])
        ]);
        exit(1);
    }
});

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
$multiple = getopt::arg(getopt::args($opts, 'm', 'multiple'), true, false);
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

$paths = new Iter(fuzzy_paths_from_file($input));
$pathsFilter = static function (callable $filter) use ($paths, &$stats) {
    $paths->doFilter(static function (string $path) use ($filter, &$stats) {
        $result = $filter($path);
        $result || $stats['filtered_paths'][] = $path;
        return $result;
    });
};
$pathsMapping = static function (callable $mapping) use ($paths) {
    $paths->doMap(static function (string $path) use ($mapping) {
        return $mapping($path);
    });
};

// git core.quotePath handling
$pathsMapping('fuzzy_quote_path_normalizer');

$addPathsFilter = static function (string $name, callable $filter, callable $validator = null) use ($opts, $pathsFilter) {
    if (!isset($opts[$name])) {
        return;
    }
    $pattern = (string)$opts[$name];
    if (null !== $validator && !$validator($pattern)) {
        fprintf(STDERR, 'fatal: invalid --%s pattern: `%s`' . "\n", $name, $pattern);
        exit(1);
    }

    $verbose = $opts['verbose'];
    $pathsFilter(static function (string $path) use ($name, $pattern, $filter, $verbose): bool {
        $result = (bool)$filter($pattern, $path);
        if (!$result && $verbose) {
            fprintf(STDERR, "filter: --%s %s: %s\n", $name, $pattern, $path);
        }
        return $result;
    });
};

$addPathsFilter('fnmatch', 'fnmatch');
$addPathsFilter('fnpcre', 'preg_match','preg_pattern_valid');

if (isset($opts['only'])) {
    if (!preg_pattern_valid($opts['only'])) {
        fprintf(STDERR, 'fatal: invalid --only pattern: `%s`' . "\n", $opts['only']);
        exit(1);
    }
    $pathsFilter(static function (string $path) use ($opts, &$stats): bool {
        $lines = @pcrephp_file($path);
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
        if (!is_file($path) && !is_readable($path)) {
            fprintf(STDERR, "i/o error: not a readable file '%s'\n", $path);
            return false;
        }
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
        echo pascii($path), "\n";
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

    $range = null;
    if (is_valid_path($path) && !file_exists($path) && is_range_path($path)) {
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

    $lines = @pcrephp_file($path);
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
        if ($multiple) {
            $result = preg_match_all($pattern, $line, $matches);
        } else {
            $result = preg_match($pattern, $line, $matches);
            $matches = [$matches];
        }
        if ($result && count($matches[0])) foreach ($matches[0] as $match) {
            $reservoir = &$stats['count_matches']['matches']["_$match"];
            $reservoir[] = [$path, $index + 1];
            unset($reservoir);
            $pathCounter = &$stats['count_matches']['paths']["_$match"]["_$path"];
            $pathCounter++;
            unset($pathCounter);
        }
    }

    if (null === $replacement) {
        if (!$countMatches) echo pascii($path), "\n";
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
        $listCount = 0;
        foreach ($stats['count_matches_count']['matches'] as $matchKey => $count) {
            $match = substr($matchKey, 1);
            $matchD = $match;
            ($matchD === $matchA = pascii($match)) || $matchD .= "' '$matchA";
            fprintf(STDOUT, "%d. '%s': %d (%.1F%% / files: %.1F%%)\n", ++$listCount, $matchD, $count,
                100 * $count / $stats['count_matches_count']['total'],
                100 * count($stats['count_matches']['paths'][$matchKey]) / $stats['count_paths']
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
