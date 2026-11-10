<?php
declare(strict_types=1);

/**
 * Testes unitários de notificações por e-mail do fluxo de solicitações de acesso
 * e verificação SSMA (etapa A5).
 * Execução: php tests/notificacoes_acesso.php
 */

require dirname(__DIR__) . '/app/helpers.php';

spl_autoload_register(function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $rel = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = dirname(__DIR__) . '/app/' . $rel . '.php';
    if (is_file($file)) {
        require $file;
    }
});

use App\Core\App;
use App\Core\I18n;
use App\Services\Email;
use App\Services\Notificacoes;
use App\Services\NotificacoesAcesso;
use App\Services\Settings;

$falhas = 0;
function check(bool $cond, string $msg): void
{
    global $falhas;
    if ($cond) {
        echo "  [OK]   {$msg}\n";
    } else {
        echo "  [ERRO] {$msg}\n";
        $falhas++;
    }
}

echo "=== Testes de NotificacoesAcesso (Solicitações e SSMA) ===\n";

$refApp = new ReflectionClass(App::class);
$propCfg = $refApp->getProperty('config');
$propCfg->setValue(null, [
    'app' => [
        'name'  => 'Salinas Access Gate',
        'env'   => 'test',
        'debug' => true,
        'url'   => 'https://acesso.salinasgold.com.br',
        'tz'    => 'America/Cuiaba',
        'key'   => str_repeat('k', 32),
    ],
    'paths' => [
        'root'    => dirname(__DIR__),
        'storage' => dirname(__DIR__) . '/storage',
        'logs'    => dirname(__DIR__) . '/storage/logs',
    ],
]);

$refSettings = new ReflectionClass(Settings::class);
$propSettings = $refSettings->getProperty('cache');
$propSettings->setValue(null, [
    'email_ativo'        => '1',
    'email_acesso_ativo' => '1',
]);

Email::definirAtivo(true);
NotificacoesAcesso::definirAtivo(null); // usa Settings::bool('email_acesso_ativo', true)

$idCriador   = '11111111-1111-4111-8111-111111111111';
$idRespArea  = '22222222-2222-4222-8222-222222222222';
$idSegPat1   = '33333333-3333-4333-8333-333333333333';
$idSsma1     = '44444444-4444-4444-8444-444444444444';
$idSsma2     = '55555555-5555-4555-8555-555555555555';
$idArea      = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
$idSolic     = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';

$usuariosMock = [
    $idCriador => [
        'id' => $idCriador,
        'nome' => 'Carlos Solicitante',
        'email' => 'carlos.solicitante@salinasgold.com.br',
        'papel' => 'anfitriao',
        'seguranca_patrimonial' => 0,
        'ativo' => 1,
    ],
    $idRespArea => [
        'id' => $idRespArea,
        'nome' => 'Fernanda Gestora',
        'email' => 'fernanda.gestora@salinasgold.com.br',
        'papel' => 'gestor',
        'seguranca_patrimonial' => 1, // também tem flag seg_patrimonial para testar deduplicação
        'ativo' => 1,
    ],
    $idSegPat1 => [
        'id' => $idSegPat1,
        'nome' => 'Paulo Patrimonial',
        'email' => 'paulo.patrimonial@salinasgold.com.br',
        'papel' => 'porteiro',
        'seguranca_patrimonial' => 1,
        'ativo' => 1,
    ],
    $idSsma1 => [
        'id' => $idSsma1,
        'nome' => 'Helena SSMA',
        'email' => 'helena.ssma@salinasgold.com.br',
        'papel' => 'ssma',
        'seguranca_patrimonial' => 0,
        'ativo' => 1,
    ],
    $idSsma2 => [
        'id' => $idSsma2,
        'nome' => 'Marcos Multiperfil SSMA',
        'email' => 'marcos.ssma@salinasgold.com.br',
        'papel' => 'anfitriao', // perfil principal anfitriao, perfil adicional ssma
        'seguranca_patrimonial' => 0,
        'ativo' => 1,
    ],
];

