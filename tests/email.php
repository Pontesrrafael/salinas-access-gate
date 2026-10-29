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

/** Simula dois workers com seleção desatualizada; não substitui o teste concorrente em MariaDB. */
final class FilaTestePdo extends PDO
{
    public array $itens;
    public bool $destinatarioPermitido = true;
    public array $consultas = [];
    public function __construct(array $itens) { $this->itens = $itens; }
    public function prepare(string $query, array $options = []): PDOStatement|false { return new FilaTesteStmt($this, $query); }
}
final class FilaTesteStmt extends PDOStatement
{
    private array $rows = [];
    private int $afetados = 0;
    public function __construct(private FilaTestePdo $db, private string $sql) {}
    public function execute(?array $params = null): bool
    {
        $p = $params ?? []; $this->db->consultas[] = [$this->sql, $p];
        if (str_starts_with($this->sql, 'SELECT 1 FROM chamados')) {
            $this->rows = $this->db->destinatarioPermitido ? [[1]] : [];
        } elseif (str_starts_with($this->sql, 'SELECT * FROM email_fila WHERE id')) {
            $r = $this->db->itens[$p[0]] ?? null;
            $this->rows = $r && $r['reserva_token'] === $p[1] ? [$r] : [];
        } elseif (str_starts_with($this->sql, 'SELECT * FROM email_fila')) {
            // Seleção propositalmente desatualizada também oferece itens reservados a outro worker.
            $this->rows = array_values(array_filter($this->db->itens, static fn ($r) => $r['enviado_em'] === null && $r['tentativas'] < Email::MAX_TENTATIVAS && $r['enviar_apos'] <= date('Y-m-d H:i:s')));
        } elseif (str_starts_with($this->sql, 'UPDATE email_fila SET reserva_token')) {
            $r = $this->db->itens[$p[1]] ?? null;
            if ($r && $r['reserva_token'] === null && $r['enviado_em'] === null && $r['tentativas'] < $p[2] && $r['enviar_apos'] <= date('Y-m-d H:i:s')) {
                $this->db->itens[$p[1]]['reserva_token'] = $p[0];
                $this->db->itens[$p[1]]['tentativas']++;
                $this->afetados = 1;
            }
        } elseif (str_starts_with($this->sql, 'UPDATE email_fila SET enviado_em')) {
            if (($this->db->itens[$p[0]]['reserva_token'] ?? null) === $p[1]) {
                $this->db->itens[$p[0]]['enviado_em'] = date('Y-m-d H:i:s');
                $this->db->itens[$p[0]]['reserva_token'] = null;
                $this->afetados = 1;
            }
        } elseif (str_starts_with($this->sql, 'UPDATE email_fila')) {
            if (($this->db->itens[$p[3]]['reserva_token'] ?? null) === $p[4]) {
                $this->db->itens[$p[3]]['tentativas'] = $p[0];
                $this->db->itens[$p[3]]['ultimo_erro'] = $p[1];
                $this->db->itens[$p[3]]['enviar_apos'] = date('Y-m-d H:i:s', time() + $p[2]);
                $this->db->itens[$p[3]]['reserva_token'] = null;
                $this->afetados = 1;
            }
        } elseif (str_starts_with($this->sql, 'DELETE FROM email_fila')) {
            if (($this->db->itens[$p[0]]['reserva_token'] ?? null) === $p[1]) { unset($this->db->itens[$p[0]]); $this->afetados = 1; }
        } else { throw new RuntimeException('Consulta inesperada no teste de fila: ' . $this->sql); }
        return true;
    }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array { return $this->rows; }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed { return array_shift($this->rows) ?? false; }
    public function fetchColumn(int $column = 0): mixed { return $this->rows ? array_values($this->rows[0])[$column] : false; }
    public function rowCount(): int { return $this->afetados; }
}

