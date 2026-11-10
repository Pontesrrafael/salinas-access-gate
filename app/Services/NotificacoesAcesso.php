<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Http;
use App\Core\I18n;
use App\Core\Uuid;
use Throwable;

/**
 * Notificações por e-mail de solicitações de acesso e verificações SSMA.
 *
 * Regras:
 * - Reutiliza a fila assíncrona email_fila via Email::enfileirar (nunca envia na requisição HTTP).
 * - Respeita Email::estaAtivo() e a configuração opcional email_acesso_ativo (Settings, padrão ligado = '1').
 * - Destinatários por evento (sem duplicar e nunca notificando o próprio autor da ação):
 *   - criada (interna, link externo ou pública): autorizadores da área (responsável da área + usuários Segurança Patrimonial)
 *   - enviada_ssma: usuários ativos com perfil ssma (usuarios.papel = 'ssma' ou usuario_perfis.perfil = 'ssma')
 *   - ssma_apto / ssma_nao_apto: criador da solicitação (criado_por) e autorizadores da área
 *   - aprovada / recusada: criador da solicitação (criado_por)
 * - Nunca envia e-mail ao visitante externo (contato_email) nesta etapa.
 * - Minimização LGPD: nunca inclui CPF, documento, telefone, motivo livre nem observações;
 *   inclui apenas nome do visitante, empresa, data prevista, área, status e link para a tela.
 * - Falhas nunca interrompem a ação principal (try/catch).
 */
final class NotificacoesAcesso
{
    /**
     * Resolvedor opcional para testes sem banco de dados.
     * Assinatura: fn(string $tipo, mixed $arg): mixed
     *   - ('usuario', string $id): ?array{id:string,nome:string,email:string}
     *   - ('area', string $areaId): ?array{id?:string,nome?:string,responsavel_user_id?:?string}
     *   - ('autorizadores', string $areaId): ?array<int, array{id:string,nome:string,email:string}>
     *   - ('seguranca_patrimonial', ?string $areaId): ?array<int, array{id:string,nome:string,email:string}>
     *   - ('ssma', ?string $areaId): ?array<int, array{id:string,nome:string,email:string}>
     *   - ('solicitacao_meta', string $solicitacaoId): ?array<string, mixed>
     *
     * @var null|callable(string,mixed):mixed
     */
    private static $resolvedor = null;

    private static ?bool $ativoOverride = null;

    public static function definirResolvedor(?callable $resolvedor): void
    {
        self::$resolvedor = $resolvedor;
    }

    /** Sobrescreve a flag email_acesso_ativo em testes sem banco; passe null para restaurar leitura de Settings. */
    public static function definirAtivo(?bool $ativo): void
    {
        self::$ativoOverride = $ativo;
    }

    /**
     * Verifica se o envio de e-mails de solicitações de acesso/SSMA está ativo.
     * Exige Email::estaAtivo() e respeita Settings 'email_acesso_ativo' (padrão ligado = '1').
     */
    public static function estaAtivo(): bool
    {
        if (!Email::estaAtivo()) {
            return false;
        }
        if (self::$ativoOverride !== null) {
            return self::$ativoOverride;
        }
        try {
            $valor = Settings::get('email_acesso_ativo', '1');
            if ($valor === null || trim($valor) === '') {
                return true;
            }
            return !in_array(strtolower(trim($valor)), ['0', 'false', 'nao', 'não', 'off'], true);
        } catch (Throwable) {
            return true;
        }
    }