$resolvedorPadrao = function (string $tipo, mixed $arg = null) use (
    &$usuariosMock,
    $idArea,
    $idRespArea,
    $idSegPat1,
    $idSsma1,
    $idSsma2
): mixed {
    if ($tipo === 'usuario') {
        $u = $usuariosMock[(string) $arg] ?? null;
        return ($u && (int) $u['ativo'] === 1) ? $u : null;
    }
    if ($tipo === 'area') {
        return $arg === $idArea
            ? ['id' => $idArea, 'nome' => 'Usina de Beneficiamento', 'responsavel_user_id' => $idRespArea]
            : null;
    }
    if ($tipo === 'autorizadores') {
        // Retorna responsável da área + seg_patrimonial (com duplicata proposital de $idRespArea para validar deduplicação)
        return [
            $usuariosMock[$idRespArea],
            $usuariosMock[$idRespArea],
            $usuariosMock[$idSegPat1],
        ];
    }
    if ($tipo === 'ssma') {
        return [
            $usuariosMock[$idSsma1],
            $usuariosMock[$idSsma2],
            $usuariosMock[$idSsma1], // duplicado proposital
        ];
    }
    if ($tipo === 'solicitacao_meta') {
        return [
            'area_id'             => $idArea,
            'area_nome'           => 'Usina de Beneficiamento',
            'responsavel_user_id' => $idRespArea,
        ];
    }
    return null;
};

NotificacoesAcesso::definirResolvedor($resolvedorPadrao);

$solicitacaoBase = [
    'id'               => $idSolic,
    'visitante_nome'   => 'João Terceiro da Silva',
    'visitante_doc'    => '123.456.789-01',
    'empresa'          => 'Engenharia Exemplo LTDA',
    'contato_telefone' => '(65) 99999-1234',
    'contato_email'    => 'joao.visitante@externo.com.br',
    'tipo'             => 'terceiro',
    'area_id'          => $idArea,
    'data_prevista'    => '2026-11-25',
    'motivo'           => 'Manutenção preventiva confidencial no britador secundário',
    'atividade_risco'  => 0,
    'status'           => 'pendente',
    'origem'           => 'interna',
    'criado_por'       => $idCriador,
];

// 1. Evento criada (solicitação interna): notifica autorizadores da área deduplicados, nunca o autor nem o visitante
Email::usarFilaMemoria([]);
$qtd = NotificacoesAcesso::solicitacao('criada', $solicitacaoBase, ['usuario_id' => $idCriador]);
$fila = Email::obterFilaMemoria();
check($qtd === 2 && count($fila) === 2, 'criada (interna): enfileira 2 e-mails deduplicados para autorizadores da área');
$destinatarios = array_column($fila, 'para');
check(
    in_array('fernanda.gestora@salinasgold.com.br', $destinatarios, true)
    && in_array('paulo.patrimonial@salinasgold.com.br', $destinatarios, true),
    'criada (interna): envia para responsável da área e Segurança Patrimonial'
);
check(!in_array('joao.visitante@externo.com.br', $destinatarios, true), 'criada: nunca envia e-mail ao visitante externo (contato_email)');
check(!in_array('carlos.solicitante@salinasgold.com.br', $destinatarios, true), 'criada: não envia ao próprio criador');

// Verifica exclusão do próprio autor quando o autor é o responsável da área
Email::usarFilaMemoria([]);
$qtdAutorGestor = NotificacoesAcesso::solicitacao('criada', [
    ...$solicitacaoBase,
    'criado_por' => $idRespArea,
], ['usuario_id' => $idRespArea]);
$filaAutorGestor = Email::obterFilaMemoria();
check(
    $qtdAutorGestor === 1 && $filaAutorGestor[0]['para'] === 'paulo.patrimonial@salinasgold.com.br',
    'criada: exclui o próprio autor caso ele seja responsável da área / Segurança Patrimonial'
);

