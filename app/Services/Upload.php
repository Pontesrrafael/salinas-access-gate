<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Core\Uuid;
use RuntimeException;

/**
 * Upload seguro de fotos da portaria:
 *  - tipo real verificado por finfo (não confia na extensão/MIME do navegador)
 *  - imagem validada com getimagesize
 *  - nome aleatório, gravado FORA da pasta pública (storage/uploads/AAAA/MM/)
 *  - metadados (EXIF/GPS, XMP, IPTC) removidos; só a orientação é mantida
 */
final class Upload
{
    /** Formatos aceitos. HEIC/HEIF é o padrão das câmeras Samsung ("Fotos de alta eficiência"); DNG é o RAW do modo Pro. */
    public const MIMES = [
        'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp',
        'image/heic' => 'heic', 'image/x-adobe-dng' => 'dng',
    ];
    /** Exibidos direto no navegador; os demais aparecem como arquivo para baixar. */
    public const MIMES_WEB = ['image/jpeg', 'image/png', 'image/webp'];
    public const EXTENSOES_ACEITAS = '.jpg,.jpeg,.png,.webp,.heic,.heif,.dng';

    /**
     * Normaliza $_FILES[campo] (múltiplo) em lista.
     * @return array<int, array{name:string,tmp_name:string,error:int,size:int}>
     */
    public static function files(string $campo): array
    {
        $f = $_FILES[$campo] ?? null;
        if (!$f || !is_array($f['name'] ?? null)) {
            return [];
        }
        $out = [];
        foreach ($f['name'] as $i => $name) {
            if (($f['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $out[] = [
                'name'     => (string) $name,
                'tmp_name' => (string) $f['tmp_name'][$i],
                'error'    => (int) $f['error'][$i],
                'size'     => (int) $f['size'][$i],
            ];
        }
        return $out;
    }

    /** Valida sem gravar. Retorna lista de erros (vazia = ok). */
    public static function validar(array $files): array
    {
        $cfg = App::config('uploads');
        $erros = [];
        if (count($files) > $cfg['max_files']) {
            $erros[] = "Máximo de {$cfg['max_files']} fotos por registro.";
        }
        $total = array_sum(array_map(fn ($f) => (int) $f['size'], $files));
        if ($total > $cfg['max_total_mb'] * 1024 * 1024) {
            $erros[] = 'As fotos somam ' . round($total / 1048576, 1) . " MB; o limite por movimentação é {$cfg['max_total_mb']} MB.";
        }
        foreach ($files as $f) {
            $nome = mb_substr($f['name'], 0, 80);
            if ($f['error'] !== UPLOAD_ERR_OK) {
                $erros[] = "Falha no envio de {$nome} (código {$f['error']}).";
                continue;
            }
            if ($f['size'] > $cfg['max_mb'] * 1024 * 1024) {
                $erros[] = "{$nome} excede {$cfg['max_mb']} MB.";
                continue;
            }
            if (self::detectar($f['tmp_name']) === null) {
                $erros[] = "Formato inválido: {$nome} (use JPG, PNG, WEBP, HEIC ou RAW/DNG).";
            }
        }
        return $erros;
    }

    /**
     * Tipo real do arquivo pelo conteúdo (não pela extensão nem pelo navegador), ou null se não for aceito.
     */
    public static function detectar(string $arquivo): ?string
    {
        $info = @getimagesize($arquivo);
        if ($info !== false && in_array($info['mime'] ?? '', self::MIMES_WEB, true)) {
            return $info['mime'];
        }
        $cab = (string) @file_get_contents($arquivo, false, null, 0, 64);
        // HEIC/HEIF: caixa 'ftyp' com marca HEVC (avif e vídeos ficam de fora)
        if (substr($cab, 4, 4) === 'ftyp') {
            $tam = unpack('N', substr($cab, 0, 4))[1] ?? 0;
            $marcas = str_split(substr($cab, 8, max(0, min($tam, 64) - 8)), 4);
            if (array_intersect($marcas, ['heic', 'heix', 'heim', 'heis', 'hevc', 'hevx'])) {
                return 'image/heic';
            }
            return null;
        }
        // RAW/DNG: TIFF com a tag DNGVersion (0xC612) no primeiro diretório
        if ($info !== false && in_array($info[2] ?? 0, [IMAGETYPE_TIFF_II, IMAGETYPE_TIFF_MM], true)) {
            $d = (string) @file_get_contents($arquivo, false, null, 0, 65536);
            $le = $d[0] === 'I';
            $u16 = fn (int $o) => strlen($d) >= $o + 2 ? unpack($le ? 'v' : 'n', substr($d, $o, 2))[1] : 0;
            $ifd = strlen($d) >= 8 ? unpack($le ? 'V' : 'N', substr($d, 4, 4))[1] : 0;
            for ($k = 0, $n = $u16($ifd); $k < $n && $k < 512; $k++) {
                if ($u16($ifd + 2 + $k * 12) === 0xC612) {
                    return 'image/x-adobe-dng';
                }
            }
        }
        return null;
    }

    /** Conversor HEIC → JPEG (pacote libheif-examples), se houver no servidor. */
    private static function conversorHeic(): ?string
    {
        foreach (['heif-dec', 'heif-convert'] as $cmd) {
            foreach (explode(PATH_SEPARATOR, (string) getenv('PATH') ?: '/usr/bin:/usr/local/bin') as $dir) {
                if ($dir !== '' && is_executable($dir . '/' . $cmd)) {
                    return $dir . '/' . $cmd;
                }
            }
        }
        return null;
    }

    /** Converte HEIC em JPEG (já girado). Retorna o caminho do JPEG temporário ou null. */
    private static function heicParaJpeg(string $origem): ?string
    {
        $bin = self::conversorHeic();
        if ($bin === null || !function_exists('proc_open')) {
            return null;
        }
        $destino = sys_get_temp_dir() . '/sag-heic-' . bin2hex(random_bytes(8)) . '.jpg';
        $p = proc_open([$bin, '-q', '90', $origem, $destino], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        if (!is_resource($p) || proc_close($p) !== 0 || !is_file($destino) || (@getimagesize($destino)['mime'] ?? '') !== 'image/jpeg') {
            @unlink($destino);
            return null;
        }
        return $destino;
    }

    /** Grava o arquivo e retorna metadados. HEIC vira JPEG quando o servidor tem o conversor. */
    public static function salvar(array $f): array
    {
        $mime = self::detectar($f['tmp_name']) ?? throw new RuntimeException('Tipo de arquivo não permitido');
        $convertido = false;
        if ($mime === 'image/heic' && ($jpg = self::heicParaJpeg($f['tmp_name'])) !== null) {
            @unlink($f['tmp_name']);
            $f['tmp_name'] = $jpg;
            $mime = 'image/jpeg';
            $convertido = true;
        }
        $ext = self::MIMES[$mime];
        $rel = date('Y/m') . '/' . Uuid::v4() . '.' . $ext;
        $dest = self::dir() . '/' . $rel;
        if (!is_dir(dirname($dest)) && !mkdir(dirname($dest), 0750, true) && !is_dir(dirname($dest))) {
            throw new RuntimeException('Não foi possível criar a pasta de uploads');
        }
        $ok = is_uploaded_file($f['tmp_name']) ? move_uploaded_file($f['tmp_name'], $dest) : (rename($f['tmp_name'], $dest) || (copy($f['tmp_name'], $dest) && @unlink($f['tmp_name'])));
        if (!$ok) {
            throw new RuntimeException('Falha ao gravar o arquivo enviado');
        }
        @chmod($dest, 0640);
        Metadados::limpar($dest, $mime, !$convertido); // remove GPS/EXIF da foto (LGPD)
        clearstatcache(true, $dest);
        return [
            'storage_path'  => $rel,
            'mime_type'     => $mime,
            'tamanho_bytes' => filesize($dest) ?: $f['size'],
            'sha256'        => hash_file('sha256', $dest),
            'nome_arquivo'  => mb_substr(basename($f['name']), 0, 200),
        ];
    }

    public static function dir(): string
    {
        return rtrim(App::config('uploads')['dir'], '/\\');
    }

    /** Caminho absoluto seguro (impede path traversal). */
    public static function caminho(string $rel): ?string
    {
        if (!preg_match('#^\d{4}/\d{2}/[0-9a-f\-]{36}\.(jpg|png|webp|heic|dng)$#', $rel)) {
            return null;
        }
        $p = self::dir() . '/' . $rel;
        return is_file($p) ? $p : null;
    }
}
