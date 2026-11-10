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
    public const SCOPE_GRAPH = 'https://graph.microsoft.com/.default';
    public const SCOPE_SMTP = 'https://outlook.office365.com/.default';

    /** @var null|callable(string,array,string,int):array{status:int,headers:array,body:string} */
    private static $http = null;
    /** Cache somente no processo; nunca grava tokens no disco ou no banco. */
    private static array $tokens = [];
    private static array $segredos = [];

    /** Injeta HTTP falso e descarta o cache para isolar testes. */
    public static function definirHttp(?callable $http): void
    {
        self::$http = $http;
        self::$tokens = [];
        self::$segredos = [];
    }

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

    /** @return array<string,mixed> */
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
            'transporte' => (string) (App::config('email')['transporte'] ?? 'smtp'),
            'smtp_auth'  => (string) (App::config('email')['smtp_auth'] ?? 'login'),
            'microsoft'  => App::config('microsoft'),
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
        $cfg = self::cfg();
        $limpo = $erro;
        $segredos = array_merge(self::$segredos, [$senha ?? '', $cfg['senha'], $cfg['microsoft']['client_secret'] ?? '']);
        usort($segredos, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        foreach ($segredos as $segredo) {
            if ($segredo === '') { continue; }
            $limpo = str_replace([$segredo, base64_encode($segredo), rawurlencode($segredo), urlencode($segredo)], '[redigido]', $limpo);
        }
        $limpo = (string) preg_replace('/Bearer\s+[^\s"<>]+/i', 'Bearer [redigido]', $limpo);
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
                $atraso = self::atrasoFalha($tentativas, $e);
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
                $atraso = self::atrasoFalha($tentativas, $e);
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
     * Envia imediatamente (inclusive diagnóstico CLI); a fila aplica email_ativo antes de chamar.
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

        if (!in_array($cfgFinal['transporte'], ['smtp', 'graph'], true)) {
            throw new RuntimeException('EMAIL_TRANSPORTE inválido (use smtp ou graph).');
        }

        if (self::$transporte !== null) {
            (self::$transporte)($paraValido, $assuntoValido, $texto, $html, $mime, $cfgFinal);
            return;
        }

        if ($cfgFinal['transporte'] === 'graph') {
            self::enviarGraph($paraValido, $deEmail, $assuntoValido, $texto, $html, $cfgFinal);
        } else {
            self::enviarSmtp($paraValido, $deEmail, $mime, $cfgFinal);
        }
    }

    /** Considera Retry-After sem dormir nem bloquear o worker. */
    private static function atrasoFalha(int $tentativa, Throwable $erro): int
    {
        return max(self::atrasoTentativaSegundos($tentativa), $erro instanceof EmailFalhaMicrosoft ? $erro->retryAfter : 0);
    }

    private static function registrarSegredo(string $segredo): void
    {
        if ($segredo !== '' && !in_array($segredo, self::$segredos, true)) {
            self::$segredos[] = $segredo;
        }
    }

    /** @return array{status:int,headers:array,body:string} */
    private static function httpMicrosoft(string $url, array $headers, string $body, int $timeout): array
    {
        if (self::$http !== null) {
            try {
                return (self::$http)($url, $headers, $body, $timeout);
            } catch (Throwable) {
                // Nem exceções do adaptador de teste devem expor corpo, credenciais ou cabeçalhos.
                throw new RuntimeException('Falha de conexão HTTPS com a Microsoft.');
            }
        }
        if (!extension_loaded('curl')) {
            throw new RuntimeException('A extensão curl é necessária para o e-mail Microsoft.');
        }
        $curl = curl_init($url);
        if ($curl === false) {
            throw new RuntimeException('Falha de conexão HTTPS com a Microsoft.');
        }
        $resHeaders = [];
        try {
            curl_setopt_array($curl, [
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => $timeout,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_HEADERFUNCTION => static function ($ch, string $linha) use (&$resHeaders): int {
                    if (str_starts_with($linha, 'HTTP/')) { $resHeaders = []; }
                    if (str_contains($linha, ':')) {
                        [$nome, $valor] = explode(':', $linha, 2);
                        $resHeaders[strtolower(trim($nome))] = trim($valor);
                    }
                    return strlen($linha);
                },
            ]);
            $res = curl_exec($curl);
            if ($res === false) {
                // curl_error pode incluir detalhes de rede: diagnóstico deliberadamente limitado.
                throw new RuntimeException('Falha de conexão HTTPS com a Microsoft.');
            }
            return ['status' => (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE), 'headers' => $resHeaders, 'body' => $res];
        } finally {
            // O objeto CurlHandle é liberado ao sair do escopo (PHP 8.2+).
            unset($curl);
        }
    }

    private static function falhaMicrosoft(array $res, string $etapa): never
    {
        $status = (int) ($res['status'] ?? 0);
        $headers = array_change_key_case($res['headers'] ?? [], CASE_LOWER);
        $retry = trim((string) ($headers['retry-after'] ?? ''));
        $segundos = ctype_digit($retry) ? (int) $retry : max(0, (strtotime($retry) ?: time()) - time());
        // Diagnósticos fixos: nunca persiste o corpo retornado pela Microsoft.
        $ajuda = match ($status) {
            401 => 'Verifique as credenciais e a validade do token.',
            403 => 'Verifique a permissão de envio e o escopo da caixa remetente.',
            429 => 'Limite da Microsoft atingido; a fila respeitará Retry-After.',
            default => $status >= 500 ? 'Serviço Microsoft indisponível; a fila tentará novamente.' : 'Verifique a configuração Microsoft.',
        };
        // Limite compatível com a coluna DATETIME e modify(), inclusive para cabeçalhos inválidos.
        throw new EmailFalhaMicrosoft("{$etapa} (HTTP {$status}). {$ajuda}", $status, min(31536000, $segundos));
    }

    /** Obtém token app-only; cache por credencial e recurso com margem de 60 segundos. */
    public static function obterTokenMicrosoft(array $cfg, string $scope, bool $renovar = false): string
    {
        if (!in_array($scope, [self::SCOPE_GRAPH, self::SCOPE_SMTP], true)) {
            throw new RuntimeException('Escopo OAuth Microsoft inválido.');
        }
        $ms = $cfg['microsoft'] ?? [];
        $tenant = (string) ($ms['tenant_id'] ?? '');
        $client = (string) ($ms['client_id'] ?? '');
        $uuid = '/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i';
        if (!preg_match($uuid, $tenant) || !preg_match($uuid, $client)) {
            throw new RuntimeException('Configure MS_TENANT_ID e MS_CLIENT_ID com os identificadores do Entra.');
        }
        $secret = (string) ($ms['client_secret'] ?? '');
        self::registrarSegredo($secret);
        $chave = hash('sha256', json_encode([$ms, $scope], JSON_THROW_ON_ERROR));
        if (!$renovar && (self::$tokens[$chave]['expira'] ?? 0) > time()) {
            return self::$tokens[$chave]['token'];
        }
        unset(self::$tokens[$chave]);
        $url = 'https://login.microsoftonline.com/' . $tenant . '/oauth2/v2.0/token';
        $form = ['client_id' => $client, 'grant_type' => 'client_credentials', 'scope' => $scope];
        if (($ms['client_cert_path'] ?? '') !== '' || ($ms['client_cert_key_path'] ?? '') !== '') {
            $form['client_assertion_type'] = 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer';
            $form['client_assertion'] = self::assertionMicrosoft($ms, $url, $client);
            self::registrarSegredo($form['client_assertion']);
        } elseif ($secret !== '') {
            $form['client_secret'] = $secret;
        } else {
            throw new RuntimeException('Configure MS_CLIENT_SECRET ou o certificado e a chave privada Microsoft.');
        }
        $res = self::httpMicrosoft($url, ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'], http_build_query($form, '', '&', PHP_QUERY_RFC3986), (int) $cfg['timeout']);
        if ($res['status'] !== 200) { self::falhaMicrosoft($res, 'Falha ao obter token Microsoft'); }
        $dados = json_decode($res['body'], true);
        if (!is_array($dados) || !is_string($dados['access_token'] ?? null)
            || $dados['access_token'] === '' || preg_match('/[\x00-\x20\x7f]/', $dados['access_token'])
            || strtolower((string) ($dados['token_type'] ?? '')) !== 'bearer'
            || !is_numeric($dados['expires_in'] ?? null) || (int) $dados['expires_in'] <= 0) {
            throw new RuntimeException('Resposta de token Microsoft inválida.');
        }
        $token = $dados['access_token'];
        self::registrarSegredo($token);
        self::$tokens[$chave] = ['token' => $token, 'expira' => time() + max(0, min(86400, (int) $dados['expires_in']) - 60)];
        return $token;
    }

    private static function base64Url(string $valor): string
    {
        return rtrim(strtr(base64_encode($valor), '+/', '-_'), '=');
    }

    /** Certificado PEM cadastrado no Entra; assertion RS256 curta e exclusiva para este tenant. */
    private static function assertionMicrosoft(array $ms, string $audience, string $client): string
    {
        if (!extension_loaded('openssl')) { throw new RuntimeException('A extensão openssl é necessária para o certificado Microsoft.'); }
        if (($ms['client_cert_path'] ?? '') === '' || ($ms['client_cert_key_path'] ?? '') === '') {
            throw new RuntimeException('Configure os dois caminhos do certificado e da chave privada Microsoft.');
        }
        $cert = @file_get_contents((string) ($ms['client_cert_path'] ?? ''));
        $pem = @file_get_contents((string) ($ms['client_cert_key_path'] ?? ''));
        if ($cert === false || $pem === false) { throw new RuntimeException('Não foi possível ler o certificado ou a chave privada Microsoft.'); }
        $key = @openssl_pkey_get_private($pem);
        $info = @openssl_x509_parse($cert);
        $fingerprint = @openssl_x509_fingerprint($cert, 'sha1', true);
        $detalhes = $key !== false ? openssl_pkey_get_details($key) : false;
        if (!$key || !$info || !$fingerprint || !$detalhes || $detalhes['type'] !== OPENSSL_KEYTYPE_RSA
            || $detalhes['bits'] < 2048 || !@openssl_x509_check_private_key($cert, $key)
            || ($info['validFrom_time_t'] ?? PHP_INT_MAX) > time() || ($info['validTo_time_t'] ?? 0) <= time()) {
            throw new RuntimeException('Certificado Microsoft inválido, vencido ou incompatível com a chave RSA.');
        }
        $header = self::base64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT', 'x5t' => self::base64Url($fingerprint)], JSON_THROW_ON_ERROR));
        $claims = self::base64Url(json_encode(['aud' => $audience, 'iss' => $client, 'sub' => $client, 'jti' => Uuid::v4(), 'nbf' => time() - 5, 'exp' => time() + 300], JSON_THROW_ON_ERROR));
        $jwt = $header . '.' . $claims;
        if (!@openssl_sign($jwt, $assinatura, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Não foi possível assinar a autenticação Microsoft.');
        }
        return $jwt . '.' . self::base64Url($assinatura);
    }

    public static function montarXoauth2(string $usuario, string $token): string
    {
        $usuario = self::validarEndereco($usuario);
        if ($token === '' || preg_match('/[\x00-\x20\x7f]/', $token)) {
            throw new InvalidArgumentException('Token SMTP OAuth inválido.');
        }
        self::registrarSegredo($token);
        $base64 = base64_encode('user=' . $usuario . "\x01auth=Bearer " . $token . "\x01\x01");
        self::registrarSegredo($base64);
        return $base64;
    }

    private static function enviarGraph(string $para, string $de, string $assunto, string $texto, ?string $html, array $cfg): void
    {
        // Graph JSON aceita um corpo: usa HTML recebido ou converte o texto com escape.
        $corpo = $html !== null && trim($html) !== '' ? $html
            : '<pre>' . htmlspecialchars($texto, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</pre>';
        $json = json_encode([
            'message' => [
                'subject' => $assunto,
                'body' => ['contentType' => 'HTML', 'content' => $corpo],
                'toRecipients' => [['emailAddress' => ['address' => $para]]],
            ],
            'saveToSentItems' => false,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $url = 'https://graph.microsoft.com/v1.0/users/' . rawurlencode($de) . '/sendMail';
        for ($tentativa = 0; $tentativa < 2; $tentativa++) {
            $token = self::obterTokenMicrosoft($cfg, self::SCOPE_GRAPH, $tentativa > 0);
            $res = self::httpMicrosoft($url, ['Authorization: Bearer ' . $token, 'Content-Type: application/json'], $json, (int) $cfg['timeout']);
            if ($res['status'] === 202) { return; } // Aceitação para processamento, não confirmação de entrega.
            if ($res['status'] === 401 && $tentativa === 0) { continue; }
            self::falhaMicrosoft($res, 'Falha no envio Microsoft Graph');
        }
    }

    /** Abre socket e executa SMTP com LOGIN ou XOAUTH2. */
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
        if (($cfg['smtp_auth'] ?? 'login') === 'oauth' && ($seguranca === 'nenhuma' || !$tlsVerify)) {
            throw new RuntimeException('SMTP OAuth exige TLS com certificado verificado.');
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
            if ($e instanceof EmailFalhaMicrosoft) { throw $e; }
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
        $auth = (string) ($cfg['smtp_auth'] ?? 'login');
        if (!in_array($auth, ['login', 'oauth'], true)) {
            throw new RuntimeException('EMAIL_SMTP_AUTH inválido (use login ou oauth).');
        }
        if ($auth === 'oauth' && (!in_array($seguranca, ['tls', 'ssl'], true) || !($cfg['tls_verify'] ?? true))) {
            throw new RuntimeException('SMTP OAuth exige TLS com certificado verificado.');
        }
        self::registrarSegredo($senha);
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

        if ($auth === 'oauth') {
            $token = self::obterTokenMicrosoft($cfg, self::SCOPE_SMTP);
            $xoauth = self::montarXoauth2($usuario !== '' ? $usuario : $deEmail, $token);
            // Alguns servidores retornam 334 com um desafio de erro SASL. A conexão será fechada.
            self::enviarComando($socket, 'AUTH XOAUTH2 ' . $xoauth, [235], 'Autenticação SMTP OAuth');
        } elseif ($usuario !== '') {
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
            $resumo = self::limparErro($resposta);
            throw new RuntimeException("Erro SMTP em {$etapa} (código {$codigo}): {$resumo}");
        }

        return $resposta;
    }
}

/** Falha HTTP com espera sugerida pelo servidor, reaproveitada pela fila. */
final class EmailFalhaMicrosoft extends RuntimeException
{
    public function __construct(string $message, int $status, public readonly int $retryAfter)
    {
        parent::__construct($message, $status);
    }
}
