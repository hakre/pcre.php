<?php declare(strict_types=1);

/*
 * this file is part of pcre.php
 */

use PHPUnit\Framework\TestCase;

/**
 * Class SourceMarkerTest
 */
class SourceMarkerTest extends TestCase
{
    public function provideSourceMarkers(): array
    {
        return [
            ['', '', []],
            ['', 'Id', []],
            ['$Id $', 'Id', []],
            ['$Idx$', 'Id', []],
            ['$Version$', 'Version', ['$Version$', null]],
            ['$Version:  $', 'Version', ['$Version:  $', '']],
            ['$Version: v0.0.6 $', 'Version', ['$Version: v0.0.6 $', 'v0.0.6']],
            ['$Id: 0a58012b89e209afc72f2b7d014dcb09241f065a $', 'Version', []],
            ['$Id: 0a58012b89e209afc72f2b7d014dcb09241f065a $', 'Id', ['$Id: 0a58012b89e209afc72f2b7d014dcb09241f065a $', '0a58012b89e209afc72f2b7d014dcb09241f065a']],
        ];
    }

    /**
     * @dataProvider provideSourceMarkers
     * @covers ::source_marker_read()
     * @param string $buffer
     * @param string $name
     * @param array $expected
     */
    public function testSourceMarkerRead(string $buffer, string $name, array $expected): void
    {
        $actual = source_marker_read($buffer, $name);
        $this->assertSame($expected, $actual);
    }

    public function provideSourceMarkersForWrite(): array
    {
        return [
            ['$Version$', 'Version', null, '$Version$'],
            ['$Version$', 'Road', null, '$Version$'],
            ['$Version: v1.0.0 $', 'Version', null, '$Version$'],
            ['In the world of foo $Version: v1.0.0 $ there is baz', 'Version', null, 'In the world of foo $Version$ there is baz'],
            ['$Version$', 'Version', 'v1.0.0', '$Version: v1.0.0 $'],
        ];
    }

    /**
     * @dataProvider provideSourceMarkersForWrite
     * @covers ::source_marker_write()
     * @param string $buffer
     * @param string $name
     * @param string|null $value
     * @param string $expected
     */
    public function testSourceMarkerWrite(string $buffer, string $name, ?string $value, string $expected): void
    {
        $actual = source_marker_write($buffer, $name, $value);
        $this->assertSame($expected, $actual);
    }

    /**
     * @covers ::file_version()
     */
    public function testFileVersion(): void
    {
        # standard version tag + modifications incl. dirty
        $this->assertRegExp('(^v\d+(\.\d+)+(-\d+-g[0-9a-f]+(-dirty)?)?$)', file_version());
    }

    /**
     * @covers ::source_version()
     */
    public function testSourceVersion(): void
    {
        $this->assertNotSame('v1.0.0', source_version([' * $Ver: v1.0.0 $ $Id$ * ']));
        $this->assertSame('v1.0.0', source_version([' * $Ver: v1.0.0 $ $Id: x $ * ']));
        $this->assertNotSame('v1.0.0', source_version([' * $Ver$ $Id: x $ * ']));
    }
}
