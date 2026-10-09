<?php
declare(strict_types=1);

namespace App\Services;

/** Exportação CSV compatível com Excel pt-BR (UTF-8 com BOM, separador ;). */
final class Csv
{
    public static function download(string $arquivo, array $cabecalho, iterable $linhas): never
    {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9_\-.]/', '_', $arquivo) . '"');
        header('Cache-Control: no-store');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, $cabecalho, ';', '"', '');
        foreach ($linhas as $l) {
            fputcsv($out, array_map([self::class, 'seguro'], $l), ';', '"', '');
        }
        fclose($out);
        exit;
    }

    /** Neutraliza injeção de fórmulas (=, +, -, @) ao abrir no Excel. */
    private static function seguro(mixed $v): string
    {
        $s = (string) ($v ?? '');
        return ($s !== '' && in_array($s[0], ['=', '+', '-', '@', "\t", "\r"], true)) ? "'" . $s : $s;
    }
}
