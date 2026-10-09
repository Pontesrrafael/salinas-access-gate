<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Proteção do CPF (LGPD):
 *  - cpf_enc  = nonce || secretbox(cpf)   (XSalsa20-Poly1305 / libsodium)
 *  - cpf_hash = HMAC-SHA256(cpf, chave_indice)  → índice cego para busca exata e unicidade
 * As chaves ficam somente no .env (CPF_ENCRYPTION_KEY e CPF_INDEX_KEY, base64 de 32 bytes).
 */
final class Crypto
{
    public static function cpfDigits(?string $cpf): string
    {
        return preg_replace('/\D/', '', (string) $cpf) ?? '';
    }

    /** Valida dígitos verificadores do CPF. */
    public static function cpfValido(string $cpf): bool
    {
        $d = self::cpfDigits($cpf);
        if (strlen($d) !== 11 || preg_match('/^(\d)\1{10}$/', $d)) {
            return false;
        }
        for ($t = 9; $t < 11; $t++) {
            $soma = 0;
            for ($i = 0; $i < $t; $i++) {
                $soma += (int) $d[$i] * (($t + 1) - $i);
            }
            $dv = ((10 * $soma) % 11) % 10;
            if ((int) $d[$t] !== $dv) {
                return false;
            }
        }
        return true;
    }

    public static function encryptCpf(?string $cpf): ?string
    {
        $d = self::cpfDigits($cpf);
        if ($d === '') {
            return null;
        }
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return $nonce . sodium_crypto_secretbox($d, $nonce, self::key('cpf_key'));
    }

    public static function decryptCpf(?string $blob): ?string
    {
        if ($blob === null || $blob === '') {
            return null;
        }
        $nonce = substr($blob, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($blob, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open($cipher, $nonce, self::key('cpf_key'));
        return $plain === false ? null : $plain;
    }

    public static function cpfHash(?string $cpf): ?string
    {
        $d = self::cpfDigits($cpf);
        return $d === '' ? null : hash_hmac('sha256', $d, self::key('cpf_index_key'));
    }

    public static function formatCpf(?string $digits): ?string
    {
        if ($digits === null || $digits === '') {
            return null;
        }
        if (strlen($digits) !== 11) {
            return $digits;
        }
        return substr($digits, 0, 3) . '.' . substr($digits, 3, 3) . '.' . substr($digits, 6, 3) . '-' . substr($digits, 9, 2);
    }

    /** Máscara igual à do sistema anterior: ***.456.789-** */
    public static function maskCpf(?string $digits): ?string
    {
        if ($digits === null || $digits === '') {
            return null;
        }
        if (strlen($digits) !== 11) {
            return '***';
        }
        return '***.' . substr($digits, 3, 3) . '.' . substr($digits, 6, 3) . '-**';
    }

    private static function key(string $name): string
    {
        static $cache = [];
        if (!isset($cache[$name])) {
            $b64 = (string) (App::config('seguranca')[$name] ?? '');
            $raw = base64_decode($b64, true);
            if ($raw === false || strlen($raw) !== 32) {
                throw new RuntimeException(
                    'Chave ' . strtoupper($name) . ' ausente/inválida no .env. Rode: php bin/console.php gerar-chaves'
                );
            }
            $cache[$name] = $raw;
        }
        return $cache[$name];
    }

    public static function newKey(): string
    {
        return base64_encode(random_bytes(32));
    }
}
