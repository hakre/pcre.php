# pcre.php

pcre pattern search through files (w/ replace)

cli wrapper around php preg_grep / preg_replace etc. taking a
list of files from stdin/file.

(copied from source)

---

## Installation

Have a PHP 7-ish (sorry forget the correct requirements) PHP
binary as PHP on home sys.

Make `pcre.php` executable (git has you covered) and have it
within your path.

For example, after cloning and considering `~/bin` is a directory
within your `$PATH`:

~~~
$ cp -a pcre.php ~/bin
~~~

The invoking:

~~~
$ pcre.php
~~~

should just show the cursor blinking. Signal `eof` (e.g. ctrl+d,
a.k.a. END_OF_TRANSMISSION in Unicode) to get a result:

~~~
matches in 0 out of 0 files
~~~

This confirms installation works.

## Examples

Most of these examples require to have the git utility installed.

Print a list of PHP files paths:

~~~
$ git ls-files *.php | pcre.php
pcre.php
matches in 0 out of 1 files (0.0%)
~~~

Search a list of files:

~~~
$ git ls-files *.php | pcre.php '/getopt/'
pcre.php
matches in 1 out of 1 files (100.0%)
~~~

And actually show each match:

~~~
$ git ls-files *.php | pcre.php --show-match '/getopt/'
  pcre.php
    352:  * Class getopt
    354:  * static helper class for parsing command-line arguments w/ php getopt()
    356:  * $opts    - array in the form of getopt() return
    359: class getopt
    364:      * @param array $opts getopt result
    386:      * (hint: maybe using `getopt::arg(...) ?? $default` is more applicable)
    416:      * index getopt() options and longoptions
    453:      * @param int $optind getopt parse stop
    456:      * @see getopt::erropt_msg()
    458:     public static function erropt(string $options, array $longopts, int $optind, $handler = ['getopt', 'erropt_msg']): bool
    460:         $idxopt = getopt::idxopt($options, $longopts);
    466:             if ($index >= $optind) break;  // stop at stop (halt of getopt() option parsing)
    516:      * standard error message callback for @see getopt::erropt() handler
    570: $opts = getopt($opt[0], $opt[1], $optind);
    571: if (getopt::erropt($opt[0], $opt[1], $optind)) {
    575: $opts['verbose'] = getopt::arg(getopt::args($opts, 'v'), true, false);
    577: $input = getopt::arg(getopt::args($opts, 'T', 'files-from'), '-', '-');
    590: $multiple = getopt::arg(getopt::args($opts, 'm', 'multiple'));
  matches in 1 out of 1 files (100.0%)
~~~

Replace matches:

~~~
$ git ls-files *.php | pcre.php '/getopt/' 'replace_getopt'
...
~~~

To revert checkout/reset with git. Alternatively use dry-run
first and preview changes with show each match (which is extended
with the replace):

~~~
$ git ls-files *.php | pcre.php --dry-run --show-match '/getopt/' 'replace_getopt'
...
~~~

### More Examples

~~~
$ pcre.php --help
~~~

show usage information
