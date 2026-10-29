<?php
declare(strict_types=1);

/** LGPD e MFA sem banco: TOTP (RFC 6238), cifra do segredo, permissão do titular e aviso de privacidade. */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\App;
use App\Core\Auth;
use App\Core\Crypto;
use App\Services\Totp;

function expect(bool $ok, string $label): void
{
    if (!$ok) {
        throw new RuntimeException($label);
    }
}

// 1. TOTP — vetor oficial da RFC 6238 (segredo ASCII "12345678901234567890", T = 59 s → 94287082)
$rfc = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
expect(Totp::codigo($rfc, intdiv(59, 30)) === '287082', 'TOTP difere do vetor da RFC 6238 (T=59).');
expect(Totp::codigo($rfc, intdiv(1111111109, 30)) === '081804', 'TOTP difere do vetor da RFC 6238 (T=1111111109).');

$agora = 1_800_000_000;
$s = Totp::novoSegredo();
expect(strlen($s) === 32 && preg_match('/^[A-Z2-7]+$/', $s) === 1, 'Segredo deve ter 160 bits em Base32.');
$passo = Totp::passoAtual($agora);
$cod = Totp::codigo($s, $passo);
expect(Totp::verificar($s, $cod, null, $agora) === $passo, 'Código atual deve ser aceito.');
expect(Totp::verificar($s, substr($cod, 0, 3) . ' ' . substr($cod, 3), null, $agora) === $passo, 'Código com espaço deve ser aceito.');
expect(Totp::verificar($s, Totp::codigo($s, $passo - 1), null, $agora) === $passo - 1, 'Tolerância de 30 s para relógio atrasado.');
expect(Totp::verificar($s, Totp::codigo($s, $passo - 3), null, $agora) === null, 'Código de 90 s atrás deve ser recusado.');
expect(Totp::verificar($s, $cod, $passo, $agora) === null, 'Código já usado deve ser recusado (anti-replay).');
expect(Totp::verificar($s, '12345', null, $agora) === null, 'Código com 5 dígitos deve ser recusado.');
expect(str_starts_with(Totp::uri($s, 'ti@salinasgold.com.br'), 'otpauth://totp/'), 'URI otpauth inválida.');

// 2. Segredo do MFA cifrado com subchave (não usa a chave do CPF diretamente)
$cfgProp = new ReflectionProperty(App::class, 'config');
$cfg = $cfgProp->getValue();
$cfg['seguranca']['cpf_key'] = base64_encode(random_bytes(32));
$cfg['seguranca']['cpf_index_key'] = base64_encode(random_bytes(32));
$cfgProp->setValue(null, $cfg);
$blob = Crypto::encryptSegredo($s);
expect(!str_contains($blob, $s), 'Segredo não pode ficar em texto puro.');
expect(Crypto::decryptSegredo($blob) === $s, 'Segredo deve voltar igual ao decifrar.');
expect(Crypto::decryptCpf($blob) === null, 'A chave do CPF não deve abrir o segredo do MFA (subchave separada).');
expect(Crypto::decryptSegredo(null) === null, 'Segredo vazio deve devolver null.');

// 3. Exportação dos dados do titular: só Admin/TI
$papel = static function (string $p): void {
    (new ReflectionProperty(Auth::class, 'loaded'))->setValue(null, true);
    (new ReflectionProperty(Auth::class, 'user'))->setValue(null, ['id' => 't-' . $p, 'papel' => $p, 'seguranca_patrimonial' => 0]);
};
foreach (['supervisor', 'ssma', 'porteiro'] as $p) {
    $papel($p);
    expect(!Auth::can('lgpd.titular'), "Perfil {$p} não pode exportar dados do titular.");
}
$papel('admin');
expect(Auth::can('lgpd.titular'), 'Admin deve exportar dados do titular.');

// 4. Aviso de privacidade: encarregado, bases legais e prazos vindos das configurações
$_SERVER['SCRIPT_NAME'] = '/index.php';
ob_start();
$dadosAviso = ['cfg' => [
    'org_nome' => 'Salinas Gold Mineração LTDA', 'encarregado_nome' => 'Maria DPO', 'encarregado_email' => 'dpo@salinasgold.com.br',
    'retencao_fotos_dias' => '90', 'retencao_registros_meses' => '24',
]];
(static function (array $__d): void {
    extract($__d);
    require App::root('app/Views/publico/privacidade.php');
})($dadosAviso);
$html = (string) ob_get_clean();
foreach (['Maria DPO', 'dpo@salinasgold.com.br', 'art. 7º, IX', 'art. 7º, II', '90 dias', 'art. 18', 'ANPD'] as $trecho) {
    expect(str_contains($html, $trecho), 'Aviso de privacidade sem: ' . $trecho);
}
expect(!str_contains(strtolower($html), 'autorizo'), 'Aviso não deve pedir consentimento.');

echo "LGPD: TOTP (RFC 6238, anti-replay), segredo cifrado, exportação do titular e aviso de privacidade OK.\n";
