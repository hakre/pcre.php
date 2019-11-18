# pcre.php

PCRE pattern search and replace through list of files.

CLI wrapper around php preg_grep / preg_replace etc. taking a
list of files from stdin/file to search and replace in multiple
files (line based).

[![Latest Stable Version](https://poser.pugx.org/hakre/pcre.php/v/stable)](https://packagist.org/packages/hakre/pcre.php)
[![Total Downloads](https://poser.pugx.org/hakre/pcre.php/downloads)](https://packagist.org/packages/hakre/pcre.php)
[![Latest Unstable Version](https://poser.pugx.org/hakre/pcre.php/v/unstable)](https://packagist.org/packages/hakre/pcre.php)
[![License](https://poser.pugx.org/hakre/pcre.php/license)](https://packagist.org/packages/hakre/pcre.php)
[![composer.lock](https://poser.pugx.org/hakre/pcre.php/composerlock)](https://packagist.org/packages/hakre/pcre.php)

[Usage](#usage) | [Examples](#examples) | [Installation](#installation)

---

## Usage

~~~
usage: pcre.php [<options>] [<search> [<replace>]]
                [<options>] --print-paths

Common options
    -n, --dry-run         do not write changes to files
    -v                    be verbose
    --show-match          show path, line number and match(es)/replacement(s)
    --count-matches       count distinct matches in descending order
    --print-paths         print paths to stdout, one by line and exit
    --lines-not | --lines-only <pattern>
                          filter lines b/f doing search and replace
    -m, --multiple        search and replace multiple times on the same line

File selection options
    -T, --files-from=<file>
                          read paths of files to operate on from <file>. The
                          special filename "-" or given no filename will read
                          files from standard input.
                          each filename is separated by LF ("\n") in a file.
    --fnmatch <pattern>   filter the list of path(s) by fnmatch() pattern
    --only <pattern>      only operate on files having a line matching pcre
                          pattern
    --invert              invert the meaning of --only pcre pattern match,
                          operate on files that do not have a line matching
                          the pcre pattern
    --file-match <pattern>
                          only operate on files their contents (not lines)
                          matches the pcre pattern
    --file-match-invert   invert the meaning of --file-match

~~~
---

## Examples

Most of these examples require to have the git utility installed.

Print a list of PHP file paths:

~~~
$ git ls-files '*.php' | pcre.php
pcre.php
matches in 0 out of 1 files (0.0%)
~~~

Search a list of files:

~~~
$ git ls-files '*.php' | pcre.php '/getopt/'
pcre.php
matches in 1 out of 1 files (100.0%)
~~~

And actually show each match:

~~~
$ git ls-files '*.php' | pcre.php --show-match '/getopt/'
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
$ git ls-files '*.php' | pcre.php -n '/getopt/' 'replace_getopt'
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

---

## Installation

Have a PHP 7.1+ PHP binary as `/usr/bin/env` on the system.

Make `pcre.php` executable (git has you covered on checkout) and
have it within your path.

For example, after cloning and considering `~/bin` is a directory
within your `$PATH`:

~~~
$ cp -a pcre.php ~/bin
~~~

or alternatively create a symbolic link (symlink) for using the
source version:

~~~
$ ln -sT "$(realpath ./pcre.php)" ~/bin/pcre.php
~~~

Then invoking:

~~~
$ pcre.php
~~~

should just show the cursor blinking. Signal `eof` (ctrl+d /
Unicode END_OF_TRANSMISSION) to get a result:

~~~
matches in 0 out of 0 files
~~~

Congratulations, you managed to search no files for nothing!

This confirms installation works.
