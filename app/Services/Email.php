<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Core\Database;
use App\Core\Uuid;
use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Cliente SMTP mínimo em PHP puro e fila assíncrona de e-mails (tabela email_fila).
 *
 * Regras de segurança e operação:
 * - Rejeita \r, \n e \0 em destinatário, remetente, assunto e referência (prevenção de injeção de cabeçalho SMTP/MIME).
 * - Cabeçalhos UTF-8 codificados em Base64 (RFC 2047: =?UTF-8?B?...?=).
 * - Mensagem MIME multipart/alternative (text/plain + text/html) em UTF-8.
 * - Nada é enviado se configuracoes.email_ativo != '1'.
 * - Até 5 tentativas com espera crescente; ultimo_erro nunca armazena a senha SMTP.
 */
final class Email
{
    public const MAX_TENTATIVAS = 5;

    /** @var null|callable(string,string,string,?string,string,array):void */
    private static $transporte = null;

    /** @var array<int, array<string, mixed>>|null */
    private static ?array $filaMemoria = null;

    private static ?bool $ativoOverride = null;

    /**
     * Injeta um transporte customizado para testes sem rede.
     * Assinatura do callback: fn(string $para, string $assunto, string $texto, ?string $html, string $mime, array $cfg): void
     */
    public static function definirTransporte(?callable $transporte): void
    {
        self::$transporte = $transporte;
    }

    /** Ativa fila em memória para testes unitários sem banco de dados. */
    public static function usarFilaMemoria(?array $inicial = []): void
    {
        self::$filaMemoria = $inicial;
    }

    /** @return array<int, array<string, mixed>> */
    public static function obterFilaMemoria(): array
    {
        return self::$filaMemoria ?? [];
    }

    /** Sobrescreve a flag email_ativo durante testes sem banco; passe null para restaurar leitura de Settings. */
    public static function definirAtivo(?bool $ativo): void
    {
        self::$ativoOverride = $ativo;
    }

    public static function estaAtivo(): bool
    {
        if (self::$ativoOverride !== null) {
            return self::$ativoOverride;
        }
        return Settings::bool('email_ativo');
    }

    /** @return array{host:string,porta:int,usuario:string,senha:string,de_email:string,de_nome:string,seguranca:string,timeout:int,tls_verify:bool} */
    public static function cfg(): array
    {
        $c = App::config('smtp');
        return [
            'host'       => trim((string) ($c['host'] ?? '')),
            'porta'      => (int) ($c['porta'] ?? 587),
            'usuario'    => trim((string) ($c['usuario'] ?? '')),
            'senha'      => (string) ($c['senha'] ?? ''),
            'de_email'   => trim((string) ($c['de_email'] ?? 'noreply@salinasgold.com.br')),
            'de_nome'    => trim((string) ($c['de_nome'] ?? 'Salinas Access Gate')),
            'seguranca'  => strtolower(trim((string) ($c['seguranca'] ?? 'tls'))),
            'timeout'    => max(1, min(60, (int) ($c['timeout'] ?? 10))),
            'tls_verify' => (bool) ($c['tls_verify'] ?? true),
        ];
    }

    /** Garante ausência de CR/LF/NUL para impedir injeção de cabeçalho SMTP/MIME. */
    public static function semQuebraCabecalho(string $valor, string $campo = 'cabeçalho'): string
    {
        if (preg_match('/[\r\n\0]/', $valor) === 1) {
            throw new InvalidArgumentException("Quebra de linha não permitida no campo {$campo}.");
        }
        return $valor;
    }