/** Conversa SMTP determinística, sem socket, DNS ou servidor externo. */
final class SmtpTesteStream
{
    public mixed $context;
    public static array $respostas = [];
    public static string $escrito = '';
    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool { return true; }
    public function stream_read(int $count): string { return array_shift(self::$respostas) ?? ''; }
    public function stream_write(string $data): int { self::$escrito .= $data; return strlen($data); }
    public function stream_eof(): bool { return self::$respostas === []; }
    public function stream_stat(): array { return []; }
    public function stream_set_option(int $option, int $arg1, ?int $arg2): bool { return true; }
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
    Email::definirAtivo(true);
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
    Email::enfileirar('desligado@example.test', 'Não acumular', 'Mensagem descartada');
    expect(count(Email::obterFilaMemoria()) === 1, 'Desligado não enfileira novas mensagens.');
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
    // Revogação vale inclusive se o snapshot do chamado ainda contém o e-mail do ex-atendente.
    Email::usarFilaMemoria([]);
    $residual = array_replace($chamadoBase, ['atendente_id' => 'removido', 'atendente_email' => 'removido@example.test']);
    Notificacoes::chamado('comentario', $residual, ['usuario_id' => 'u-solic']);
    expect(Email::obterFilaMemoria() === [], 'Ex-membro não recebe e-mail por endereço residual.');
    Notificacoes::chamado('fechado', $chamadoBase, ['automatico' => true]);
    expect(str_contains(Email::obterFilaMemoria()[0]['corpo_texto'], 'avaliar o atendimento'), 'Fechamento convida à avaliação.');
    foreach (['en', 'es'] as $idioma) {
        (new ReflectionProperty(\App\Core\I18n::class, 'locale'))->setValue(null, $idioma);
        Email::usarFilaMemoria([]);
        Notificacoes::chamado('solucionado', $chamadoBase, ['usuario_id' => 'u-atend']);
        expect(str_contains(Email::obterFilaMemoria()[0]['corpo_html'], 'lang="' . $idioma . '"'), 'Idioma do HTML corresponde ao conteúdo traduzido.');
    }
    (new ReflectionProperty(\App\Core\I18n::class, 'locale'))->setValue(null, 'pt-BR');
    Email::definirAtivo(false);
    Email::usarFilaMemoria([]);
    Notificacoes::chamado('criado', $chamadoBase, ['usuario_id' => 'u-solic']);
    expect(Email::obterFilaMemoria() === [], 'Notificação desligada não produz backlog.');

    // Exercita o executor de produção, incluindo a reserva e a revalidação de acesso antes do transporte.
    Email::definirAtivo(true);
    Email::usarFilaMemoria([]);
    Email::enfileirar('membro@example.test', 'Reserva de teste', 'Texto', null, 'chamado:22222222-2222-4222-8222-222222222222');
    $registro = Email::obterFilaMemoria()[0] + ['reserva_token' => null, 'reservado_em' => null];
    $pdoTeste = new FilaTestePdo([$registro['id'] => $registro]);
    $propPdo = new ReflectionProperty(\App\Core\Database::class, 'pdo');
    $pdoAnterior = $propPdo->getValue();
    $propPdo->setValue(null, $pdoTeste);
    Email::usarFilaMemoria(null);
    try {
        $disparos = 0;
        Email::definirTransporte(static function () use (&$disparos): void {
            $disparos++;
            expect(Email::processarFila() === ['enviados' => 0, 'falhas' => 0], 'Worker concorrente perde a reserva mesmo com seleção desatualizada.');
        });
        expect(Email::processarFila()['enviados'] === 1 && $disparos === 1, 'Mensagem reservada é enviada uma única vez na simulação.');
        expect($pdoTeste->itens[$registro['id']]['tentativas'] === 1, 'Reserva já contabiliza a tentativa para recuperação de crash.');
        $pdoTeste->itens = [$registro['id'] => $registro];
        $pdoTeste->destinatarioPermitido = false;
        expect(Email::processarFila()['enviados'] === 0 && $disparos === 1 && $pdoTeste->itens === [], 'Mensagem pendente de destinatário revogado é descartada antes do SMTP.');
        $pdoTeste->itens = [$registro['id'] => $registro];
        $pdoTeste->destinatarioPermitido = true;
        Email::definirTransporte(static fn () => throw new RuntimeException('Falha simulada'));
        expect(Email::processarFila()['falhas'] === 1, 'Falha de SMTP é registrada pelo executor com reserva.');
        $falho = $pdoTeste->itens[$registro['id']];
        expect($falho['reserva_token'] === null && $falho['tentativas'] === 1 && $falho['enviar_apos'] > date('Y-m-d H:i:s'), 'Falha libera reserva e agenda nova tentativa sem contar duas vezes.');
    } finally { $propPdo->setValue(null, $pdoAnterior); }

