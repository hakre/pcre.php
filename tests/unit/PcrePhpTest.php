<?php declare(strict_types=1);

/*
 * this file is part of pcre.php
 */

use PHPUnit\Framework\TestCase;

/**
 * Class PcrePhpTest
 *
 * Basic Test-Suite for not yet grouped things. It basically demonstrates
 * that it works w/ the bootstrapping.
 */
class PcrePhpTest extends TestCase
{
    public function testPascii()
    {
        $this->assertSame('\x00', pascii("\0"));
    }
}