// Verifica que link externo ainda aguardando preenchimento não dispara e-mail prematuro
Email::usarFilaMemoria([]);
$qtdLinkVazio = NotificacoesAcesso::solicitacao('criada', [
    ...$solicitacaoBase,
    'visitante_nome'           => '(aguardando preenchimento externo)',
    'aguardando_preenchimento' => 1,
    'origem'                   => 'link',
], ['usuario_id' => $idCriador]);
check($qtdLinkVazio === 0 && count(Email::obterFilaMemoria()) === 0, 'criada: ignora link externo ainda aguardando preenchimento');

// 2. Minimização LGPD e identidade visual no corpo do e-mail
$msgHtml = $fila[0]['corpo_html'];
$msgTxt  = $fila[0]['corpo_texto'];
$assunto = $fila[0]['assunto'];

check(str_contains($msgHtml, 'João Terceiro da Silva') && str_contains($msgTxt, 'João Terceiro da Silva'), 'Conteúdo: inclui nome do visitante');
check(str_contains($msgHtml, 'Engenharia Exemplo LTDA') && str_contains($msgTxt, 'Engenharia Exemplo LTDA'), 'Conteúdo: inclui empresa');
check(str_contains($msgHtml, '25/11/2026') && str_contains($msgTxt, '25/11/2026'), 'Conteúdo: inclui data prevista formatada');
check(str_contains($msgHtml, 'Usina de Beneficiamento') && str_contains($msgTxt, 'Usina de Beneficiamento'), 'Conteúdo: inclui nome da área');
check(str_contains($msgHtml, 'https://acesso.salinasgold.com.br/solicitacoes'), 'Conteúdo: inclui link para /solicitacoes');
check(
    !str_contains($msgHtml, '123.456.789-01') && !str_contains($msgTxt, '123.456.789-01') && !str_contains($assunto, '123.456.789-01'),
    'LGPD: não expõe CPF/documento no assunto, HTML ou texto'
);
check(
    !str_contains($msgHtml, '99999-1234') && !str_contains($msgTxt, '99999-1234'),
    'LGPD: não expõe telefone no e-mail'
);
check(
    !str_contains($msgHtml, 'joao.visitante@externo.com.br') && !str_contains($msgTxt, 'joao.visitante@externo.com.br'),
    'LGPD: não expõe e-mail do visitante no corpo'
);
check(
    !str_contains($msgHtml, 'britador secundário') && !str_contains($msgTxt, 'britador secundário'),
    'LGPD: não expõe motivo detalhado no corpo do e-mail'
);
check(
    str_contains($msgHtml, 'Calibri') && str_contains($msgHtml, '#024B37') && str_contains($msgHtml, '#FFCD00') && str_contains($msgHtml, '#C6D53E'),
    'Identidade visual: aplica fonte Calibri e paleta Salinas Gold (#024B37, #FFCD00, #C6D53E)'
);
check(
    Notificacoes::destinatarioAtual((string) $fila[0]['referencia'], $fila[0]['para']) === true,
    'Compatibilidade com fila: Notificacoes::destinatarioAtual aceita referência solicitacao:{id}'
);

// 3. Evento enviada_ssma: notifica usuários ativos com perfil ssma (direto e multiperfil), link para /ssma/{id}
Email::usarFilaMemoria([]);
$solicitacaoRisco = [
    ...$solicitacaoBase,
    'atividade_risco' => 1,
    'status'          => 'aguardando_ssma',
];
$qtdSsma = NotificacoesAcesso::solicitacao('enviada_ssma', $solicitacaoRisco, ['usuario_id' => $idCriador]);
$filaSsma = Email::obterFilaMemoria();
$destSsma = array_column($filaSsma, 'para');
check(
    $qtdSsma === 2
    && in_array('helena.ssma@salinasgold.com.br', $destSsma, true)
    && in_array('marcos.ssma@salinasgold.com.br', $destSsma, true),
    'enviada_ssma: notifica usuários com perfil SSMA (principal e adicional) sem duplicar'
);
check(
    str_contains($filaSsma[0]['corpo_html'], 'https://acesso.salinasgold.com.br/ssma/' . $idSolic),
    'enviada_ssma: inclui link direto para /ssma/{id}'
);

