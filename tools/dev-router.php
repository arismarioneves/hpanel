<?php

declare(strict_types=1);

// Roteador para `php -S` em desenvolvimento: emula o .htaccess.
// Uso: php -S 127.0.0.1:8099 tools/dev-router.php

$root = dirname(__DIR__);
$uri = (string) $_SERVER['REQUEST_URI'];
$path = rawurldecode((string) parse_url($uri, PHP_URL_PATH));
$query = (string) parse_url($uri, PHP_URL_QUERY);

$blocked = static fn(string $p): bool => !str_starts_with($p, '/.well-known/')
    && preg_match('#(^|/)\.|^/(storage|lib|partials|tests|tools|vendor|docs|extension)(/|$)|^/(config(\.exemplo)?\.php|composer\.(json|lock)|package\.json|phpunit\.xml)$#', $p) === 1;

if ($blocked($path)) {
    http_response_code(403);
    exit('Forbidden');
}
// URL com .php: 301 para a forma sem extensão (index.php vira a pasta), só em GET/HEAD.
if (str_ends_with($path, '.php') && in_array($_SERVER['REQUEST_METHOD'], ['GET', 'HEAD'], true)) {
    $clean = preg_replace('#(^|/)index\.php$#', '$1', $path) ?? $path;
    $clean = $clean === $path ? substr($path, 0, -4) : $clean;
    header('Location: ' . $clean . ($query !== '' ? '?' . $query : ''), true, 301);
    exit;
}
if ($path === '/') {
    require $root . '/index.php';
    return true;
}
// Assets versionados (assets/v/<versão>/…): mesmo arquivo de assets/…, como no .htaccess.
if (preg_match('#^/assets/v/[0-9a-f]+/(.+)$#', $path, $m) === 1) {
    $assets = realpath($root . '/assets') . DIRECTORY_SEPARATOR;
    $file = realpath($assets . $m[1]);
    if ($file === false || !str_starts_with($file, $assets) || !is_file($file)) {
        http_response_code(404);
        exit('Not found');
    }
    $types = [
        'js' => 'text/javascript; charset=utf-8', 'css' => 'text/css; charset=utf-8', 'svg' => 'image/svg+xml',
        'woff2' => 'font/woff2', 'webp' => 'image/webp', 'png' => 'image/png', 'txt' => 'text/plain; charset=utf-8',
    ];
    header('Content-Type: ' . ($types[strtolower(pathinfo($file, PATHINFO_EXTENSION))] ?? 'application/octet-stream'));
    readfile($file);
    return true;
}
if (is_file($root . $path)) {
    return false;
}
$script = rtrim($path, '/') . '.php';
if (is_file($root . $script)) {
    if ($blocked($script)) {
        http_response_code(403);
        exit('Forbidden');
    }
    require $root . $script;
    return true;
}
http_response_code(404);
exit('Not found');
