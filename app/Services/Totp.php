<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Códigos de 6 dígitos a cada 30 s (TOTP, RFC 6238 / HMAC-SHA1), compatíveis com
 * Microsoft Authenticator, Google Authenticator, Authy e similares.
 */
final class Totp
{
    private const ALFABETO = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    private const PERIODO = 30;

    /** Segredo novo de 160 bits em Base32. */
    public static function novoSegredo(): string
    {
        return self::base32(random_bytes(20));
    }

    public static function codigo(string $segredo, int $passo): string
    {
        $hash = hash_hmac('sha1', pack('J', $passo), self::base32Decode($segredo), true);
        $off = ord($hash[19]) & 0x0f;
        $n = ((ord($hash[$off]) & 0x7f) << 24) | (ord($hash[$off + 1]) << 16) | (ord($hash[$off + 2]) << 8) | ord($hash[$off + 3]);
        return str_pad((string) ($n % 1000000), 6, '0', STR_PAD_LEFT);
    }

    public static function passoAtual(?int $agora = null): int
    {
        return intdiv($agora ?? time(), self::PERIODO);
    }

    /**
     * Confere o código aceitando 30 s de diferença de relógio. Recusa passo já usado (anti-replay).
     * @return int|null passo aceito, para gravar em mfa_ultimo_passo
     */
    public static function verificar(string $segredo, string $codigo, ?int $ultimoPasso = null, ?int $agora = null): ?int
    {
        $codigo = preg_replace('/\D/', '', $codigo) ?? '';
        if (strlen($codigo) !== 6) {
            return null;
        }
        $atual = self::passoAtual($agora);
        foreach ([$atual, $atual - 1, $atual + 1] as $passo) {
            if ($ultimoPasso !== null && $passo <= $ultimoPasso) {
                continue;
            }
            if (hash_equals(self::codigo($segredo, $passo), $codigo)) {
                return $passo;
            }
        }
        return null;
    }

    /** Endereço otpauth:// lido pelos aplicativos autenticadores. */
    public static function uri(string $segredo, string $conta, string $emissor = 'Salinas Access Gate'): string
    {
        return 'otpauth://totp/' . rawurlencode($emissor . ':' . $conta)
            . '?secret=' . $segredo . '&issuer=' . rawurlencode($emissor) . '&algorithm=SHA1&digits=6&period=' . self::PERIODO;
    }

    /** Segredo em grupos de 4 letras para digitar no celular. */
    public static function formatar(string $segredo): string
    {
        return trim(chunk_split($segredo, 4, ' '));
    }

    private static function base32(string $bin): string
    {
        $bits = '';
        foreach (str_split($bin) as $c) {
            $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $grupo) {
            $out .= self::ALFABETO[bindec(str_pad($grupo, 5, '0'))];
        }
        return $out;
    }

    private static function base32Decode(string $b32): string
    {
        $bits = '';
        foreach (str_split(strtoupper(rtrim($b32, '='))) as $c) {
            $i = strpos(self::ALFABETO, $c);
            if ($i !== false) {
                $bits .= str_pad(decbin($i), 5, '0', STR_PAD_LEFT);
            }
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }
        return $out;
    }
}