// Se o próprio autor tiver perfil SSMA, não recebe auto-notificação
Email::usarFilaMemoria([]);
$qtdSsmaAuto = NotificacoesAcesso::solicitacao('enviada_ssma', [
    ...$solicitacaoRisco,
    'criado_por' => $idSsma1,
], ['usuario_id' => $idSsma1]);
$filaSsmaAuto = Email::obterFilaMemoria();
check(
    $qtdSsmaAuto === 1 && $filaSsmaAuto[0]['para'] === 'marcos.ssma@salinasgold.com.br',
    'enviada_ssma: exclui o próprio autor quando ele também possui perfil SSMA'
);

// 4. Eventos ssma_apto e ssma_nao_apto: criador + autorizadores da área, nunca o verificador SSMA nem o visitante
Email::usarFilaMemoria([]);
$qtdApto = NotificacoesAcesso::solicitacao('ssma_apto', [
    ...$solicitacaoRisco,
    'status'            => 'pendente',
    'status_observacao' => 'Apto pelo SSMA',
    'verificado_por'    => $idSsma1,
], ['usuario_id' => $idSsma1]);
$filaApto = Email::obterFilaMemoria();
$destApto = array_column($filaApto, 'para');
check(
    $qtdApto === 3
    && in_array('carlos.solicitante@salinasgold.com.br', $destApto, true)
    && in_array('fernanda.gestora@salinasgold.com.br', $destApto, true)
    && in_array('paulo.patrimonial@salinasgold.com.br', $destApto, true)
    && !in_array('helena.ssma@salinasgold.com.br', $destApto, true)
    && !in_array('joao.visitante@externo.com.br', $destApto, true),
    'ssma_apto: envia ao criador e aos autorizadores da área, excluindo verificador SSMA e visitante'
);

Email::usarFilaMemoria([]);
$qtdNaoApto = NotificacoesAcesso::solicitacao('ssma_nao_apto', [
    ...$solicitacaoRisco,
    'status'            => 'recusada',
    'status_observacao' => 'Reprovado pelo SSMA',
    'motivo_recusa'     => 'ASO vencido há 30 dias',
    'verificado_por'    => $idSsma1,
], ['usuario_id' => $idSsma1]);
$filaNaoApto = Email::obterFilaMemoria();
check($qtdNaoApto === 3, 'ssma_nao_apto: envia ao criador e aos autorizadores da área');
check(
    !str_contains($filaNaoApto[0]['corpo_html'], 'ASO vencido') && !str_contains($filaNaoApto[0]['corpo_texto'], 'ASO vencido'),
    'LGPD (ssma_nao_apto): não expõe observação médica/ASO no corpo do e-mail'
);

// 5. Eventos aprovada e recusada: notifica o criador interno, nunca o próprio autorizador nem o visitante externo
Email::usarFilaMemoria([]);
$qtdAprov = NotificacoesAcesso::solicitacao('aprovada', [
    ...$solicitacaoBase,
    'status'         => 'aprovada',
    'autorizado_por' => $idRespArea,
], ['usuario_id' => $idRespArea]);
$filaAprov = Email::obterFilaMemoria();
check(
    $qtdAprov === 1 && $filaAprov[0]['para'] === 'carlos.solicitante@salinasgold.com.br',
    'aprovada: notifica o criador da solicitação quando aprovado por gestor/autorizador'
);

Email::usarFilaMemoria([]);
$qtdAutoAprov = NotificacoesAcesso::solicitacao('aprovada', [
    ...$solicitacaoBase,
    'criado_por'     => $idRespArea,
    'status'         => 'aprovada',
    'autorizado_por' => $idRespArea,
], ['usuario_id' => $idRespArea]);
check($qtdAutoAprov === 0 && count(Email::obterFilaMemoria()) === 0, 'aprovada: não notifica quando o próprio criador aprova sua solicitação');

