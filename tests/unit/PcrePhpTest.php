<?php declare(strict_types=1);

/*
 * this file is part of pcre.php
 */


class PcrePhpTest extends PHPUnit\Framework\TestCase
{
    public function testPascii()
    {
        $this->assertSame('\x00', pascii("\0"));
    }
}
