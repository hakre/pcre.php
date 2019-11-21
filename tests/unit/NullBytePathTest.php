<?php declare(strict_types=1);

/*
 * this file is part of pcre.php
 */

use PHPUnit\Framework\TestCase;

/**
 * Class NullBytePathTest
 *
 * Some tests related to NUL \0 byte handling in paths
 */
class NullBytePathTest extends TestCase
{
    public function testNullByteSafeness()
    {
        /** @noinspection PhpUnitTestsInspection *//* using file_exists here by intention */
        $this->assertFalse(file_exists(''));

        $this->expectException(TypeError::class);
        file_exists("\0");
    }

    public function testQuotePathNormalizerNullByteSafeness()
    {
        $this->assertSame("\0", fuzzy_quote_path_normalizer("\0"));
    }

    /**
     * test how it is possible to add an error to error_get_last()
     */
    public function testAddUserError()
    {
        @trigger_error('test error', E_USER_WARNING);
        $actual = error_get_last()['message'];
        $this->assertSame('test error', $actual);
    }
}
