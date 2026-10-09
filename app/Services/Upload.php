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
 */
final class Upload
{
    public const MIMES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

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
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']) ?: '';
            if (!isset(self::MIMES[$mime]) || @getimagesize($f['tmp_name']) === false) {
                $erros[] = "Formato inválido: {$nome} (use JPG, PNG ou WEBP).";
            }
        }
        return $erros;
    }

    /** Grava o arquivo e retorna metadados. */
    public static function salvar(array $f): array
    {
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']) ?: '';
        $ext = self::MIMES[$mime] ?? throw new RuntimeException('Tipo de arquivo não permitido');
        $rel = date('Y/m') . '/' . Uuid::v4() . '.' . $ext;
        $dest = self::dir() . '/' . $rel;
        if (!is_dir(dirname($dest)) && !mkdir(dirname($dest), 0750, true) && !is_dir(dirname($dest))) {
            throw new RuntimeException('Não foi possível criar a pasta de uploads');
        }
        $ok = is_uploaded_file($f['tmp_name']) ? move_uploaded_file($f['tmp_name'], $dest) : rename($f['tmp_name'], $dest);
        if (!$ok) {
            throw new RuntimeException('Falha ao gravar o arquivo enviado');
        }
        @chmod($dest, 0640);
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
        if (!preg_match('#^\d{4}/\d{2}/[0-9a-f\-]{36}\.(jpg|png|webp)$#', $rel)) {
            return null;
        }
        $p = self::dir() . '/' . $rel;
        return is_file($p) ? $p : null;
    }
}
