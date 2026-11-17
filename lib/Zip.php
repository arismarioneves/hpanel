<?php

declare(strict_types=1);

namespace HPanel;

/** Arquivo ZIP sem compressão (método store), montado em memória: não depende da extensão zip do PHP. */
final class Zip
{
    /**
     * Conteúdo dos arquivos de uma pasta, recursivo, com caminhos "/" relativos a ela e o prefixo informado.
     * Arquivos e pastas ocultos (".algo") ficam de fora.
     *
     * @return array<string, string>
     */
    public static function directory(string $dir, string $prefix = ''): array
    {
        $root = rtrim(str_replace('\\', '/', $dir), '/') . '/';
        $files = [];
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            $relative = substr(str_replace('\\', '/', $item->getPathname()), strlen($root));
            if (!$item->isFile() || preg_match('#(^|/)\.#', $relative) === 1) {
                continue;
            }
            $files[$prefix . $relative] = (string) file_get_contents($item->getPathname());
        }
        ksort($files);
        return $files;
    }

    /** @param array<string, string> $files caminho dentro do zip => conteúdo */
    public static function build(array $files, int $mtime): string
    {
        $date = getdate($mtime);
        $dosTime = ($date['hours'] << 11) | ($date['minutes'] << 5) | intdiv($date['seconds'], 2);
        $dosDate = (max(0, $date['year'] - 1980) << 9) | ($date['mon'] << 5) | $date['mday'];
        $utf8Names = 0x0800;

        $body = '';
        $central = '';
        foreach ($files as $name => $data) {
            $name = (string) $name;
            $crc = crc32($data);
            $size = strlen($data);
            $offset = strlen($body);
            $body .= "PK\x03\x04" . pack('vvvvvVVVvv', 20, $utf8Names, 0, $dosTime, $dosDate, $crc, $size, $size, strlen($name), 0) . $name . $data;
            $central .= "PK\x01\x02" . pack('vvvvvvVVVvvvvvVV', 20, 20, $utf8Names, 0, $dosTime, $dosDate, $crc, $size, $size, strlen($name), 0, 0, 0, 0, 0, $offset) . $name;
        }
        $count = count($files);
        return $body . $central . "PK\x05\x06" . pack('vvvvVVv', 0, 0, $count, $count, strlen($central), strlen($body), 0);
    }
}