    /**
     * Enfileira notificações de uma solicitação de acesso ou decisão do SSMA.
     * Nunca propaga exceções para não interromper o fluxo operacional.
     *
     * @param string               $evento      criada|enviada_ssma|ssma_apto|ssma_nao_apto|aprovada|recusada
     * @param array<string, mixed> $solicitacao Dados ou linha de solicitacoes_acesso
     * @param array<string, mixed> $extra       Contexto adicional (ex.: usuario_id, autorizadores, ssma, usuarios)
     * @return int Quantidade de mensagens enfileiradas
     */
    public static function solicitacao(string $evento, array $solicitacao, array $extra = []): int
    {
        try {
            if (!self::estaAtivo()) {
                return 0;
            }
            $eventoNorm = self::normalizarEvento($evento);
            if ($eventoNorm === null) {
                return 0;
            }

            // Link externo ainda não preenchido: o aviso sai quando o visitante enviar os dados.
            if (!empty($solicitacao['aguardando_preenchimento'])) {
                return 0;
            }
            $enfileirados = 0;
            $id = (string) ($solicitacao['id'] ?? '');
            if ($id === '') {
                return 0;
            }

            $solicitacao = self::enriquecerSolicitacao($solicitacao);
            $autorId = self::identificarAutor($eventoNorm, $solicitacao, $extra);
            $destinatarios = self::montarDestinatarios($eventoNorm, $solicitacao, $extra, $autorId);
            if ($destinatarios === []) {
                return 0;
            }

            $visitanteNome = self::limparLinha((string) ($solicitacao['visitante_nome'] ?? ''));
            if ($visitanteNome === '') {
                $visitanteNome = t('Visitante');
            }
            $empresaNome = self::limparLinha((string) ($solicitacao['empresa'] ?? ''));
            if ($empresaNome === '') {
                $empresaNome = '—';
            }
            $areaNome = self::limparLinha((string) ($solicitacao['area_nome'] ?? ''));
            if ($areaNome === '') {
                $areaNome = '—';
            }
            $dataPrevista = self::formatarDataPrevista((string) ($solicitacao['data_prevista'] ?? ''));
            $statusRotulo = self::rotuloStatus($eventoNorm, $solicitacao);
            $linkTela = $eventoNorm === 'enviada_ssma'
                ? Http::absoluteUrl('/ssma/' . $id)
                : Http::absoluteUrl('/solicitacoes');
            $rotuloBotao = $eventoNorm === 'enviada_ssma'
                ? t('Verificação SSMA')
                : t('Abrir solicitações');
            $referencia = 'solicitacao:' . $id;

            [$assunto, $tituloEvento, $resumoEvento] = self::textosEvento($eventoNorm, $visitanteNome);

            foreach ($destinatarios as $dest) {
                $dadosEmail = [
                    'assunto'          => $assunto,
                    'tituloEvento'     => $tituloEvento,
                    'resumoEvento'     => $resumoEvento,
                    'destinatarioNome' => self::limparLinha((string) ($dest['nome'] ?? '')),
                    'visitanteNome'    => $visitanteNome,
                    'empresaNome'      => $empresaNome,
                    'dataPrevista'     => $dataPrevista,
                    'areaNome'         => $areaNome,
                    'statusRotulo'     => $statusRotulo,
                    'linkTela'         => $linkTela,
                    'rotuloBotao'      => $rotuloBotao,
                ];

                $texto = self::renderizarTexto($dadosEmail);
                $html = self::renderizarHtml($dadosEmail);

                try {
                    Email::enfileirar((string) $dest['email'], $assunto, $texto, $html, $referencia);
                    $enfileirados++;
                } catch (Throwable) {
                    // Endereço inválido ou falha de escrita na fila nunca interrompe os demais envios.
                }
            }
            return $enfileirados;
        } catch (Throwable) {
            // Falha de e-mail nunca pode quebrar a ação principal.
            return 0;
        }
    }

