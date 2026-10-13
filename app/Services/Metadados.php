<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Remove metadados de fotos (LGPD: minimização). Fotos de celular trazem EXIF com GPS,
 * modelo do aparelho, data e às vezes nome do dono. Só a orientação (rotação) é preservada.
 * Sem dependências (não usa GD/Imagick): reescreve os blocos do arquivo sem recodificar a imagem.
 */
final class Metadados
{
    /**
     * Limpa o arquivo no lugar. Retorna true se algo foi removido.
     * HEIC e RAW (DNG) não são reescritos: o bloco de GPS é esvaziado no próprio arquivo (mesmo tamanho).
     * $manterOrientacao=false quando a imagem já foi girada (ex.: convertida de HEIC).
     */
    public static function limpar(string $arquivo, string $mime, bool $manterOrientacao = true): bool
    {
        $dados = @file_get_contents($arquivo);
        if ($dados === false) {
            return false;
        }
        $novo = match ($mime) {
            'image/jpeg'        => self::jpeg($dados, $manterOrientacao),
            'image/png'         => self::png($dados),
            'image/webp'        => self::webp($dados),
            'image/heic'        => self::heic($dados),
            'image/x-adobe-dng' => self::dng($dados),
            default             => null,
        };
        if ($novo === null || $novo === $dados) {
            return false;
        }
        return file_put_contents($arquivo, $novo, LOCK_EX) !== false;
    }

    /** JPEG: descarta APP1 (EXIF/XMP), APP12, APP13 (IPTC) e comentários; recria EXIF só com a orientação. */
    public static function jpeg(string $d, bool $manterOrientacao = true): ?string
    {
        if (strncmp($d, "\xFF\xD8", 2) !== 0) {
            return null;
        }
        $out = "\xFF\xD8";
        $orientacao = null;
        $i = 2;
        $n = strlen($d);
        while ($i + 4 <= $n) {
            if ($d[$i] !== "\xFF") {
                return null; // estrutura inesperada: não mexe
            }
            $m = ord($d[$i + 1]);
            if ($m === 0xD9 || $m === 0xDA) {           // EOI ou início dos dados da imagem: copia o resto
                $out .= substr($d, $i);
                break;
            }
            if ($m === 0x01 || ($m >= 0xD0 && $m <= 0xD7) || $m === 0xFF) {
                $out .= substr($d, $i, 2);
                $i += $m === 0xFF ? 1 : 2;
                continue;
            }
            $len = unpack('n', substr($d, $i + 2, 2))[1];
            if ($len < 2 || $i + 2 + $len > $n) {
                return null;
            }
            $seg = substr($d, $i, 2 + $len);
            if ($m === 0xE1 && str_starts_with(substr($seg, 4), "Exif\0\0")) {
                $orientacao ??= self::orientacaoExif(substr($seg, 10));
            } elseif (!in_array($m, [0xE1, 0xEC, 0xED, 0xFE], true)) {
                $out .= $seg;                             // mantém JFIF, ICC, Adobe, tabelas etc.
            }
            $i += 2 + $len;
        }
        if ($manterOrientacao && $orientacao !== null && $orientacao !== 1) {
            // APP1 mínimo: TIFF big-endian, IFD0 com uma entrada (0x0112 Orientation, SHORT)
            $tiff = "MM\x00\x2A\x00\x00\x00\x08" . "\x00\x01" . "\x01\x12\x00\x03\x00\x00\x00\x01" . pack('n', $orientacao) . "\x00\x00" . "\x00\x00\x00\x00";
            $app1 = "Exif\0\0" . $tiff;
            $seg = "\xFF\xE1" . pack('n', strlen($app1) + 2) . $app1;
            // depois do APP0 (JFIF) se houver, senão logo após o SOI
            $pos = 2;
            if (substr($out, 2, 2) === "\xFF\xE0") {
                $pos = 4 + unpack('n', substr($out, 4, 2))[1];
            }
            $out = substr($out, 0, $pos) . $seg . substr($out, $pos);
        }
        return $out;
    }

    private static function orientacaoExif(string $t): ?int
    {
        if (strlen($t) < 14) {
            return null;
        }
        $le = substr($t, 0, 2) === 'II';
        $u16 = fn (int $o) => unpack($le ? 'v' : 'n', substr($t, $o, 2))[1] ?? 0;
        $u32 = fn (int $o) => unpack($le ? 'V' : 'N', substr($t, $o, 4))[1] ?? 0;
        $ifd = $u32(4);
        if ($ifd + 2 > strlen($t)) {
            return null;
        }
        $qtd = $u16($ifd);
        for ($k = 0; $k < $qtd && $ifd + 2 + ($k + 1) * 12 <= strlen($t); $k++) {
            $e = $ifd + 2 + $k * 12;
            if ($u16($e) === 0x0112) {
                $v = $u16($e + 8);
                return ($v >= 1 && $v <= 8) ? $v : null;
            }
        }
        return null;
    }

