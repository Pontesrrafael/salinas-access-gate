<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Services\Email;
use App\Services\Notificacoes;

function expect(bool $ok, string $label): void
{
    if (!$ok) {
        throw new RuntimeException($label);
    }
}

function expectThrows(callable $fn, string $label): void
{
    try {
        $fn();
    } catch (InvalidArgumentException) {
        return;
    }
    throw new RuntimeException($label);
}

try {
    // 1. Codificação de cabeçalho UTF-8 (=?UTF-8?B?...?=)
    $original = 'Chamado #42 — Manutenção Elétrica (NSL)';
    $codificado = Email::codificarCabecalho($original);
    expect(str_starts_with($codificado, '=?UTF-8?B?') && str_ends_with($codificado, '?='), 'Cabeçalho deve usar formato RFC 2047 =?UTF-8?B?...?=.');
    $miolo = substr($codificado, strlen('=?UTF-8?B?'), -2);
    expect(base64_decode($miolo, true) === $original, 'Base64 do cabeçalho deve decodificar exatamente para o texto UTF-8 original.');

    // 2. Recusa de injeção de cabeçalho (\r, \n, \0) em destinatário, assunto e referência
    expectThrows(
        static fn () => Email::validarEndereco("colaborador@salinasgold.com.br\r\nBcc: invasor@exemplo.com"),
        'Deve recusar CR/LF no endereço de destino.'
    );
    expectThrows(
        static fn () => Email::validarEndereco("colaborador@salinasgold.com.br\nCc: invasor@exemplo.com"),
        'Deve recusar LF no endereço de destino.'
    );
    expectThrows(
        static fn () => Email::validarAssunto("Assunto legítimo\r\nX-Injected: true"),
        'Deve recusar CR/LF no assunto.'
    );
    expectThrows(
        static fn () => Email::codificarCabecalho("Linha 1\nLinha 2"),
        'Deve recusar quebra de linha ao codificar cabeçalho.'
    );

    // 3. Montagem da mensagem MIME multipart/alternative (texto + HTML) UTF-8
    $mime = Email::montarMime(
        'solicitante@salinasgold.com.br',
        'Chamado #105 — Solucionado',
        "Olá,\nSeu chamado #105 foi solucionado.\nAcesse: https://accessgate.salinasgold.com.br/chamados/105",
        '<p>Olá, seu chamado <strong>#105</strong> foi solucionado.</p>',
        [
            'de_email' => 'noreply@salinasgold.com.br',
            'de_nome'  => 'Salinas Access Gate',
        ],
        'fronteira_teste_sag'
    );
    expect(str_contains($mime, 'MIME-Version: 1.0'), 'MIME deve conter cabeçalho MIME-Version: 1.0.');
    expect(str_contains($mime, 'Content-Type: multipart/alternative; boundary="fronteira_teste_sag"'), 'MIME deve ser multipart/alternative com o boundary informado.');
    expect(str_contains($mime, 'Subject: ' . Email::codificarCabecalho('Chamado #105 — Solucionado')), 'MIME deve conter Subject codificado em =?UTF-8?B?.');
    expect(str_contains($mime, 'From: ' . Email::codificarCabecalho('Salinas Access Gate') . ' <noreply@salinasgold.com.br>'), 'MIME deve conter From codificado com e-mail entre sinais.');
    expect(str_contains($mime, 'To: <solicitante@salinasgold.com.br>'), 'MIME deve conter destinatário no cabeçalho To.');
    expect(str_contains($mime, "Content-Type: text/plain; charset=UTF-8"), 'MIME deve conter parte text/plain UTF-8.');
    expect(str_contains($mime, "Content-Type: text/html; charset=UTF-8"), 'MIME deve conter parte text/html UTF-8.');
    expect(str_contains($mime, '--fronteira_teste_sag--'), 'MIME deve encerrar com boundary final.');

    // 4. Fila em memória com transporte falso, bloqueio quando email_ativo != '1', tentativas crescentes e limpeza de senha
    Email::usarFilaMemoria([]);
    $enviadosTransporte = [];
    Email::definirTransporte(static function (string $para, string $assunto, string $texto, ?string $html, string $mimeMsg) use (&$enviadosTransporte): void {
        $enviadosTransporte[] = compact('para', 'assunto', 'texto', 'html', 'mimeMsg');
    });

    Email::enfileirar(
        'atendente@salinasgold.com.br',
        'Chamado #1 — Novo chamado',
        'Texto puro do chamado #1',
        '<p>HTML do chamado #1</p>',
        'chamado:11111111-1111-4111-8111-111111111111'
    );

    // 4.1. Com email_ativo = false, nada é enviado
    Email::definirAtivo(false);
    $resInativo = Email::processarFila();
    expect($resInativo['enviados'] === 0 && $resInativo['falhas'] === 0, 'Nada deve ser enviado quando email_ativo != 1.');
    expect(count($enviadosTransporte) === 0, 'Transporte não deve ser acionado quando email_ativo está desligado.');

    // 4.2. Com email_ativo = true, envia normalmente e grava enviado_em
    Email::definirAtivo(true);
    $resAtivo = Email::processarFila();
    expect($resAtivo['enviados'] === 1 && $resAtivo['falhas'] === 0, 'Fila ativa deve enviar o item pendente.');
    expect(count($enviadosTransporte) === 1 && $enviadosTransporte[0]['para'] === 'atendente@salinasgold.com.br', 'Transporte falso deve receber o destinatário correto.');
    $fila = Email::obterFilaMemoria();
    expect($fila[0]['enviado_em'] !== null && $fila[0]['ultimo_erro'] === null, 'Item enviado deve ter enviado_em preenchido e ultimo_erro nulo.');

    // 4.3. Falha no transporte: incrementa tentativas, aplica espera crescente e oculta senha do ultimo_erro
    $senhaSecreta = 'S3nhaSuperSecreta!2026';
    $erroNet = 'Falha AUTH LOGIN com segredo ' . $senhaSecreta . ' e ' . base64_encode($senhaSecreta);
    $erroLimpo = Email::limparErro($erroNet, $senhaSecreta);
    expect(!str_contains($erroLimpo, $senhaSecreta) && !str_contains($erroLimpo, base64_encode($senhaSecreta)), 'limparErro() deve remover a senha em texto puro e em Base64.');
    expect(str_contains($erroLimpo, '[redigido]'), 'limparErro() deve marcar o trecho sensível como [redigido].');

    expect(
        Email::atrasoTentativaSegundos(1) < Email::atrasoTentativaSegundos(2)
        && Email::atrasoTentativaSegundos(2) < Email::atrasoTentativaSegundos(3)
        && Email::atrasoTentativaSegundos(3) < Email::atrasoTentativaSegundos(4)
        && Email::atrasoTentativaSegundos(4) < Email::atrasoTentativaSegundos(5),
        'Atraso entre tentativas deve ser estritamente crescente.'
    );

    Email::usarFilaMemoria([]);
    Email::definirTransporte(static function () use ($senhaSecreta): void {
        throw new RuntimeException('Erro SMTP autenticando com ' . $senhaSecreta);
    });
    Email::enfileirar('falha@salinasgold.com.br', 'Teste de falha', 'Corpo texto', '<p>Corpo</p>', 'chamado:teste');
    $resFalha = Email::processarFila();
    expect($resFalha['enviados'] === 0 && $resFalha['falhas'] === 1, 'Falha no envio deve contabilizar 1 falha.');
    $itemFalho = Email::obterFilaMemoria()[0];
    expect($itemFalho['tentativas'] === 1 && $itemFalho['enviado_em'] === null, 'Tentativas deve subir para 1 e enviado_em continuar nulo.');

    // 5. Notificacoes::chamado() — regras de destinatários, exclusão do autor e minimização LGPD
    Email::usarFilaMemoria([]);
    Notificacoes::definirResolvedor(static function (string $tipo, mixed $arg): mixed {
        $usuarios = [
            'u-solic' => ['id' => 'u-solic', 'nome' => 'Maria Solicitante', 'email' => 'maria@salinasgold.com.br'],
            'u-atend' => ['id' => 'u-atend', 'nome' => 'João Atendente',   'email' => 'joao@salinasgold.com.br'],
            'u-gest'  => ['id' => 'u-gest',  'nome' => 'Carlos Gestor',    'email' => 'carlos@salinasgold.com.br'],
        ];
        if ($tipo === 'usuario') {
            return $usuarios[(string) $arg] ?? null;
        }
        if ($tipo === 'equipe') {
            return [$usuarios['u-atend'], $usuarios['u-gest']];
        }
        return null;
    });

    $chamadoBase = [
        'id'                => '22222222-2222-4222-8222-222222222222',
        'numero'            => 77,
        'area_id'           => 'e4ef6cc8-aafe-4ad4-80be-a2420a856802',
        'area_nome'         => 'Facilities',
        'categoria_id'      => '33333333-3333-4333-8333-333333333333',
        'categoria_nome'    => 'Climatização',
        'titulo'            => 'Ar-condicionado pingando',
        'descricao'         => 'DADO SENSIVEL NA DESCRICAO QUE NAO DEVE IR POR EMAIL',
        'beneficiario_nome' => 'TERCEIRO SENSIVEL LGPD',
        'solicitante_id'    => 'u-solic',
        'atendente_id'      => 'u-atend',
        'status'            => 'novo',
        'prioridade'        => 3,
    ];

    // 5.1. Criado por membro da própria equipe (u-atend): avisa apenas o outro membro (u-gest), nunca quem fez a ação
    Notificacoes::chamado('criado', $chamadoBase, ['usuario_id' => 'u-atend']);
    $filaNotif = Email::obterFilaMemoria();
    expect(count($filaNotif) === 1 && $filaNotif[0]['para'] === 'carlos@salinasgold.com.br', 'Evento criado não deve notificar o próprio autor da ação.');
    expect(!str_contains((string) $filaNotif[0]['corpo_texto'], 'DADO SENSIVEL') && !str_contains((string) $filaNotif[0]['corpo_html'], 'TERCEIRO SENSIVEL'), 'E-mail não deve conter descrição detalhada nem nome de beneficiário (LGPD).');
    expect(str_contains((string) $filaNotif[0]['corpo_texto'], '/chamados/22222222-2222-4222-8222-222222222222'), 'E-mail deve conter link absoluto para o chamado.');

    // 5.2. Nota interna nunca gera e-mail
    Email::usarFilaMemoria([]);
    Notificacoes::chamado('comentario', $chamadoBase, ['usuario_id' => 'u-atend', 'tipo' => 'interno', 'texto' => 'Nota interna secreta']);
    expect(count(Email::obterFilaMemoria()) === 0, 'Nota interna jamais deve enfileirar e-mail.');

    // 5.3. Comentário público do atendente avisa a solicitante; comentário da solicitante avisa o atendente
    Notificacoes::chamado('comentario', $chamadoBase, ['usuario_id' => 'u-atend', 'tipo' => 'comentario']);
    expect(count(Email::obterFilaMemoria()) === 1 && Email::obterFilaMemoria()[0]['para'] === 'maria@salinasgold.com.br', 'Comentário público do atendente deve avisar o solicitante.');

    Email::usarFilaMemoria([]);
    Notificacoes::chamado('comentario', $chamadoBase, ['usuario_id' => 'u-solic', 'tipo' => 'comentario']);
    expect(count(Email::obterFilaMemoria()) === 1 && Email::obterFilaMemoria()[0]['para'] === 'joao@salinasgold.com.br', 'Comentário público do solicitante deve avisar o atendente.');
} finally {
    Email::definirTransporte(null);
    Email::usarFilaMemoria(null);
    Email::definirAtivo(null);
    Notificacoes::definirResolvedor(null);
}

echo "Email & Notificacoes: MIME multipart/alternative, RFC 2047, recusa de CR/LF, fila com transporte falso e LGPD OK.\n";
