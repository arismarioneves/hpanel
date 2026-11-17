<?php

declare(strict_types=1);

require __DIR__ . '/lib/autoload.php';

use HPanel\{Http, Zip};

// Entrega a pasta extension/ como .zip para instalar no navegador ("Carregar sem compactação").
Http::securityHeaders();

$dir = __DIR__ . '/extension';
$manifest = json_decode((string) file_get_contents($dir . '/manifest.json'), true);
$version = preg_replace('/[^0-9.]/', '', (string) ($manifest['version'] ?? '')) ?: '0';
$files = Zip::directory($dir, 'hpanel-conector/');
$mtime = max(array_map(static fn(string $path): int => (int) filemtime($dir . '/' . substr($path, strlen('hpanel-conector/'))), array_keys($files)));

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="hpanel-conector-' . $version . '.zip"');
header('Cache-Control: no-store');
echo Zip::build($files, $mtime);
