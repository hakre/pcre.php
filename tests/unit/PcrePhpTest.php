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

    /**
     * @covers ::string_get_line
     */
    public function testStringGetLine()
    {
        $buffer = "this\nis\nnot";
        $offset = 0;
        $actual = string_get_line($buffer, $offset);
        $this->assertSame('this', $actual);
        $this->assertSame(5, $offset);

        $next = string_get_line($buffer, $offset);
        $this->assertSame('is', $next);
        $this->assertSame(8, $offset);

        $terminator = string_get_line($buffer, $offset);
        $this->assertNull($terminator);
        $this->assertSame(8, $offset);

        $this->assertSame('not', substr($buffer, $offset));
    }

    /**
     * @covers ::string_get_line
     */
    public function stringGetLineAtEnd()
    {
        $buffer = "this\n";
        $offset = 0;
        $actual = string_get_line($buffer, $offset);
        $this->assertSame('this', $actual);
        $this->assertSame(5, $offset);

        $terminator = string_get_line($buffer, $offset);
        $this->assertNull($terminator);
        $this->assertSame(5, $offset);

        // was false before PHP 7.0
        $this->assertSame('', substr($buffer, $offset));
    }
}