    /**
     * Determina a lista deduplicada de destinatários para o evento, excluindo o próprio autor da ação
     * e nunca incluindo o e-mail externo do visitante (contato_email).
     *
     * @param array<string, mixed> $solicitacao
     * @param array<string, mixed> $extra
     * @return array<int, array{id:string,nome:string,email:string}>
     */
    public static function montarDestinatarios(
        string $evento,
        array $solicitacao,
        array $extra = [],
        ?string $autorId = null
    ): array {
        $eventoNorm = self::normalizarEvento($evento);
        if ($eventoNorm === null) {
            return [];
        }

        $areaId = (string) ($solicitacao['area_id'] ?? '');
        $candidatos = [];

        switch ($eventoNorm) {
            case 'criada':
                foreach (self::buscarAutorizadoresArea($areaId, $solicitacao, $extra) as $u) {
                    $candidatos[] = $u;
                }
                break;

            case 'enviada_ssma':
                foreach (self::buscarUsuariosSsma($areaId, $solicitacao, $extra) as $u) {
                    $candidatos[] = $u;
                }
                break;

            case 'ssma_apto':
            case 'ssma_nao_apto':
                $criador = self::buscarCriador($solicitacao, $extra);
                if ($criador !== null) {
                    $candidatos[] = $criador;
                }
                foreach (self::buscarAutorizadoresArea($areaId, $solicitacao, $extra) as $u) {
                    $candidatos[] = $u;
                }
                break;

            case 'aprovada':
            case 'recusada':
                $criador = self::buscarCriador($solicitacao, $extra);
                if ($criador !== null) {
                    $candidatos[] = $criador;
                }
                break;
        }

        $autorEmail = null;
        if ($autorId !== null && $autorId !== '') {
            $autor = self::buscarUsuario($autorId, $solicitacao, $extra);
            if ($autor !== null && !empty($autor['email'])) {
                $autorEmail = mb_strtolower(trim((string) $autor['email']));
            }
        }

        $unicos = [];
        $porId = [];
        $porEmail = [];
        foreach ($candidatos as $c) {
            $cid = trim((string) ($c['id'] ?? ''));
            $email = trim((string) ($c['email'] ?? ''));
            $emailKey = mb_strtolower($email);
            if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                continue;
            }
            if ($autorId !== null && $autorId !== '' && $cid !== '' && $cid === $autorId) {
                continue;
            }
            if ($autorEmail !== null && $emailKey === $autorEmail) {
                continue;
            }
            if (($cid !== '' && isset($porId[$cid])) || isset($porEmail[$emailKey])) {
                continue;
            }
            if ($cid !== '') {
                $porId[$cid] = true;
            }
            $porEmail[$emailKey] = true;
            $unicos[] = [
                'id'    => $cid,
                'nome'  => (string) ($c['nome'] ?? ''),
                'email' => $email,
            ];
        }

