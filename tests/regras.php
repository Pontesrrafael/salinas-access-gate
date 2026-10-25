<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Controllers\SolicitacoesController;
use App\Core\App;
use App\Core\Auth;
use App\Core\Http;

function expect(bool $ok, string $label): void
{
    if (!$ok) {
        throw new RuntimeException($label);
    }
}

function role(?string $role, int $segPatrimonial = 0): void
{
    (new ReflectionProperty(Auth::class, 'loaded'))->setValue(null, true);
    (new ReflectionProperty(Auth::class, 'user'))->setValue(
        null,
        $role === null ? null : ['id' => 'test-' . $role, 'papel' => $role, 'seguranca_patrimonial' => $segPatrimonial]
    );
}

$cfgProp = new ReflectionProperty(App::class, 'config');
$cfgOriginal = $cfgProp->getValue();

function setConfig(array $overrides): void
{
    global $cfgProp, $cfgOriginal;
    $cfgProp->setValue(null, array_replace_recursive($cfgOriginal, $overrides));
}

// 1. Matriz de permissões (Auth::can) e autorização de solicitações
role(null);
expect(!Auth::can('portaria.operar') && !Auth::can('pessoas.ver_cpf') && !Auth::can('ssma.verificar'), 'Visitante não deve ter permissões.');

role('porteiro');
expect(Auth::can('portaria.operar'), 'Porteiro deve operar a portaria.');
expect(Auth::can('pessoas.ver') && Auth::can('pessoas.criar'), 'Porteiro deve ver e cadastrar pessoas.');
expect(!Auth::can('pessoas.editar'), 'Porteiro não deve editar pessoas.');
expect(!Auth::can('pessoas.ver_cpf'), 'Porteiro não pode ver CPF completo.');
expect(!Auth::can('ssma.verificar'), 'Porteiro não faz verificação SSMA.');
expect(!Auth::can('usuarios.gerir') && !Auth::can('configuracoes.gerir'), 'Porteiro não administra o sistema.');

$ctrl = new SolicitacoesController();
$podeAutorizar = new ReflectionMethod(SolicitacoesController::class, 'podeAutorizar');
$solicitacao = ['id' => 'sol-1', 'responsavel_user_id' => 'usr-resp'];

expect(
    !$podeAutorizar->invoke($ctrl, $solicitacao, ['id' => 'usr-port', 'papel' => 'porteiro', 'seguranca_patrimonial' => 0]),
    'Porteiro não pode autorizar solicitações.'
);
expect(
    $podeAutorizar->invoke($ctrl, $solicitacao, ['id' => 'usr-adm', 'papel' => 'admin', 'seguranca_patrimonial' => 0]),
    'Admin deve poder autorizar solicitações.'
);
expect(
    $podeAutorizar->invoke($ctrl, $solicitacao, ['id' => 'usr-seg', 'papel' => 'supervisor', 'seguranca_patrimonial' => 1]),
    'Segurança Patrimonial deve poder autorizar solicitações.'
);
expect(
    $podeAutorizar->invoke($ctrl, $solicitacao, ['id' => 'usr-resp', 'papel' => 'supervisor', 'seguranca_patrimonial' => 0]),
    'Responsável da área deve poder autorizar solicitações.'
);

role('ssma');
expect(Auth::can('ssma.verificar'), 'SSMA deve poder realizar verificação SSMA.');
expect(Auth::can('documentos.gerir') && Auth::can('documentos.excluir'), 'SSMA deve gerir documentos de saúde/segurança.');
expect(!Auth::can('pessoas.ver_cpf'), 'SSMA não deve ver CPF completo.');
expect(!Auth::can('portaria.operar'), 'SSMA não opera portaria.');

role('supervisor');
expect(Auth::can('pessoas.ver_cpf'), 'Supervisor deve poder ver CPF completo.');
expect(Auth::can('relatorios.ver') && Auth::can('auditoria.ver'), 'Supervisor deve ver relatórios e auditoria.');
expect(!Auth::can('portaria.operar') && !Auth::can('ssma.verificar'), 'Supervisor não opera portaria nem verifica SSMA.');

role('admin');
foreach (['portaria.operar', 'pessoas.ver_cpf', 'ssma.verificar', 'usuarios.gerir', 'configuracoes.gerir'] as $perm) {
    expect(Auth::can($perm), 'Admin deve possuir a permissão: ' . $perm);
}

// 2. Http::clientIp com proxies confiáveis e cabeçalhos forjados
setConfig(['seguranca' => ['trusted_proxies' => []]]);
$_SERVER['REMOTE_ADDR'] = '198.51.100.10';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.99';
expect(Http::clientIp() === '198.51.100.10', 'Sem proxy confiável, X-Forwarded-For deve ser ignorado.');

setConfig(['seguranca' => ['trusted_proxies' => ['10.0.0.1', '10.0.0.2']]]);
$_SERVER['REMOTE_ADDR'] = '198.51.100.10';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.99';
expect(Http::clientIp() === '198.51.100.10', 'Conexão direta fora de trusted_proxies deve ignorar X-Forwarded-For.');

$_SERVER['REMOTE_ADDR'] = '10.0.0.1';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '1.1.1.1, 203.0.113.50';
expect(Http::clientIp() === '203.0.113.50', 'Deve ignorar IP forjado à esquerda e pegar o primeiro não confiável da direita para a esquerda.');

$_SERVER['REMOTE_ADDR'] = '10.0.0.1';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '1.1.1.1, 203.0.113.50, 10.0.0.2';
expect(Http::clientIp() === '203.0.113.50', 'Deve pular proxies confiáveis à direita até encontrar o IP real do cliente.');

$_SERVER['REMOTE_ADDR'] = '10.0.0.1';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '10.0.0.2, 10.0.0.1';
expect(Http::clientIp() === '10.0.0.1', 'Se todos os saltos forem proxies confiáveis, deve devolver REMOTE_ADDR.');

$_SERVER['REMOTE_ADDR'] = '10.0.0.1';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '1.1.1.1, ip-invalido';
expect(Http::clientIp() === '10.0.0.1', 'IP inválido na borda direita não deve permitir leitura de IP forjado à esquerda.');

// 3. Http::absoluteUrl em produção vs ambiente local
$_SERVER['HTTP_HOST'] = 'host-forjado.exemplo';
setConfig(['app' => ['env' => 'production', 'url' => 'https://accessgate.mycode.app.br']]);
expect(
    Http::absoluteUrl('/solicitar-acesso/token123') === 'https://accessgate.mycode.app.br/solicitar-acesso/token123',
    'Em produção com APP_URL definido, deve usar APP_URL e ignorar HTTP_HOST.'
);

setConfig(['app' => ['env' => 'production', 'url' => '']]);
$falhouProdSemUrl = false;
try {
    Http::absoluteUrl('/solicitar-acesso/token123');
} catch (RuntimeException $e) {
    $falhouProdSemUrl = true;
}
expect($falhouProdSemUrl, 'Em produção com APP_URL vazio, Http::absoluteUrl deve lançar RuntimeException.');

$_SERVER['HTTP_HOST'] = 'localhost:8080';
$_SERVER['SCRIPT_NAME'] = '/index.php';
setConfig(['app' => ['env' => 'local', 'url' => '']]);
expect(
    str_starts_with(Http::absoluteUrl('/solicitar-acesso/token123'), 'http://localhost:8080/'),
    'Em ambiente local sem APP_URL, deve permitir fallback para HTTP_HOST.'
);

$cfgProp->setValue(null, $cfgOriginal);
echo "Regras: RBAC (perfis e autorização), Http::clientIp e Http::absoluteUrl OK.\n";
