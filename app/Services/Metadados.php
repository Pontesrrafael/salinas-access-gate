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
    /** Limpa o arquivo no lugar. Retorna true se algo foi removido. */
    public static function limpar(string $arquivo, string $mime): bool
    {
        $dados = @file_get_contents($arquivo);
        if ($dados === false) {
            return false;
        }
        $novo = match ($mime) {
            'image/jpeg' => self::jpeg($dados),
            'image/png'  => self::png($dados),
            'image/webp' => self::webp($dados),
            default      => null,
        };
        if ($novo === null || $novo === $dados) {
            return false;
        }
        return file_put_contents($arquivo, $novo, LOCK_EX) !== false;
    }

    /** JPEG: descarta APP1 (EXIF/XMP), APP12, APP13 (IPTC) e comentários; recria EXIF só com a orientação. */
    public static function jpeg(string $d): ?string
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
        if ($orientacao !== null && $orientacao !== 1) {
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