    public static function validarEndereco(string $email): string
    {
        self::semQuebraCabecalho($email, 'destinatário');
        $limpo = trim($email);
        if ($limpo === '' || mb_strlen($limpo) > 190 || filter_var($limpo, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Endereço de e-mail inválido.');
        }
        return $limpo;
    }

    public static function validarAssunto(string $assunto): string
    {
        self::semQuebraCabecalho($assunto, 'assunto');
        $limpo = trim($assunto);
        if ($limpo === '') {
            throw new InvalidArgumentException('Assunto do e-mail não pode ser vazio.');
        }
        return mb_substr($limpo, 0, 200);
    }

    /** Codifica cabeçalho UTF-8 no formato RFC 2047 (=?UTF-8?B?...?=). */
    public static function codificarCabecalho(string $valor): string
    {
        self::semQuebraCabecalho($valor, 'cabeçalho');
        return '=?UTF-8?B?' . base64_encode($valor) . '?=';
    }

    /** Espera crescente entre tentativas (1ª=1 min, 2ª=5 min, 3ª=15 min, 4ª=60 min, 5ª=120 min). */
    public static function atrasoTentativaSegundos(int $tentativa): int
    {
        return match (max(1, $tentativa)) {
            1       => 60,
            2       => 300,
            3       => 900,
            4       => 3600,
            default => 7200,
        };
    }

    /** Remove a senha SMTP (em texto puro ou Base64) antes de persistir ultimo_erro. */
    public static function limparErro(string $erro, ?string $senha = null): string
    {
        $senhaReal = $senha ?? self::cfg()['senha'];
        $limpo = $erro;
        if ($senhaReal !== '') {
            $limpo = str_replace([$senhaReal, base64_encode($senhaReal)], '[redigido]', $limpo);
        }
        $limpo = trim((string) preg_replace('/\s+/', ' ', $limpo));
        return mb_substr($limpo, 0, 500);
    }

    /**
     * Monta mensagem MIME multipart/alternative (text/plain + text/html) em UTF-8.
     */
    public static function montarMime(
        string $para,
        string $assunto,
        string $texto,
        ?string $html = null,
        array $cfg = [],
        ?string $boundary = null
    ): string {
        $cfg = array_merge(self::cfg(), $cfg);
        $paraValido = self::validarEndereco($para);
        $assuntoValido = self::validarAssunto($assunto);
        $deEmail = self::validarEndereco((string) ($cfg['de_email'] ?: 'noreply@salinasgold.com.br'));
        $deNome = self::semQuebraCabecalho(trim((string) ($cfg['de_nome'] ?? 'Salinas Access Gate')), 'remetente');

        $fronteira = $boundary !== null && $boundary !== ''
            ? self::semQuebraCabecalho($boundary, 'boundary')
            : '=_sag_' . bin2hex(random_bytes(12));

        $dominio = substr((string) strrchr($deEmail, '@'), 1) ?: 'salinasgold.local';
        $remetente = $deNome !== ''
            ? self::codificarCabecalho($deNome) . " <{$deEmail}>"
            : "<{$deEmail}>";

        $textoNorm = str_replace(["\r\n", "\r"], "\n", $texto);
        $textoCrlf = str_replace("\n", "\r\n", $textoNorm);

        $htmlConteudo = ($html !== null && trim($html) !== '')
            ? $html
            : '<!DOCTYPE html><html lang="pt-BR"><body><pre>'
              . htmlspecialchars($textoNorm, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
              . '</pre></body></html>';
        $htmlNorm = str_replace(["\r\n", "\r"], "\n", $htmlConteudo);
        $htmlCrlf = str_replace("\n", "\r\n", $htmlNorm);

        $cabecalhos = [
            'Date: ' . date(DATE_RFC2822),
            'From: ' . $remetente,
            'To: <' . $paraValido . '>',
            'Subject: ' . self::codificarCabecalho($assuntoValido),
            'Message-ID: <' . Uuid::v4() . '@' . $dominio . '>',
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $fronteira . '"',
            'Auto-Submitted: auto-generated',
            'X-Auto-Response-Suppress: All',
        ];

        $corpo = [
            '--' . $fronteira,
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: quoted-printable',
            '',
            quoted_printable_encode($textoCrlf),
            '--' . $fronteira,
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: quoted-printable',
            '',
            quoted_printable_encode($htmlCrlf),
            '--' . $fronteira . '--',
            '',
        ];

        return implode("\r\n", $cabecalhos) . "\r\n\r\n" . implode("\r\n", $corpo);
    }

    /**
     * Enfileira um e-mail na tabela email_fila (nunca dispara conexão SMTP na requisição web).
     */
    public static function enfileirar(
        string $para,
        string $assunto,
        string $texto,
        ?string $html = null,
        ?string $referencia = null
    ): void {
        if (!self::estaAtivo()) { return; }
        $paraValido = self::validarEndereco($para);
        $assuntoValido = self::validarAssunto($assunto);
        $refValida = null;
        if ($referencia !== null && trim($referencia) !== '') {
            self::semQuebraCabecalho($referencia, 'referencia');
            $refValida = mb_substr(trim($referencia), 0, 80);
        }

        $agora = date('Y-m-d H:i:s');
        $registro = [
            'id'          => Uuid::v4(),
            'para'        => $paraValido,
            'assunto'     => $assuntoValido,
            'corpo_texto' => $texto,
            'corpo_html'  => $html,
            'referencia'  => $refValida,
            'tentativas'  => 0,
            'ultimo_erro' => null,
            'enviar_apos' => $agora,
            'enviado_em'  => null,
            'created_at'  => $agora,
        ];

        if (self::$filaMemoria !== null) {
            self::$filaMemoria[] = $registro;
            return;
        }

        Database::insert('email_fila', $registro);
    }

    /**
     * Processa itens pendentes da fila de e-mails.
     * @return array{enviados:int, falhas:int}
     */
    public static function processarFila(int $limite = 50): array
    {
        $limite = max(1, min(500, $limite));
        if (!self::estaAtivo()) {
            return ['enviados' => 0, 'falhas' => 0];
        }

        if (self::$filaMemoria !== null) {
            return self::processarFilaMemoria($limite);
        }

        $pendentes = Database::all(
            'SELECT * FROM email_fila
              WHERE enviado_em IS NULL
                AND tentativas < ?
                AND enviar_apos <= NOW()
                AND (reserva_token IS NULL OR reservado_em < (NOW() - INTERVAL 30 MINUTE))
              ORDER BY enviar_apos ASC, created_at ASC
              LIMIT ' . $limite,
            [self::MAX_TENTATIVAS]
        );

        $enviados = 0;
        $falhas = 0;
        foreach ($pendentes as $item) {
            // Compare-and-set: só um worker ganha a reserva; cada item é reservado imediatamente antes do envio.
            $token = Uuid::v4();
            $reservou = Database::run('UPDATE email_fila SET reserva_token = ?, reservado_em = NOW(), tentativas = tentativas + 1 WHERE id = ? AND enviado_em IS NULL AND tentativas < ? AND enviar_apos <= NOW() AND (reserva_token IS NULL OR reservado_em < (NOW() - INTERVAL 30 MINUTE))', [$token, $item['id'], self::MAX_TENTATIVAS])->rowCount();
            if ($reservou !== 1) { continue; }
            $item = Database::one('SELECT * FROM email_fila WHERE id = ? AND reserva_token = ?', [$item['id'], $token]);
            if (!$item) { continue; }
            try {
                if (!Notificacoes::destinatarioAtual((string) ($item['referencia'] ?? ''), (string) $item['para'])) {
                    Database::run('DELETE FROM email_fila WHERE id = ? AND reserva_token = ?', [$item['id'], $token]);
                    continue;
                }
                self::enviar(
                    (string) $item['para'],
                    (string) $item['assunto'],
                    (string) $item['corpo_texto'],
                    isset($item['corpo_html']) ? (string) $item['corpo_html'] : null
                );
                Database::run(
                    'UPDATE email_fila SET enviado_em = NOW(), ultimo_erro = NULL, reserva_token = NULL, reservado_em = NULL WHERE id = ? AND reserva_token = ?',
                    [$item['id'], $token]
                );
                $enviados++;
            } catch (Throwable $e) {
                $tentativas = (int) $item['tentativas'];
                $atraso = self::atrasoTentativaSegundos($tentativas);
                $erro = self::limparErro($e->getMessage());
                Database::run(
                    'UPDATE email_fila
                        SET tentativas = ?,
                            ultimo_erro = ?,
                            enviar_apos = (NOW() + INTERVAL ? SECOND), reserva_token = NULL, reservado_em = NULL
                      WHERE id = ? AND reserva_token = ?',
                    [$tentativas, $erro, $atraso, $item['id'], $token]
                );
                $falhas++;
            }
        }

        return ['enviados' => $enviados, 'falhas' => $falhas];
    }

    /**
     * Processa a fila em memória (usado pelos testes automatizados sem banco).
     * @return array{enviados:int, falhas:int}
     */
    private static function processarFilaMemoria(int $limite, ?DateTimeImmutable $agora = null): array
    {
        $agora ??= new DateTimeImmutable();
        $agoraStr = $agora->format('Y-m-d H:i:s');
        $enviados = 0;
        $falhas = 0;
        $processados = 0;

        foreach (self::$filaMemoria as &$item) {
            if ($processados >= $limite) {
                break;
            }
            if (($item['enviado_em'] ?? null) !== null) {
                continue;
            }
            if (((int) ($item['tentativas'] ?? 0)) >= self::MAX_TENTATIVAS) {
                continue;
            }
            if (((string) ($item['enviar_apos'] ?? '')) > $agoraStr) {
                continue;
            }
            $processados++;
            try {
                self::enviar(
                    (string) $item['para'],
                    (string) $item['assunto'],
                    (string) $item['corpo_texto'],
                    isset($item['corpo_html']) ? (string) $item['corpo_html'] : null
                );
                $item['enviado_em'] = $agoraStr;
                $item['ultimo_erro'] = null;
                $enviados++;
            } catch (Throwable $e) {
                $tentativas = ((int) ($item['tentativas'] ?? 0)) + 1;
                $atraso = self::atrasoTentativaSegundos($tentativas);
                $item['tentativas'] = $tentativas;
                $item['ultimo_erro'] = self::limparErro($e->getMessage());
                $item['enviar_apos'] = $agora->modify('+' . $atraso . ' seconds')->format('Y-m-d H:i:s');
                $falhas++;
            }
        }
        unset($item);

        return ['enviados' => $enviados, 'falhas' => $falhas];
    }

    /**
     * Envia uma mensagem imediatamente usando o transporte injetado (testes) ou cliente SMTP nativo.
     */
    public static function enviar(
        string $para,
        string $assunto,
        string $texto,
        ?string $html = null,
        ?array $cfg = null
    ): void {
        $cfgFinal = array_merge(self::cfg(), $cfg ?? []);
        $paraValido = self::validarEndereco($para);
        $assuntoValido = self::validarAssunto($assunto);
        $deEmail = self::validarEndereco((string) ($cfgFinal['de_email'] ?: 'noreply@salinasgold.com.br'));
        $mime = self::montarMime($paraValido, $assuntoValido, $texto, $html, $cfgFinal);

        if (self::$transporte !== null) {
            (self::$transporte)($paraValido, $assuntoValido, $texto, $html, $mime, $cfgFinal);
            return;
        }

        self::enviarSmtp($paraValido, $deEmail, $mime, $cfgFinal);
    }

    /**
     * Abre conexão socket e executa a conversa SMTP (EHLO, STARTTLS, AUTH LOGIN, MAIL FROM, RCPT TO, DATA, QUIT).
     */
    public static function enviarSmtp(string $para, string $deEmail, string $mime, array $cfg): void
    {
        $host = trim((string) ($cfg['host'] ?? ''));
        $porta = (int) ($cfg['porta'] ?? 587);
        $seguranca = strtolower(trim((string) ($cfg['seguranca'] ?? 'tls')));
        $timeout = max(1, min(60, (int) ($cfg['timeout'] ?? 10)));
        $tlsVerify = (bool) ($cfg['tls_verify'] ?? true);

        if ($host === '' || $porta <= 0 || $porta > 65535) {
            throw new RuntimeException('Servidor SMTP não configurado (SMTP_HOST/SMTP_PORT).');
        }
        if (!in_array($seguranca, ['tls', 'ssl', 'nenhuma'], true)) {
            throw new RuntimeException('Modo de segurança SMTP inválido (use tls, ssl ou nenhuma).');
        }

        $esquema = $seguranca === 'ssl' ? 'tls' : 'tcp';
        $contexto = stream_context_create([
            'ssl' => [
                'verify_peer'       => $tlsVerify,
                'verify_peer_name'  => $tlsVerify,
                'allow_self_signed' => !$tlsVerify,
                'SNI_enabled'       => true,
                'peer_name'         => $host,
            ],
        ]);

        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client(
            "{$esquema}://{$host}:{$porta}",
            $errno,
            $errstr,
            $timeout,
            STREAM_CLIENT_CONNECT,
            $contexto
        );
        if (!is_resource($socket)) {
            throw new RuntimeException(self::limparErro("Falha ao conectar ao SMTP {$host}:{$porta} ({$errno}: {$errstr})", (string) ($cfg['senha'] ?? '')));
        }

        stream_set_timeout($socket, $timeout);

        try {
            self::dialogarSmtp($socket, $para, $deEmail, $mime, $cfg);
        } catch (Throwable $e) {
            throw new RuntimeException(self::limparErro($e->getMessage(), (string) ($cfg['senha'] ?? '')), 0, $e);
        } finally {
            @fclose($socket);
        }
    }

    /**
     * Executa o protocolo SMTP sobre um stream aberto (permite teste de protocolo com stream em memória/par).
     * @param resource $socket
     */
    public static function dialogarSmtp(
        $socket,
        string $para,
        string $deEmail,
        string $mime,
        array $cfg,
        ?callable $habilitarTls = null
    ): void {
        $seguranca = strtolower(trim((string) ($cfg['seguranca'] ?? 'tls')));
        $usuario = trim((string) ($cfg['usuario'] ?? ''));
        $senha = (string) ($cfg['senha'] ?? '');
        $ehloHost = parse_url((string) (App::config('app')['url'] ?? ''), PHP_URL_HOST) ?: 'salinasgold.local';
        $ehloHost = preg_replace('/[^a-zA-Z0-9.\-]/', '', (string) $ehloHost) ?: 'salinasgold.local';

        self::esperarCodigo($socket, [220], 'Saudação SMTP');
        self::enviarComando($socket, "EHLO {$ehloHost}", [250], 'EHLO inicial');

        if ($seguranca === 'tls') {
            self::enviarComando($socket, 'STARTTLS', [220], 'STARTTLS');
            $metodoCrypto = STREAM_CRYPTO_METHOD_TLS_CLIENT;
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
                $metodoCrypto |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
            }
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
                $metodoCrypto |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
            }
            $tlsOk = $habilitarTls !== null
                ? (bool) $habilitarTls($socket)
                : @stream_socket_enable_crypto($socket, true, $metodoCrypto);
            if ($tlsOk !== true) {
                throw new RuntimeException('Não foi possível negociar STARTTLS com o servidor SMTP.');
            }
            self::enviarComando($socket, "EHLO {$ehloHost}", [250], 'EHLO pós-STARTTLS');
        }

        if ($usuario !== '') {
            self::enviarComando($socket, 'AUTH LOGIN', [334], 'AUTH LOGIN');
            self::enviarComando($socket, base64_encode($usuario), [334], 'Usuário SMTP');
            self::enviarComando($socket, base64_encode($senha), [235], 'Autenticação SMTP');
        }

        self::enviarComando($socket, "MAIL FROM:<{$deEmail}>", [250], 'MAIL FROM');
        self::enviarComando($socket, "RCPT TO:<{$para}>", [250, 251], 'RCPT TO');
        self::enviarComando($socket, 'DATA', [354], 'DATA');

        // Dot-stuffing RFC 5321 (linhas iniciadas por "." ganham um ponto extra)
        $mimeNorm = str_replace(["\r\n", "\r"], "\n", $mime);
        $mimeStuffed = (string) preg_replace('/^\./m', '..', $mimeNorm);
        $payload = str_replace("\n", "\r\n", $mimeStuffed) . "\r\n.\r\n";

        self::escreverStream($socket, $payload);
        self::esperarCodigo($socket, [250], 'Envio do corpo DATA');

        @fwrite($socket, "QUIT\r\n");
    }

