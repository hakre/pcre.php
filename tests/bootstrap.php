<?php declare(strict_types=1);

/*
 * this file is part of pcre.php
 */

/**
 * include a file w/o the first line (shebang)
 *
 * magic constants __FILE__ and __DIR__ won't work properly
 * for the included parts as it works w/ the help of a temporary
 * file.
 *
 * @param string $path
 */
$shebangInclude = static function(string $path) {
    $lines = file($path);
    unset($lines[0]); # remove shebang line that lies therein
    $handle = tmpfile();
    $uri = stream_get_meta_data($handle)['uri'];
    file_put_contents($uri, $lines);
    /** @noinspection PhpIncludeInspection */
    include($uri);
};

$shebangInclude(__DIR__ . '/../pcre.php');

unset($shebangInclude);