    // Microsoft: todos os HTTP abaixo são falsos, com configuração isolada e restaurada.
    $propConfig = new ReflectionProperty(\App\Core\App::class, 'config');
    $configAnterior = $propConfig->getValue();
    $configGraph = $configAnterior;
    $configGraph['email'] = ['transporte' => 'graph', 'smtp_auth' => 'oauth'];
    $configGraph['microsoft'] = [
        'tenant_id' => '11111111-1111-4111-8111-111111111111',
        'client_id' => '22222222-2222-4222-8222-222222222222',
        'client_secret' => 'segredo-falso+/?microsoft',
        'client_cert_path' => '', 'client_cert_key_path' => '',
    ];
    $propConfig->setValue(null, $configGraph);
    $cfgGraph = Email::cfg();
    $tokenRes = static fn (string $token = 'token-falso-microsoft', int $ttl = 3600): array => [
        'status' => 200, 'headers' => [],
        'body' => json_encode(['access_token' => $token, 'expires_in' => $ttl, 'token_type' => 'Bearer'], JSON_THROW_ON_ERROR),
    ];
    $falhaHttp = static function (callable $acao, int $status): \App\Services\EmailFalhaMicrosoft {
        try { $acao(); } catch (\App\Services\EmailFalhaMicrosoft $e) {
            expect($e->getCode() === $status, 'Código HTTP deve ser preservado no diagnóstico.');
            expect(!str_contains($e->getMessage(), 'SEGREDO_RESPOSTA'), 'Diagnóstico não pode ecoar corpo da Microsoft.');
            return $e;
        }
        throw new RuntimeException('Era esperada uma falha HTTP ' . $status);
    };
    try {
        $requisicoes = [];
        Email::definirTransporte(null);
        Email::definirHttp(static function (string $url, array $headers, string $body, int $timeout) use (&$requisicoes, $tokenRes): array {
            $requisicoes[] = compact('url', 'headers', 'body', 'timeout');
            return str_contains($url, '/token') ? $tokenRes() : ['status' => 202, 'headers' => [], 'body' => ''];
        });
        Email::enviar('destino@example.test', 'Teste Graph', 'Texto alternativo', '<p>Conteúdo HTML</p>');
        Email::enviar('destino@example.test', 'Texto seguro', '<texto & somente>');
        expect(count($requisicoes) === 3, 'Dois envios Graph devem reutilizar um token.');
        parse_str($requisicoes[0]['body'], $form);
        expect($form['grant_type'] === 'client_credentials' && $form['scope'] === Email::SCOPE_GRAPH, 'Token Graph deve usar client credentials e recurso correto.');
        expect($form['client_secret'] === $cfgGraph['microsoft']['client_secret'], 'Segredo com caracteres especiais deve chegar intacto.');
        expect($requisicoes[1]['url'] === 'https://graph.microsoft.com/v1.0/users/noreply%40salinasgold.com.br/sendMail', 'Remetente Graph deve vir da configuração e ser codificado na URL.');
        $graphBody = json_decode($requisicoes[1]['body'], true, 512, JSON_THROW_ON_ERROR);
        expect($graphBody['saveToSentItems'] === false && $graphBody['message']['body'] === ['contentType' => 'HTML', 'content' => '<p>Conteúdo HTML</p>'], 'Graph deve enviar HTML sem cópia em Itens enviados.');
        expect($graphBody['message']['toRecipients'][0]['emailAddress']['address'] === 'destino@example.test', 'Graph deve preservar o destinatário validado.');
        expect(str_contains($requisicoes[2]['body'], '&lt;texto &amp; somente&gt;'), 'Texto sem HTML deve ser escapado no Graph.');
        Email::obterTokenMicrosoft($cfgGraph, Email::SCOPE_SMTP);
        expect(count($requisicoes) === 4, 'Recurso SMTP deve usar cache distinto do Graph.');
        parse_str($requisicoes[3]['body'], $formSmtp);
        expect($formSmtp['scope'] === Email::SCOPE_SMTP, 'Token SMTP deve usar outlook.office365.com/.default.');
        $outroCfg = $cfgGraph;
        $outroCfg['microsoft']['client_secret'] = 'outro-segredo';
        Email::obterTokenMicrosoft($outroCfg, Email::SCOPE_GRAPH);
        expect(count($requisicoes) === 5, 'Troca da credencial deve invalidar a reutilização do token.');
        $propTokens = new ReflectionProperty(Email::class, 'tokens');
        $tokens = $propTokens->getValue();
        foreach ($tokens as &$cache) { $cache['expira'] = time() - 1; }
        unset($cache);
        $propTokens->setValue(null, $tokens);
        Email::obterTokenMicrosoft($cfgGraph, Email::SCOPE_GRAPH);
        expect(count($requisicoes) === 6, 'Token vencido deve ser renovado.');
        $limpo = Email::limparErro('Bearer token-falso-microsoft ' . $cfgGraph['microsoft']['client_secret'] . ' ' . rawurlencode($cfgGraph['microsoft']['client_secret']));
        expect(!str_contains($limpo, 'token-falso-microsoft') && !str_contains($limpo, 'segredo-falso'), 'Tokens e segredos codificados devem ser redigidos.');

        // 401 renova uma única vez; 403/5xx voltam para a fila, sem repetição imediata.
        foreach ([401, 403, 503] as $status) {
            $tokensChamados = 0; $enviosChamados = 0;
            Email::definirHttp(static function (string $url) use ($tokenRes, $status, &$tokensChamados, &$enviosChamados): array {
                if (str_contains($url, '/token')) { $tokensChamados++; return $tokenRes('token-' . $tokensChamados); }
                $enviosChamados++;
                return ['status' => $status, 'headers' => [], 'body' => 'SEGREDO_RESPOSTA'];
            });
            $falhaHttp(static fn () => Email::enviar('destino@example.test', 'Erro Graph', 'Texto'), $status);
            expect($tokensChamados === ($status === 401 ? 2 : 1) && $enviosChamados === $tokensChamados, '401 deve renovar só uma vez; demais falhas não repetem no mesmo envio.');
        }
        $enviosChamados = 0;
        Email::definirHttp(static function (string $url) use ($tokenRes, &$enviosChamados): array {
            if (str_contains($url, '/token')) { return $tokenRes(); }
            return ['status' => ++$enviosChamados === 1 ? 401 : 202, 'headers' => [], 'body' => ''];
        });
        Email::enviar('destino@example.test', 'Renovação', 'Texto');
        expect($enviosChamados === 2, '401 seguido de token renovado deve recuperar o envio.');

        foreach ([['Retry-After' => '900'], ['retry-after' => gmdate(DATE_RFC7231, time() + 900)]] as $headersRetry) {
            Email::definirHttp(static fn (string $url): array => str_contains($url, '/token') ? $tokenRes() : ['status' => 429, 'headers' => $headersRetry, 'body' => 'SEGREDO_RESPOSTA']);
            Email::usarFilaMemoria([]);
            Email::enfileirar('destino@example.test', 'Aguardar limite', 'Texto');
            $inicio = time();
            expect(Email::processarFila() === ['enviados' => 0, 'falhas' => 1], '429 deve falhar sem bloquear o worker.');
            $item = Email::obterFilaMemoria()[0];
            expect(strtotime($item['enviar_apos']) >= $inicio + 899 && $item['tentativas'] === 1, 'Retry-After numérico/data deve adiar a fila além do backoff mínimo.');
            expect(!str_contains($item['ultimo_erro'], 'SEGREDO_RESPOSTA'), 'ultimo_erro nunca deve persistir o corpo retornado.');
        }
        // Também exercita o agendamento de produção (PDO falso, reserva real do executor).
        $pdoTeste = new FilaTestePdo([$registro['id'] => $registro]);
        $propPdo->setValue(null, $pdoTeste);
        Email::usarFilaMemoria(null);
        try {
            $inicio = time();
            expect(Email::processarFila()['falhas'] === 1, '429 deve alcançar o executor de produção.');
            expect(strtotime($pdoTeste->itens[$registro['id']]['enviar_apos']) >= $inicio + 899, 'PDO deve persistir o Retry-After.');
        } finally { $propPdo->setValue(null, $pdoAnterior); }
        Email::definirHttp(static fn (): array => ['status' => 429, 'headers' => ['Retry-After' => '1200'], 'body' => 'SEGREDO_RESPOSTA']);
        $e = $falhaHttp(static fn () => Email::obterTokenMicrosoft($cfgGraph, Email::SCOPE_GRAPH), 429);
        expect($e->retryAfter === 1200, '429 do endpoint de token também deve preservar Retry-After.');
        foreach ([['access_token' => 'token-sem-validade'], ['access_token' => "token\r\ninjetado", 'token_type' => 'Bearer', 'expires_in' => 3600]] as $respostaInvalida) {
            Email::definirHttp(static fn (): array => ['status' => 200, 'headers' => [], 'body' => json_encode($respostaInvalida)]);
            try { Email::obterTokenMicrosoft($cfgGraph, Email::SCOPE_GRAPH); throw new LogicException('Aceitou token inválido.'); }
            catch (RuntimeException $e) { expect($e->getMessage() === 'Resposta de token Microsoft inválida.', 'Token inválido deve falhar sem ecoar o conteúdo.'); }
        }

        // Certificado gerado apenas para o teste, com chave em pasta de cache privada e remoção no finally.
        $temporarios = [];
        try {
            foreach (['conf', 'cert', 'key'] as $tipo) {
                $temporarios[$tipo] = tempnam(\App\Core\App::root('storage/cache'), 'email-' . $tipo . '-');
                expect($temporarios[$tipo] !== false, 'Deve ser possível criar o material temporário de teste.');
            }
            file_put_contents($temporarios['conf'], "[req]\ndistinguished_name=dn\n[dn]\n");
            $opcoes = ['config' => $temporarios['conf'], 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'digest_alg' => 'sha256'];
            $key = openssl_pkey_new($opcoes);
            expect($key !== false, 'Deve gerar chave RSA para testar o certificado.');
            $csr = openssl_csr_new(['commonName' => 'email-test.example.test'], $key, $opcoes);
            expect($csr !== false, 'Deve gerar CSR para testar o certificado.');
            $cert = openssl_csr_sign($csr, null, $key, 1, $opcoes);
            expect($cert !== false && openssl_x509_export($cert, $certPem), 'Deve exportar certificado de teste.');
            expect(openssl_pkey_export($key, $keyPem, null, $opcoes), 'Deve exportar chave de teste.');
            file_put_contents($temporarios['cert'], $certPem);
            file_put_contents($temporarios['key'], $keyPem);
            $cfgCert = $cfgGraph;
            $cfgCert['microsoft']['client_cert_path'] = $temporarios['cert'];
            $cfgCert['microsoft']['client_cert_key_path'] = $temporarios['key'];
            Email::definirHttp(static function (string $url, array $headers, string $body) use ($tokenRes, $certPem, $cfgCert): array {
                parse_str($body, $formCert);
                expect(!isset($formCert['client_secret']) && $formCert['client_assertion_type'] === 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer', 'Certificado deve ter prioridade e não enviar client_secret.');
                [$header64, $claims64, $signature64] = explode('.', $formCert['client_assertion']);
                $decode = static fn (string $v): string => base64_decode(strtr($v, '-_', '+/'), true);
                $headerJwt = json_decode($decode($header64), true, 512, JSON_THROW_ON_ERROR);
                $claims = json_decode($decode($claims64), true, 512, JSON_THROW_ON_ERROR);
                expect($headerJwt['alg'] === 'RS256' && $decode($headerJwt['x5t']) === openssl_x509_fingerprint($certPem, 'sha1', true), 'JWT deve identificar o certificado e algoritmo RS256.');
                expect($claims['aud'] === $url && $claims['iss'] === $cfgCert['microsoft']['client_id'] && $claims['sub'] === $claims['iss'], 'JWT deve ter audience, issuer e subject corretos.');
                expect($claims['exp'] <= time() + 300 && $claims['exp'] > time() && $claims['nbf'] <= time() && $claims['jti'] !== '', 'Assertion deve ter validade curta e identificador único.');
                expect(openssl_verify($header64 . '.' . $claims64, $decode($signature64), $certPem, OPENSSL_ALGO_SHA256) === 1, 'Assinatura RS256 deve ser verificável com o certificado.');
                expect(!str_contains(Email::limparErro($formCert['client_assertion']), $formCert['client_assertion']), 'Assertion não pode aparecer em diagnósticos.');
                return $tokenRes();
            });
            Email::obterTokenMicrosoft($cfgCert, Email::SCOPE_GRAPH);
            $cfgCert['microsoft']['client_cert_key_path'] = '';
            try { Email::obterTokenMicrosoft($cfgCert, Email::SCOPE_GRAPH); throw new LogicException('Aceitou certificado incompleto.'); }
            catch (RuntimeException $e) { expect(str_contains($e->getMessage(), 'dois caminhos'), 'Certificado incompleto não deve fazer fallback silencioso ao segredo.'); }
        } finally {
            foreach ($temporarios as $arquivo) { if (is_string($arquivo) && is_file($arquivo)) { unlink($arquivo); } }
        }

        // SMTP XOAUTH2: testa a conversa inteira e a posição do AUTH após STARTTLS.
        Email::definirHttp(static fn (): array => $tokenRes());
        expect(base64_decode(Email::montarXoauth2('noreply@example.test', 'token-teste'), true) === "user=noreply@example.test\x01auth=Bearer token-teste\x01\x01", 'SASL XOAUTH2 deve ter os separadores corretos.');
        stream_wrapper_register('emailsmtpfixture', SmtpTesteStream::class);
        try {
            SmtpTesteStream::$respostas = ["220 pronto\r\n", "250 ehlo\r\n", "220 tls\r\n", "250 ehlo tls\r\n", "235 autenticado\r\n", "250 remetente\r\n", "250 destinatario\r\n", "354 dados\r\n", "250 aceito\r\n"];
            SmtpTesteStream::$escrito = '';
            $socket = fopen('emailsmtpfixture://teste', 'r+');
            try {
                $cfgSmtp = array_replace($cfgGraph, ['transporte' => 'smtp', 'usuario' => 'noreply@example.test', 'seguranca' => 'tls']);
                Email::dialogarSmtp($socket, 'destino@example.test', 'noreply@example.test', $mime, $cfgSmtp, static fn () => true);
            } finally { fclose($socket); }
            $comandos = SmtpTesteStream::$escrito;
            expect(str_contains($comandos, 'AUTH XOAUTH2 ' . base64_encode("user=noreply@example.test\x01auth=Bearer token-falso-microsoft\x01\x01")), 'Conversa SMTP deve enviar o AUTH XOAUTH2 correto.');
            expect(strpos($comandos, 'STARTTLS') < strpos($comandos, 'AUTH XOAUTH2') && !str_contains($comandos, 'AUTH LOGIN'), 'OAuth só autentica após TLS e nunca faz fallback para LOGIN.');
            SmtpTesteStream::$respostas = ["220 pronto\r\n", "250 ehlo\r\n", "220 tls\r\n", "250 ehlo tls\r\n", "334 usuario\r\n", "334 senha\r\n", "235 autenticado\r\n", "250 remetente\r\n", "250 destinatario\r\n", "354 dados\r\n", "250 aceito\r\n"];
            SmtpTesteStream::$escrito = '';
            $socket = fopen('emailsmtpfixture://teste', 'r+');
            try {
                Email::dialogarSmtp($socket, 'destino@example.test', 'noreply@example.test', $mime, array_replace($cfgSmtp, ['smtp_auth' => 'login', 'senha' => 'senha-smtp-falsa']), static fn () => true);
            } finally { fclose($socket); }
            expect(str_contains(SmtpTesteStream::$escrito, "AUTH LOGIN\r\n" . base64_encode('noreply@example.test') . "\r\n" . base64_encode('senha-smtp-falsa')), 'Conversa legada AUTH LOGIN deve continuar funcionando.');
            SmtpTesteStream::$respostas = ["220 pronto\r\n", "250 ehlo\r\n", "220 tls\r\n", "250 ehlo tls\r\n", '535 token-falso-microsoft ' . base64_encode("user=noreply@example.test\x01auth=Bearer token-falso-microsoft\x01\x01") . "\r\n"];
            $socket = fopen('emailsmtpfixture://teste', 'r+');
            try {
                try { Email::dialogarSmtp($socket, 'destino@example.test', 'noreply@example.test', $mime, $cfgSmtp, static fn () => true); throw new LogicException('Aceitou erro de autenticação.'); }
                catch (RuntimeException $e) { expect(!str_contains($e->getMessage(), 'token-falso-microsoft') && !str_contains($e->getMessage(), base64_encode('user=noreply@example.test')), 'Erro SMTP não pode expor token nem resposta AUTH codificada.'); }
            } finally { fclose($socket); }
            $socket = fopen('emailsmtpfixture://teste', 'r+');
            try {
                try { Email::dialogarSmtp($socket, 'destino@example.test', 'noreply@example.test', $mime, array_replace($cfgSmtp, ['seguranca' => 'nenhuma'])); throw new LogicException('Aceitou OAuth sem TLS.'); }
                catch (RuntimeException $e) { expect(str_contains($e->getMessage(), 'exige TLS'), 'OAuth sem TLS deve ser recusado antes do protocolo.'); }
            } finally { fclose($socket); }
            try {
                Email::enviarSmtp('destino@example.test', 'noreply@example.test', $mime, array_replace($cfgSmtp, ['host' => 'nao-conectar.example.test', 'tls_verify' => false]));
                throw new LogicException('Aceitou OAuth sem validação TLS.');
            } catch (RuntimeException $e) { expect(str_contains($e->getMessage(), 'exige TLS'), 'OAuth sem validação TLS deve falhar antes de abrir uma conexão.'); }
        } finally { stream_wrapper_unregister('emailsmtpfixture'); }
    } finally {
        $propConfig->setValue(null, $configAnterior);
        Email::definirHttp(null);
    }
} finally {
    Email::definirTransporte(null);
    Email::usarFilaMemoria(null);
    Email::definirAtivo(null);
    Notificacoes::definirResolvedor(null);
    Email::definirHttp(null);
}

echo "Email & Notificacoes: MIME multipart/alternative, RFC 2047, recusa de CR/LF, fila com transporte falso e LGPD OK.\n";
echo "Microsoft: token/cache/escopos, Graph, 401/403/429/503, Retry-After na fila e SMTP XOAUTH2 simulados OK.\n";