    /**
     * @param resource $socket
     * @param int[] $codigosEsperados
     */
    private static function enviarComando($socket, string $comando, array $codigosEsperados, string $etapa): string
    {
        self::semQuebraCabecalho($comando, $etapa);
        self::escreverStream($socket, $comando . "\r\n");
        return self::esperarCodigo($socket, $codigosEsperados, $etapa);
    }

    /** @param resource $socket */
    private static function escreverStream($socket, string $dados): void
    {
        $total = strlen($dados);
        $escrito = 0;
        while ($escrito < $total) {
            $n = @fwrite($socket, substr($dados, $escrito));
            if ($n === false || $n === 0) {
                throw new RuntimeException('Falha de escrita na conexão SMTP.');
            }
            $escrito += $n;
        }
    }

    /**
     * @param resource $socket
     * @param int[] $codigosEsperados
     */
    private static function esperarCodigo($socket, array $codigosEsperados, string $etapa): string
    {
        $resposta = '';
        $codigo = 0;
        while (($linha = @fgets($socket, 1024)) !== false) {
            $resposta .= $linha;
            if (preg_match('/^(\d{3})([\s\-])/', $linha, $m) === 1) {
                $codigo = (int) $m[1];
                if ($m[2] === ' ') {
                    break;
                }
            } else {
                break;
            }
        }

        $meta = stream_get_meta_data($socket);
        if (!empty($meta['timed_out'])) {
            throw new RuntimeException("Tempo limite esgotado na etapa SMTP: {$etapa}.");
        }

        if (!in_array($codigo, $codigosEsperados, true)) {
            $resumo = trim((string) preg_replace('/\s+/', ' ', $resposta));
            throw new RuntimeException("Erro SMTP em {$etapa} (código {$codigo}): {$resumo}");
        }

        return $resposta;
    }
}
