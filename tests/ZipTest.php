<?php

declare(strict_types=1);

namespace HPanel\Tests;

use HPanel\Tests\Support\TempDir;
use HPanel\Zip;
use PHPUnit\Framework\TestCase;

final class ZipTest extends TestCase
{
    /** Lê o zip pelo diretório central, como um descompactador faria. @return array<string, string> */
    private static function unzip(string $zip): array
    {
        $eocd = strrpos($zip, "PK\x05\x06");
        self::assertNotFalse($eocd, 'fim do diretório central');
        $end = unpack('vdisk/vstart/vcount/vtotal/Vsize/Voffset', substr($zip, $eocd + 4, 16));
        $files = [];
        $pos = $end['offset'];
        for ($i = 0; $i < $end['total']; $i++) {
            self::assertSame("PK\x01\x02", substr($zip, $pos, 4));
            $c = unpack('vmade/vneed/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vname/vextra/vcomment/vdisk/vint/Vext/Vlocal', substr($zip, $pos + 4, 42));
            $name = substr($zip, $pos + 46, $c['name']);
            self::assertSame("PK\x03\x04", substr($zip, $c['local'], 4));
            $l = unpack('vneed/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vname/vextra', substr($zip, $c['local'] + 4, 26));
            $data = substr($zip, $c['local'] + 30 + $l['name'] + $l['extra'], $c['csize']);
            self::assertSame(0, $c['method'], 'sem compressão');
            self::assertSame($c['crc'], crc32($data), 'CRC de ' . $name);
            $files[$name] = $data;
            $pos += 46 + $c['name'] + $c['extra'] + $c['comment'];
        }
        return $files;
    }

    public function testBuildRoundTripsFilesAndUtf8Names(): void
    {
        $files = ['a/manifest.json' => '{"v":1}', 'a/vazio.txt' => '', 'a/ação.js' => str_repeat("x\n", 500)];
        self::assertSame($files, self::unzip(Zip::build($files, mktime(12, 30, 10, 11, 17, 2026))));
    }

    public function testEmptyArchiveIsValid(): void
    {
        self::assertSame([], self::unzip(Zip::build([], time())));
    }

    public function testDirectoryIsRecursiveWithPrefixAndSkipsHiddenFiles(): void
    {
        $dir = TempDir::make('hpanel-zip', 'sub', '.git');
        file_put_contents($dir . '/manifest.json', 'm');
        file_put_contents($dir . '/sub/b.js', 'b');
        file_put_contents($dir . '/.DS_Store', 'x');
        file_put_contents($dir . '/.git/config', 'x');
        try {
            self::assertSame(['ext/manifest.json' => 'm', 'ext/sub/b.js' => 'b'], Zip::directory($dir, 'ext/'));
        } finally {
            array_map('unlink', [$dir . '/manifest.json', $dir . '/sub/b.js', $dir . '/.DS_Store', $dir . '/.git/config']);
            array_map('rmdir', [$dir . '/sub', $dir . '/.git', $dir]);
        }
    }

    public function testExtensionFolderShipsItsManifest(): void
    {
        $files = Zip::directory(dirname(__DIR__) . '/extension', 'hpanel-conector/');
        $manifest = json_decode($files['hpanel-conector/manifest.json'] ?? '', true);
        self::assertSame(3, $manifest['manifest_version'] ?? null);
        foreach (['sw.js', 'content.js', 'popup.html', 'popup.js', 'hostinger.js'] as $file) {
            self::assertArrayHasKey('hpanel-conector/' . $file, $files);
        }
    }
}