    /**
     * HEIC (padrão das câmeras Samsung com "Fotos de alta eficiência"): o EXIF fica num item interno
     * ("Exif\0\0" + TIFF). Esvazia o diretório de GPS no lugar, sem mudar o tamanho nem os offsets do arquivo.
     */
    public static function heic(string $d): ?string
    {
        $alterou = false;
        foreach (["Exif\0\0II*\0", "Exif\0\0MM\0*"] as $marca) {
            $pos = 0;
            while (($pos = strpos($d, $marca, $pos)) !== false) {
                $alterou = self::tiffSemGps($d, $pos + 6) || $alterou;
                $pos += 10;
            }
        }
        return $alterou ? $d : null;
    }

    /** RAW/DNG (TIFF): esvazia o GPS numa cópia, sem mudar tamanho nem offsets. */
    public static function dng(string $d): ?string
    {
        return self::tiffSemGps($d, 0) ? $d : null;
    }

    /**
     * TIFF/DNG: zera a quantidade de entradas do diretório GPS (IFD apontado pela tag 0x8825 do IFD0).
     * O arquivo continua válido (diretório vazio) e mantém o mesmo tamanho.
     */
    public static function tiffSemGps(string &$d, int $base): bool
    {
        $t = substr($d, $base, 8);
        if (strlen($t) < 8 || !in_array(substr($t, 0, 4), ["II*\0", "MM\0*"], true)) {
            return false;
        }
        $le = $t[0] === 'I';
        $u16 = fn (int $o) => strlen($d) >= $o + 2 ? unpack($le ? 'v' : 'n', substr($d, $o, 2))[1] : 0;
        $u32 = fn (int $o) => strlen($d) >= $o + 4 ? unpack($le ? 'V' : 'N', substr($d, $o, 4))[1] : 0;
        $ifd = $base + $u32($base + 4);
        $qtd = $u16($ifd);
        for ($k = 0; $k < $qtd && $k < 512; $k++) {
            $e = $ifd + 2 + $k * 12;
            if ($u16($e) === 0x8825) {
                $gps = $base + $u32($e + 8);
                if ($gps + 2 <= strlen($d) && $u16($gps) > 0) {
                    $d = substr_replace($d, "\0\0", $gps, 2);
                    return true;
                }
                return false;
            }
        }
        return false;
    }

    /** PNG: descarta eXIf, tEXt, zTXt, iTXt e tIME (os CRCs são por bloco, nada a recalcular). */
    public static function png(string $d): ?string
    {
        if (strncmp($d, "\x89PNG\r\n\x1a\n", 8) !== 0) {
            return null;
        }
        $out = substr($d, 0, 8);
        $i = 8;
        $n = strlen($d);
        while ($i + 12 <= $n) {
            $len = unpack('N', substr($d, $i, 4))[1];
            $tipo = substr($d, $i + 4, 4);
            if ($i + 12 + $len > $n) {
                return null;
            }
            if (!in_array($tipo, ['eXIf', 'tEXt', 'zTXt', 'iTXt', 'tIME'], true)) {
                $out .= substr($d, $i, 12 + $len);
            }
            $i += 12 + $len;
            if ($tipo === 'IEND') {
                break;
            }
        }
        return $out;
    }

    /** WebP: descarta os blocos EXIF e XMP e limpa os bits correspondentes do VP8X. */
    public static function webp(string $d): ?string
    {
        if (strlen($d) < 12 || substr($d, 0, 4) !== 'RIFF' || substr($d, 8, 4) !== 'WEBP') {
            return null;
        }
        $corpo = 'WEBP';
        $i = 12;
        $n = strlen($d);
        while ($i + 8 <= $n) {
            $tipo = substr($d, $i, 4);
            $len = unpack('V', substr($d, $i + 4, 4))[1];
            $total = 8 + $len + ($len % 2);
            if ($i + 8 + $len > $n) {
                return null;
            }
            $bloco = substr($d, $i, min($total, $n - $i));
            if ($tipo === 'VP8X' && $len >= 1) {
                $bloco[8] = chr(ord($bloco[8]) & ~0x0C); // bits 2 (XMP) e 3 (EXIF)
            }
            if ($tipo !== 'EXIF' && $tipo !== 'XMP ') {
                $corpo .= $bloco;
            }
            $i += $total;
        }
        return 'RIFF' . pack('V', strlen($corpo)) . $corpo;
    }
}