Email::usarFilaMemoria([]);
$qtdRecusa = NotificacoesAcesso::solicitacao('recusada', [
    ...$solicitacaoBase,
    'status'         => 'recusada',
    'motivo_recusa'  => 'Documento pendente de validação interna',
    'autorizado_por' => $idSegPat1,
], ['usuario_id' => $idSegPat1]);
$filaRecusa = Email::obterFilaMemoria();
check(
    $qtdRecusa === 1
    && $filaRecusa[0]['para'] === 'carlos.solicitante@salinasgold.com.br'
    && !str_contains($filaRecusa[0]['corpo_html'], 'Documento pendente de validação interna'),
    'recusada: notifica o criador sem vazar motivo_recusa no e-mail'
);

// Solicitação pública sem criado_por: aprovada/recusada não envia para contato_email externo
Email::usarFilaMemoria([]);
$qtdPubAprov = NotificacoesAcesso::solicitacao('aprovada', [
    ...$solicitacaoBase,
    'origem'         => 'publica',
    'criado_por'     => null,
    'status'         => 'aprovada',
    'autorizado_por' => $idRespArea,
], ['usuario_id' => $idRespArea]);
check($qtdPubAprov === 0 && count(Email::obterFilaMemoria()) === 0, 'aprovada (origem pública): não envia e-mail para contato_email do visitante');

// 6. Respeito a Email::estaAtivo() e à chave de configuração email_acesso_ativo
Email::usarFilaMemoria([]);
Email::definirAtivo(false);
$qtdEmailOff = NotificacoesAcesso::solicitacao('criada', $solicitacaoBase, ['usuario_id' => $idCriador]);
check($qtdEmailOff === 0 && count(Email::obterFilaMemoria()) === 0, 'Configuração: respeita Email::estaAtivo() === false');
Email::definirAtivo(true);

Email::usarFilaMemoria([]);
$propSettings->setValue(null, [
    'email_ativo'        => '1',
    'email_acesso_ativo' => '0',
]);
check(NotificacoesAcesso::estaAtivo() === false, 'Configuração: Settings email_acesso_ativo=0 desativa notificações de acesso');
$qtdAcessoOff = NotificacoesAcesso::solicitacao('criada', $solicitacaoBase, ['usuario_id' => $idCriador]);
check($qtdAcessoOff === 0 && count(Email::obterFilaMemoria()) === 0, 'Configuração: não enfileira quando email_acesso_ativo=0');

// Quando a chave email_acesso_ativo não existe no Settings, o padrão deve ser ligado (true)
$propSettings->setValue(null, ['email_ativo' => '1']);
check(NotificacoesAcesso::estaAtivo() === true, 'Configuração: email_acesso_ativo assume padrão ligado (1) quando ausente');

// 7. Resiliência: falha na resolução de destinatários nunca interrompe o fluxo
NotificacoesAcesso::definirResolvedor(function (): never {
    throw new RuntimeException('Falha simulada de banco na resolução de destinatários');
});
Email::usarFilaMemoria([]);
$qtdErro = NotificacoesAcesso::solicitacao('criada', $solicitacaoBase, ['usuario_id' => $idCriador]);
check($qtdErro === 0 && count(Email::obterFilaMemoria()) === 0, 'Resiliência: exceção interna é capturada (try/catch) sem quebrar a ação');

// Restaura resolvedor padrão e valida internacionalização (pt-BR, en, es)
NotificacoesAcesso::definirResolvedor($resolvedorPadrao);

Email::usarFilaMemoria(null);
Email::definirAtivo(null);
NotificacoesAcesso::definirAtivo(null);
NotificacoesAcesso::definirResolvedor(null);

echo $falhas === 0
    ? "\nTODOS OS TESTES DE NOTIFICACOES DE ACESSO PASSARAM.\n"
    : "\nFALHAS: {$falhas}\n";
exit($falhas === 0 ? 0 : 1);