        return $unicos;
    }

    public static function normalizarEvento(string $evento): ?string
    {
        return match (strtolower(trim($evento))) {
            'criada', 'criado', 'criar', 'nova', 'novo',
            'solicitacao_criada', 'link_preenchido'            => 'criada',
            'enviada_ssma', 'ssma', 'aguardando_ssma',
            'enviar_ssma', 'ssma_pendente', 'verificacao_ssma' => 'enviada_ssma',
            'ssma_apto', 'apto', 'apto_ssma'                   => 'ssma_apto',
            'ssma_nao_apto', 'nao_apto', 'nao_apto_ssma',
            'reprovado_ssma', 'ssma_reprovado'                 => 'ssma_nao_apto',
            'aprovada', 'aprovado', 'aprovar'                  => 'aprovada',
            'recusada', 'recusado', 'recusar'                  => 'recusada',
            default                                            => null,
        };
    }

    /**
     * Identifica o autor da ação para nunca notificá-lo do próprio ato.
     * Solicitações preenchidas por visitante externo (origem 'link' ou 'publica') não têm autor interno na abertura.
     *
     * @param array<string, mixed> $solicitacao
     * @param array<string, mixed> $extra
     */
    private static function identificarAutor(string $eventoNorm, array $solicitacao, array $extra): ?string
    {
        foreach (['usuario_id', 'autor_id', 'ator_id'] as $chave) {
            if (array_key_exists($chave, $extra)) {
                $val = $extra[$chave];
                return is_string($val) && $val !== '' ? $val : null;
            }
        }
        if (isset($extra['usuario']) && is_array($extra['usuario']) && !empty($extra['usuario']['id'])) {
            return (string) $extra['usuario']['id'];
        }
        $authId = Auth::id();
        if ($authId !== null && $authId !== '') {
            return $authId;
        }
        $origem = strtolower(trim((string) ($solicitacao['origem'] ?? 'interna')));
        if (in_array($eventoNorm, ['criada', 'enviada_ssma'], true) && $origem === 'interna' && !empty($solicitacao['criado_por'])) {
            return (string) $solicitacao['criado_por'];
        }
        if (in_array($eventoNorm, ['ssma_apto', 'ssma_nao_apto'], true) && !empty($solicitacao['verificado_por'])) {
            return (string) $solicitacao['verificado_por'];
        }
        if (in_array($eventoNorm, ['aprovada', 'recusada'], true) && !empty($solicitacao['autorizado_por'])) {
            return (string) $solicitacao['autorizado_por'];
        }
        return null;
    }

    /**
     * @return array{0:string,1:string,2:string} [assunto, tituloEvento, resumoEvento]
     */
    private static function textosEvento(string $eventoNorm, string $visitanteNome): array
    {
        $prefixo = '[Salinas Access Gate] ' . t('Solicitação de acesso');

        return match ($eventoNorm) {
            'criada' => [
                mb_substr("{$prefixo} — " . t('Nova solicitação de acesso') . ": {$visitanteNome}", 0, 200),
                t('Nova solicitação de acesso'),
                t('Uma solicitação de acesso foi registrada para a sua área e aguarda análise.'),
            ],
            'enviada_ssma' => [
                mb_substr("{$prefixo} — " . t('Aguardando verificação do SSMA') . ": {$visitanteNome}", 0, 200),
                t('Solicitação enviada ao SSMA'),
                t('Uma solicitação de acesso com atividade de risco aguarda verificação de aptidão pelo SSMA.'),
            ],
            'ssma_apto' => [
                mb_substr("{$prefixo} — " . t('Apto pelo SSMA') . ": {$visitanteNome}", 0, 200),
                t('Verificação SSMA: Apto'),
                t('O SSMA marcou a solicitação de acesso como Apto. O pedido segue para autorização da área.'),
            ],
            'ssma_nao_apto' => [
                mb_substr("{$prefixo} — " . t('Reprovado pelo SSMA') . ": {$visitanteNome}", 0, 200),
                t('Verificação SSMA: Não apto'),
                t('O SSMA marcou a solicitação de acesso como Não apto e o pedido foi recusado.'),
            ],
            'aprovada' => [
                mb_substr("{$prefixo} — " . t('Solicitação aprovada') . ": {$visitanteNome}", 0, 200),
                t('Solicitação de acesso aprovada'),
                t('A sua solicitação de acesso foi aprovada.'),
            ],
            default => [
                mb_substr("{$prefixo} — " . t('Solicitação recusada') . ": {$visitanteNome}", 0, 200),
                t('Solicitação de acesso recusada'),
                t('A sua solicitação de acesso foi recusada. Acesse o sistema para consultar os detalhes.'),
            ],
        };
    }

    /**
     * @param array<string, mixed> $solicitacao
     */
    private static function rotuloStatus(string $eventoNorm, array $solicitacao): string
    {
        $statusPadrao = match ($eventoNorm) {
            'enviada_ssma'  => 'aguardando_ssma',
            'ssma_apto'     => 'pendente',
            'ssma_nao_apto' => 'recusada',
            'aprovada'      => 'aprovada',
            'recusada'      => 'recusada',
            default         => !empty($solicitacao['atividade_risco']) ? 'aguardando_ssma' : 'pendente',
        };
        $statusCodigo = (string) ($solicitacao['status'] ?? '');
        if ($statusCodigo === '' || ($eventoNorm === 'enviada_ssma' && $statusCodigo === 'pendente')) {
            $statusCodigo = $statusPadrao;
        }
        $rotuloBase = label_t(STATUS_SOLICITACAO, $statusCodigo, $statusCodigo);

        if ($eventoNorm === 'ssma_apto') {
            return $rotuloBase . ' (' . t('Apto pelo SSMA') . ')';
        }
        if ($eventoNorm === 'ssma_nao_apto') {
            return $rotuloBase . ' (' . t('Reprovado pelo SSMA') . ')';
        }
        return $rotuloBase;
    }

    private static function formatarDataPrevista(string $data): string
    {
        $limpa = self::limparLinha($data);
        if ($limpa === '') {
            return '—';
        }
        $formatada = fmt_data($limpa);
        return $formatada !== '—' ? $formatada : $limpa;
    }

    /**
     * Renderiza corpo em texto puro seguindo o padrão de emails/chamado_texto.php.
     *
     * @param array<string, string> $d
     */
    private static function renderizarTexto(array $d): string
    {
        $linhas = [
            'SALINAS GOLD MINERAÇÃO · SALINAS ACCESS GATE',
            $d['tituloEvento'],
            str_repeat('-', 56),
        ];
        if ($d['destinatarioNome'] !== '') {
            $linhas[] = t('Olá, {nome}.', ['nome' => $d['destinatarioNome']]);
            $linhas[] = '';
        }
        $linhas[] = $d['resumoEvento'];
        $linhas[] = '';
        $linhas[] = t('Visitante / Terceiro') . ': ' . $d['visitanteNome'];
        $linhas[] = t('Empresa') . ': ' . $d['empresaNome'];
        $linhas[] = t('Data prevista') . ': ' . $d['dataPrevista'];
        $linhas[] = t('Área') . ': ' . $d['areaNome'];
        $linhas[] = t('Status') . ': ' . $d['statusRotulo'];
        $linhas[] = '';
        $linhas[] = $d['rotuloBotao'] . ':';
        $linhas[] = $d['linkTela'];
        $linhas[] = '';
        $linhas[] = str_repeat('-', 56);
        $linhas[] = t('Mensagem automática do Salinas Access Gate. Por privacidade (LGPD), detalhes completos, anexos e histórico ficam restritos ao acesso autenticado no sistema.');

        return implode("\n", $linhas) . "\n";
    }

    /**
     * Renderiza corpo HTML seguindo exatamente o layout visual de emails/chamado_html.php (Calibri, #024B37, #FFCD00).
     *
     * @param array<string, string> $d
     */
    private static function renderizarHtml(array $d): string
    {
        $lang = e(I18n::current());
        $assunto = e($d['assunto']);
        $tituloEvento = e($d['tituloEvento']);
        $resumoEvento = e($d['resumoEvento']);
        $saudacaoHtml = '';
        if ($d['destinatarioNome'] !== '') {
            $saudacaoHtml = '          <p style="margin:0 0 12px 0;font-size:15px;">'
                . e(t('Olá, {nome}.', ['nome' => $d['destinatarioNome']]))
                . "</p>\n";
        }

        $lblVisitante = e(t('Visitante / Terceiro'));
        $valVisitante = e($d['visitanteNome']);
        $lblEmpresa = e(t('Empresa'));
        $valEmpresa = e($d['empresaNome']);
        $lblData = e(t('Data prevista'));
        $valData = e($d['dataPrevista']);
        $lblArea = e(t('Área'));
        $valArea = e($d['areaNome']);
        $lblStatus = e(t('Status'));
        $valStatus = e($d['statusRotulo']);
        $linkTela = e($d['linkTela']);
        $rotuloBotao = e($d['rotuloBotao']);
        $lblLinkDireto = e(t('Link direto'));
        $rodapeLgpd = e(t('Mensagem automática do Salinas Access Gate. Por privacidade (LGPD), detalhes completos, anexos e histórico ficam restritos ao acesso autenticado no sistema.'));

        return <<<HTML
<!DOCTYPE html>
<html lang="{$lang}">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>{$assunto}</title>
</head>
<body style="margin:0;padding:24px 12px;background-color:#F4F6F5;font-family:Calibri,'Segoe UI',Arial,sans-serif;color:#1F2933;line-height:1.5;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;margin:0 auto;background-color:#FFFFFF;border:1px solid #D8E0DC;border-radius:8px;overflow:hidden;">
    <tr>
      <td style="background-color:#024B37;padding:18px 24px;border-bottom:4px solid #FFCD00;">
        <div style="font-size:12px;letter-spacing:0.6px;text-transform:uppercase;color:#C6D53E;font-weight:bold;">
          Salinas Gold Mineração · Salinas Access Gate
        </div>
        <div style="font-size:20px;font-weight:bold;color:#FFFFFF;margin-top:4px;">
          {$tituloEvento}
        </div>
      </td>
    </tr>
    <tr>
      <td style="padding:24px;">
{$saudacaoHtml}        <p style="margin:0 0 18px 0;font-size:15px;color:#334155;">
          {$resumoEvento}
        </p>

        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#F8FAF9;border:1px solid #E2E8F0;border-left:4px solid #024B37;border-radius:6px;padding:14px 16px;margin-bottom:20px;">
          <tr>
            <td style="padding:4px 0;font-size:13px;color:#707070;width:140px;">{$lblVisitante}:</td>
            <td style="padding:4px 0;font-size:14px;font-weight:bold;color:#024B37;">{$valVisitante}</td>
          </tr>
          <tr>
            <td style="padding:4px 0;font-size:13px;color:#707070;">{$lblEmpresa}:</td>
            <td style="padding:4px 0;font-size:14px;color:#1F2933;">{$valEmpresa}</td>
          </tr>
          <tr>
            <td style="padding:4px 0;font-size:13px;color:#707070;">{$lblData}:</td>
            <td style="padding:4px 0;font-size:14px;color:#1F2933;">{$valData}</td>
          </tr>
          <tr>
            <td style="padding:4px 0;font-size:13px;color:#707070;">{$lblArea}:</td>
            <td style="padding:4px 0;font-size:14px;color:#1F2933;">{$valArea}</td>
          </tr>
          <tr>
            <td style="padding:4px 0;font-size:13px;color:#707070;">{$lblStatus}:</td>
            <td style="padding:4px 0;font-size:14px;font-weight:bold;color:#1F2933;">{$valStatus}</td>
          </tr>
        </table>

        <p style="margin:0 0 20px 0;">
          <a href="{$linkTela}" style="display:inline-block;background-color:#024B37;color:#FFFFFF;text-decoration:none;font-weight:bold;font-size:14px;padding:10px 20px;border-radius:6px;border-bottom:2px solid #FFCD00;">
            {$rotuloBotao}
          </a>
        </p>

        <p style="margin:0;font-size:12px;color:#707070;word-break:break-all;">
          {$lblLinkDireto}: <a href="{$linkTela}" style="color:#024B37;">{$linkTela}</a>
        </p>
      </td>
    </tr>
    <tr>
      <td style="background-color:#F8FAF9;padding:14px 24px;border-top:1px solid #E2E8F0;font-size:12px;color:#707070;">
        {$rodapeLgpd}
      </td>
    </tr>
  </table>
</body>
</html>
HTML;
    }

    /**
     * Completa metadados ausentes da solicitação e da área quando necessário.
     *
     * @param array<string, mixed> $solicitacao
     * @return array<string, mixed>
     */
    private static function enriquecerSolicitacao(array $solicitacao): array
    {
        $id = (string) ($solicitacao['id'] ?? '');
        $precisaMeta = empty($solicitacao['visitante_nome'])
            || empty($solicitacao['area_id'])
            || empty($solicitacao['area_nome'])
            || !array_key_exists('responsavel_user_id', $solicitacao);

        if (!$precisaMeta) {
            return $solicitacao;
        }

        if (self::$resolvedor !== null) {
            if ($id !== '') {
                $meta = (self::$resolvedor)('solicitacao_meta', $id);
                if (is_array($meta)) {
                    foreach ($meta as $k => $v) {
                        if (!isset($solicitacao[$k]) || $solicitacao[$k] === '' || $solicitacao[$k] === null) {
                            $solicitacao[$k] = $v;
                        }
                    }
                }
            }
            $areaId = (string) ($solicitacao['area_id'] ?? '');
            if ($areaId !== '' && (empty($solicitacao['area_nome']) || !array_key_exists('responsavel_user_id', $solicitacao))) {
                $area = (self::$resolvedor)('area', $areaId);
                if (is_array($area)) {
                    if (empty($solicitacao['area_nome']) && !empty($area['nome'])) {
                        $solicitacao['area_nome'] = $area['nome'];
                    }
                    if (!array_key_exists('responsavel_user_id', $solicitacao) && array_key_exists('responsavel_user_id', $area)) {
                        $solicitacao['responsavel_user_id'] = $area['responsavel_user_id'];
                    }
                }
            }
            return $solicitacao;
        }

        if (Uuid::valid($id)) {
            try {
                $row = Database::one(
                    'SELECT s.visitante_nome, s.empresa, s.area_id, s.data_prevista, s.atividade_risco,
                            s.status, s.origem, s.criado_por, s.autorizado_por,
                            a.nome AS area_nome, a.responsavel_user_id
                       FROM solicitacoes_acesso s
                       LEFT JOIN areas a ON a.id = s.area_id
                      WHERE s.id = ?',
                    [$id]
                );
                if (is_array($row)) {
                    foreach ($row as $k => $v) {
                        if (!isset($solicitacao[$k]) || $solicitacao[$k] === '' || $solicitacao[$k] === null) {
                            $solicitacao[$k] = $v;
                        }
                    }
                }
            } catch (Throwable) {
                // Ignora falha de leitura complementar em ambiente sem banco.
            }
        }

        $areaId = (string) ($solicitacao['area_id'] ?? '');
        if (Uuid::valid($areaId) && (empty($solicitacao['area_nome']) || !array_key_exists('responsavel_user_id', $solicitacao))) {
            try {
                $area = Database::one('SELECT nome, responsavel_user_id FROM areas WHERE id = ?', [$areaId]);
                if (is_array($area)) {
                    if (empty($solicitacao['area_nome']) && !empty($area['nome'])) {
                        $solicitacao['area_nome'] = $area['nome'];
                    }
                    if (!array_key_exists('responsavel_user_id', $solicitacao)) {
                        $solicitacao['responsavel_user_id'] = $area['responsavel_user_id'] ?? null;
                    }
                }
            } catch (Throwable) {
                // Ignora falha em ambiente sem banco.
            }
        }

        return $solicitacao;
    }

    /**
     * Busca o criador interno da solicitação (solicitacoes_acesso.criado_por).
     * Nunca usa contato_email (visitante externo).
     *
     * @param array<string, mixed> $solicitacao
     * @param array<string, mixed> $extra
     * @return array{id:string,nome:string,email:string}|null
     */
    private static function buscarCriador(array $solicitacao, array $extra): ?array
    {
        if (isset($extra['criador']) && is_array($extra['criador']) && !empty($extra['criador']['email'])) {
            return [
                'id'    => (string) ($extra['criador']['id'] ?? $solicitacao['criado_por'] ?? ''),
                'nome'  => (string) ($extra['criador']['nome'] ?? ''),
                'email' => (string) $extra['criador']['email'],
            ];
        }
        $criadoPor = trim((string) ($solicitacao['criado_por'] ?? ''));
        if ($criadoPor === '') {
            return null;
        }
        if (!empty($solicitacao['criador_email'])) {
            return [
                'id'    => $criadoPor,
                'nome'  => (string) ($solicitacao['criador_nome'] ?? ''),
                'email' => (string) $solicitacao['criador_email'],
            ];
        }
        return self::buscarUsuario($criadoPor, $solicitacao, $extra);
    }

    /**
     * Busca autorizadores da área: responsável da área (areas.responsavel_user_id)
     * e usuários ativos com Segurança Patrimonial (usuarios.seguranca_patrimonial = 1).
     *
     * @param array<string, mixed> $solicitacao
     * @param array<string, mixed> $extra
     * @return array<int, array{id:string,nome:string,email:string}>
     */
    private static function buscarAutorizadoresArea(string $areaId, array $solicitacao, array $extra): array
    {
        if (isset($extra['autorizadores']) && is_array($extra['autorizadores'])) {
            return $extra['autorizadores'];
        }
        if (isset($solicitacao['autorizadores']) && is_array($solicitacao['autorizadores'])) {
            return $solicitacao['autorizadores'];
        }

        $lista = [];

        if (self::$resolvedor !== null) {
            $diretos = (self::$resolvedor)('autorizadores', $areaId);
            if (is_array($diretos)) {
                foreach ($diretos as $u) {
                    if (is_array($u)) {
                        $lista[] = $u;
                    }
                }
            }

            $respId = (string) ($solicitacao['responsavel_user_id'] ?? '');
            if ($respId === '' && $areaId !== '') {
                $area = (self::$resolvedor)('area', $areaId);
                if (is_array($area) && !empty($area['responsavel_user_id'])) {
                    $respId = (string) $area['responsavel_user_id'];
                }
            }
            if ($respId !== '') {
                $resp = self::buscarUsuario($respId, $solicitacao, $extra);
                if ($resp !== null) {
                    $lista[] = $resp;
                }
            }

            $segPat = $extra['seguranca_patrimonial'] ?? (self::$resolvedor)('seguranca_patrimonial', $areaId);
            if (is_array($segPat)) {
                foreach ($segPat as $u) {
                    if (is_array($u)) {
                        $lista[] = $u;
                    }
                }
            }
            return $lista;
        }

        $respId = (string) ($solicitacao['responsavel_user_id'] ?? '');
        if ($respId !== '' && Uuid::valid($respId)) {
            $resp = self::buscarUsuario($respId, $solicitacao, $extra);
            if ($resp !== null) {
                $lista[] = $resp;
            }
        } elseif (Uuid::valid($areaId)) {
            try {
                $resp = Database::one(
                    'SELECT u.id, u.nome, u.email
                       FROM areas a
                       JOIN usuarios u ON u.id = a.responsavel_user_id
                      WHERE a.id = ? AND a.ativo = 1 AND u.ativo = 1',
                    [$areaId]
                );
                if (is_array($resp)) {
                    $lista[] = [
                        'id'    => (string) $resp['id'],
                        'nome'  => (string) $resp['nome'],
                        'email' => (string) $resp['email'],
                    ];
                }
            } catch (Throwable) {
                // Ignora em ambiente sem banco.
            }
        }

        if (isset($extra['seguranca_patrimonial']) && is_array($extra['seguranca_patrimonial'])) {
            foreach ($extra['seguranca_patrimonial'] as $u) {
                if (is_array($u)) {
                    $lista[] = $u;
                }
            }
            return $lista;
        }

        try {
            $segPat = Database::all(
                'SELECT id, nome, email
                   FROM usuarios
                  WHERE ativo = 1 AND seguranca_patrimonial = 1
                  ORDER BY nome'
            );
            foreach ($segPat as $u) {
                $lista[] = [
                    'id'    => (string) $u['id'],
                    'nome'  => (string) $u['nome'],
                    'email' => (string) $u['email'],
                ];
            }
        } catch (Throwable) {
            // Ignora em ambiente sem banco.
        }

        return $lista;
    }

    /**
     * Busca usuários ativos com perfil ssma (em usuarios.papel ou usuario_perfis).
     *
     * @param array<string, mixed> $solicitacao
     * @param array<string, mixed> $extra
     * @return array<int, array{id:string,nome:string,email:string}>
     */
    private static function buscarUsuariosSsma(string $areaId, array $solicitacao, array $extra): array
    {
        if (isset($extra['ssma']) && is_array($extra['ssma'])) {
            return $extra['ssma'];
        }
        if (isset($solicitacao['ssma']) && is_array($solicitacao['ssma'])) {
            return $solicitacao['ssma'];
        }
        if (self::$resolvedor !== null) {
            $lista = (self::$resolvedor)('ssma', $areaId);
            return is_array($lista) ? $lista : [];
        }

        try {
            return Database::all(
                "SELECT DISTINCT u.id, u.nome, u.email
                   FROM usuarios u
                   LEFT JOIN usuario_perfis up ON up.usuario_id = u.id AND up.perfil = 'ssma'
                  WHERE u.ativo = 1
                    AND (u.papel = 'ssma' OR up.perfil = 'ssma')
                  ORDER BY u.nome"
            );
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param array<string, mixed> $solicitacao
     * @param array<string, mixed> $extra
     * @return array{id:string,nome:string,email:string}|null
     */
    private static function buscarUsuario(string $usuarioId, array $solicitacao, array $extra): ?array
    {
        if ($usuarioId === '') {
            return null;
        }
        if (isset($extra['usuarios'][$usuarioId]) && is_array($extra['usuarios'][$usuarioId])) {
            $u = $extra['usuarios'][$usuarioId];
            return [
                'id'    => (string) ($u['id'] ?? $usuarioId),
                'nome'  => (string) ($u['nome'] ?? ''),
                'email' => (string) ($u['email'] ?? ''),
            ];
        }
        if (self::$resolvedor !== null) {
            $u = (self::$resolvedor)('usuario', $usuarioId);
            return is_array($u) ? [
                'id'    => (string) ($u['id'] ?? $usuarioId),
                'nome'  => (string) ($u['nome'] ?? ''),
                'email' => (string) ($u['email'] ?? ''),
            ] : null;
        }
        if (!Uuid::valid($usuarioId)) {
            return null;
        }

        try {
            $u = Database::one('SELECT id, nome, email FROM usuarios WHERE id = ? AND ativo = 1', [$usuarioId]);
            return $u ? [
                'id'    => (string) $u['id'],
                'nome'  => (string) $u['nome'],
                'email' => (string) $u['email'],
            ] : null;
        } catch (Throwable) {
            return null;
        }
    }

    private static function limparLinha(string $valor): string
    {
        return trim((string) preg_replace('/[\r\n\0]+/', ' ', $valor));
    }
}
