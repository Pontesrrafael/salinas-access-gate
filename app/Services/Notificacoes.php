<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Http;
use App\Core\Uuid;
use App\Core\View;
use Throwable;

/**
 * Notificações por e-mail de eventos de chamados.
 *
 * Regras:
 * - Apenas enfileira em email_fila via Email::enfileirar (nunca envia na requisição HTTP).
 * - Filtra destinatários por PreferenciasNotificacao::quer($usuarioId, $evento).
 * - Destinatários por evento:
 *   - criado: equipe da área (atendente.criado)
 *   - recebido / solicitante.criado: solicitante (solicitante.criado)
 *   - atribuido: atendente responsável (atendente.atribuido) e solicitante (solicitante.atribuido)
 *   - comentario (público): a outra parte (atendente.comentario se o solicitante comentou; solicitante.comentario se a equipe comentou)
 *   - pendente: solicitante (solicitante.pendente)
 *   - solucionado: solicitante (solicitante.solucionado)
 *   - fechado: a outra parte (atendente/equipe quando fechado pelo solicitante; solicitante.fechado quando fechado automaticamente ou pela equipe)
 *   - transferido: equipe da área de destino (atendente.transferido)
 *   - reaberto: atendente responsável ou equipe da área (atendente.reaberto)
 * - Nunca avisa quem realizou a ação.
 * - Nunca envia notas internas nem dados sensíveis/desnecessários (LGPD).
 */
final class Notificacoes
{
    /**
     * Resolvedor opcional para testes sem banco de dados.
     * Assinatura: fn(string $tipo, mixed $arg): mixed
     *   - ('usuario', string $id): ?array{id:string,nome:string,email:string}
     *   - ('equipe', string $areaId): array<int, array{id:string,nome:string,email:string}>
     *   - ('chamado_meta', string $chamadoId): ?array{numero?:int|string,area_nome?:string,area_cor?:string,categoria_nome?:string}
     *
     * @var null|callable(string,mixed):mixed
     */
    private static $resolvedor = null;

    public static function definirResolvedor(?callable $resolvedor): void
    {
        self::$resolvedor = $resolvedor;
    }

    /**
     * Enfileira notificações do chamado conforme o evento ocorrido.
     *
     * @param string               $evento  criado|recebido|atribuido|comentario|pendente|solucionado|fechado|transferido|reaberto
     * @param array<string, mixed> $chamado Linha ou dados do chamado
     * @param array<string, mixed> $extra   Contexto adicional (ex.: usuario_id, tipo, automatico, atendente_id)
     */
    public static function chamado(string $evento, array $chamado, array $extra = []): void
    {
        if (!Email::estaAtivo()) { return; }
        $eventoNorm = self::normalizarEvento($evento, $extra);
        if ($eventoNorm === null) {
            return;
        }

        $id = (string) ($chamado['id'] ?? '');
        if ($id === '') {
            return;
        }

        $chamado = self::enriquecerChamado($chamado);
        $autorId = self::identificarAutor($eventoNorm, $chamado, $extra);
        $destinatarios = self::montarDestinatarios($eventoNorm, $chamado, $extra, $autorId);
        if ($destinatarios === []) {
            return;
        }

        $numero = !empty($chamado['numero']) ? '#' . $chamado['numero'] : '#' . substr($id, 0, 8);
        $tituloChamado = self::limparLinha((string) ($chamado['titulo'] ?? t('Chamado')));
        $areaNome = self::limparLinha((string) ($chamado['area_nome'] ?? '—'));
        $categoriaNome = self::limparLinha((string) ($chamado['categoria_nome'] ?? '—'));
        $statusCodigo = (string) ($chamado['status'] ?? 'novo');
        $statusRotulo = isset(Chamados::STATUS[$statusCodigo]) ? t(Chamados::STATUS[$statusCodigo]) : $statusCodigo;
        $prioridadeNum = (int) ($chamado['prioridade'] ?? 4);
        $prioridadeRotulo = isset(Chamados::PRIORIDADES[$prioridadeNum]) ? t(Chamados::PRIORIDADES[$prioridadeNum]) : (string) $prioridadeNum;
        $corArea = (string) ($chamado['area_cor'] ?? '#024B37');
        $linkChamado = Http::absoluteUrl('/chamados/' . $id);
        $referencia = 'chamado:' . $id;

        foreach ($destinatarios as $dest) {
            $eventoTexto = (string) ($dest['evento_pref'] ?? $eventoNorm);
            [$assunto, $tituloEvento, $resumoEvento] = self::textosEvento($eventoNorm, $chamado, $eventoTexto);

            $dadosView = [
                'assunto'          => $assunto,
                'tituloEvento'     => $tituloEvento,
                'resumoEvento'     => $resumoEvento,
                'destinatarioNome' => (string) ($dest['nome'] ?? ''),
                'numero'           => $numero,
                'tituloChamado'    => $tituloChamado,
                'areaNome'         => $areaNome,
                'categoriaNome'    => $categoriaNome,
                'statusRotulo'     => $statusRotulo,
                'prioridadeRotulo' => $prioridadeRotulo,
                'corArea'          => $corArea,
                'linkChamado'      => $linkChamado,
            ];

            $texto = View::partial('emails/chamado_texto', $dadosView);
            $html = View::partial('emails/chamado_html', $dadosView);

            try {
                Email::enfileirar((string) $dest['email'], $assunto, $texto, $html, $referencia);
            } catch (Throwable) {
                // E-mail inválido no cadastro não deve interromper a transação do chamado.
            }
        }
    }

    /**
     * Determina a lista deduplicada de destinatários para o evento, excluindo quem realizou a ação
     * e respeitando as preferências individuais (PreferenciasNotificacao::quer).
     *
     * @param array<string, mixed> $chamado
     * @param array<string, mixed> $extra
     * @return array<int, array{id:string,nome:string,email:string}>
     */
    public static function montarDestinatarios(
        string $evento,
        array $chamado,
        array $extra = [],
        ?string $autorId = null
    ): array {
        $eventoNorm = self::normalizarEvento($evento, $extra);
        if ($eventoNorm === null) {
            return [];
        }

        $areaId = (string) ($chamado['area_id'] ?? '');
        $solicitanteId = (string) ($chamado['solicitante_id'] ?? '');
        $atendenteId = (string) ($extra['atendente_id'] ?? $chamado['atendente_id'] ?? '');

        $candidatos = [];
        switch ($eventoNorm) {
            case 'criado':
            case 'atendente.criado':
                foreach (self::buscarEquipeArea($areaId, $chamado, $extra) as $m) {
                    $m['evento_pref'] = 'atendente.criado';
                    $candidatos[] = $m;
                }
                if (!empty($extra['incluir_solicitante']) && $solicitanteId !== '') {
                    $u = self::buscarUsuario($solicitanteId, 'solicitante', $chamado, $extra);
                    if ($u !== null) {
                        $u['evento_pref'] = 'solicitante.criado';
                        $candidatos[] = $u;
                    }
                }
                break;

            case 'recebido':
            case 'solicitante.criado':
                if ($solicitanteId !== '') {
                    $u = self::buscarUsuario($solicitanteId, 'solicitante', $chamado, $extra);
                    if ($u !== null) {
                        $u['evento_pref'] = 'solicitante.criado';
                        $candidatos[] = $u;
                    }
                }
                break;

            case 'atribuido':
                if ($atendenteId !== '') {
                    $u = self::buscarUsuario($atendenteId, 'atendente', $chamado, $extra);
                    if ($u !== null) {
                        $u['evento_pref'] = 'atendente.atribuido';
                        $candidatos[] = $u;
                    }
                }
                if ($solicitanteId !== '') {
                    $uSol = self::buscarUsuario($solicitanteId, 'solicitante', $chamado, $extra);
                    if ($uSol !== null) {
                        $uSol['evento_pref'] = 'solicitante.atribuido';
                        $candidatos[] = $uSol;
                    }
                }
                break;

            case 'atendente.atribuido':
                if ($atendenteId !== '') {
                    $u = self::buscarUsuario($atendenteId, 'atendente', $chamado, $extra);
                    if ($u !== null) {
                        $u['evento_pref'] = 'atendente.atribuido';
                        $candidatos[] = $u;
                    }
                }
                break;

            case 'solicitante.atribuido':
                if ($solicitanteId !== '') {
                    $u = self::buscarUsuario($solicitanteId, 'solicitante', $chamado, $extra);
                    if ($u !== null) {
                        $u['evento_pref'] = 'solicitante.atribuido';
                        $candidatos[] = $u;
                    }
                }
                break;

            case 'comentario':
                if ($autorId !== null && $autorId !== '' && $autorId === $solicitanteId) {
                    // Solicitante comentou: avisa o atendente responsável (ou a equipe da área se ainda sem atendente).
                    if ($atendenteId !== '') {
                        $u = self::buscarUsuario($atendenteId, 'atendente', $chamado, $extra);
                        if ($u !== null) {
                            $u['evento_pref'] = 'atendente.comentario';
                            $candidatos[] = $u;
                        }
                    } else {
                        foreach (self::buscarEquipeArea($areaId, $chamado, $extra) as $m) {
                            $m['evento_pref'] = 'atendente.comentario';
                            $candidatos[] = $m;
                        }
                    }
                } else {
                    // Atendente ou equipe comentou: avisa o solicitante.
                    if ($solicitanteId !== '') {
                        $u = self::buscarUsuario($solicitanteId, 'solicitante', $chamado, $extra);
                        if ($u !== null) {
                            $u['evento_pref'] = 'solicitante.comentario';
                            $candidatos[] = $u;
                        }
                    }
                }
                break;

            case 'atendente.comentario':
                if ($atendenteId !== '') {
                    $u = self::buscarUsuario($atendenteId, 'atendente', $chamado, $extra);
                    if ($u !== null) {
                        $u['evento_pref'] = 'atendente.comentario';
                        $candidatos[] = $u;
                    }
                } else {
                    foreach (self::buscarEquipeArea($areaId, $chamado, $extra) as $m) {
                        $m['evento_pref'] = 'atendente.comentario';
                        $candidatos[] = $m;
                    }
                }
                break;

            case 'solicitante.comentario':
                if ($solicitanteId !== '') {
                    $u = self::buscarUsuario($solicitanteId, 'solicitante', $chamado, $extra);
                    if ($u !== null) {
                        $u['evento_pref'] = 'solicitante.comentario';
                        $candidatos[] = $u;
                    }
                }
                break;

            case 'pendente':
            case 'solicitante.pendente':
                if ($solicitanteId !== '') {
                    $u = self::buscarUsuario($solicitanteId, 'solicitante', $chamado, $extra);
                    if ($u !== null) {
                        $u['evento_pref'] = 'solicitante.pendente';
                        $candidatos[] = $u;
                    }
                }
                break;

            case 'solucionado':
            case 'solicitante.solucionado':
                if ($solicitanteId !== '') {
                    $u = self::buscarUsuario($solicitanteId, 'solicitante', $chamado, $extra);
                    if ($u !== null) {
                        $u['evento_pref'] = 'solicitante.solucionado';
                        $candidatos[] = $u;
                    }
                }
                break;

            case 'fechado':
                $fechadoPeloSolicitante = ($autorId !== null && $autorId !== '' && $autorId === $solicitanteId)
                    && empty($extra['automatico'])
                    && empty($chamado['fechado_automaticamente']);
                if ($fechadoPeloSolicitante) {
                    if ($atendenteId !== '') {
                        $u = self::buscarUsuario($atendenteId, 'atendente', $chamado, $extra);
                        if ($u !== null) {
                            $u['evento_pref'] = 'atendente.fechado';
                            $candidatos[] = $u;
                        }
                    } else {
                        foreach (self::buscarEquipeArea($areaId, $chamado, $extra) as $m) {
                            $m['evento_pref'] = 'atendente.fechado';
                            $candidatos[] = $m;
                        }
                    }
                } elseif ($solicitanteId !== '') {
                    $u = self::buscarUsuario($solicitanteId, 'solicitante', $chamado, $extra);
                    if ($u !== null) {
                        $u['evento_pref'] = 'solicitante.fechado';
                        $candidatos[] = $u;
                    }
                }
                break;

            case 'solicitante.fechado':
                if ($solicitanteId !== '') {
                    $u = self::buscarUsuario($solicitanteId, 'solicitante', $chamado, $extra);
                    if ($u !== null) {
                        $u['evento_pref'] = 'solicitante.fechado';
                        $candidatos[] = $u;
                    }
                }
                break;

            case 'transferido':
            case 'atendente.transferido':
                foreach (self::buscarEquipeArea($areaId, $chamado, $extra) as $m) {
                    $m['evento_pref'] = 'atendente.transferido';
                    $candidatos[] = $m;
                }
                break;

            case 'reaberto':
            case 'atendente.reaberto':
                if ($atendenteId !== '') {
                    $u = self::buscarUsuario($atendenteId, 'atendente', $chamado, $extra);
                    if ($u !== null) {
                        $u['evento_pref'] = 'atendente.reaberto';
                        $candidatos[] = $u;
                    }
                } else {
                    foreach (self::buscarEquipeArea($areaId, $chamado, $extra) as $m) {
                        $m['evento_pref'] = 'atendente.reaberto';
                        $candidatos[] = $m;
                    }
                }
                break;
        }

        $autorEmail = null;
        if ($autorId !== null && $autorId !== '') {
            $autor = self::buscarUsuario($autorId, 'autor', $chamado, $extra);
            if ($autor !== null && !empty($autor['email'])) {
                $autorEmail = mb_strtolower(trim((string) $autor['email']));
            }
        }

        $unicos = [];
        $porEmail = [];
        foreach ($candidatos as $c) {
            $cid = (string) ($c['id'] ?? '');
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
            if (isset($porEmail[$emailKey])) {
                continue;
            }
            $eventoPref = (string) ($c['evento_pref'] ?? $eventoNorm);
            if ($cid !== '' && !PreferenciasNotificacao::quer($cid, $eventoPref)) {
                continue;
            }
            $porEmail[$emailKey] = true;
            $unicos[] = [
                'id'          => $cid,
                'nome'        => (string) ($c['nome'] ?? ''),
                'email'       => $email,
                'evento_pref' => $eventoPref,
            ];
        }

        return $unicos;
    }

    private static function normalizarEvento(string $evento, array $extra): ?string
    {
        $tipoAcompanhamento = strtolower(trim((string) ($extra['tipo'] ?? '')));
        if ($tipoAcompanhamento === 'interno') {
            return null;
        }

        return match (strtolower(trim($evento))) {
            'criado', 'criar', 'novo'                               => 'criado',
            'recebido', 'aberto', 'solicitante.criado',
            'solicitante_criado'                                    => 'recebido',
            'atendente.criado', 'atendente_criado'                  => 'atendente.criado',
            'atribuido', 'atribuir', 'atribuicao'                   => 'atribuido',
            'atendente.atribuido', 'atendente_atribuido'            => 'atendente.atribuido',
            'solicitante.atribuido', 'solicitante_atribuido'        => 'solicitante.atribuido',
            'comentario', 'comentario_publico', 'acompanhar'        => 'comentario',
            'atendente.comentario', 'atendente_comentario'          => 'atendente.comentario',
            'solicitante.comentario', 'solicitante_comentario'      => 'solicitante.comentario',
            'pendente', 'solicitante.pendente',
            'solicitante_pendente', 'aguardando_solicitante'        => 'pendente',
            'solucionado', 'solucionar', 'solucao',
            'solicitante.solucionado', 'solicitante_solucionado'    => 'solucionado',
            'fechado', 'fechar'                                     => 'fechado',
            'solicitante.fechado', 'solicitante_fechado'            => 'solicitante.fechado',
            'transferido', 'transferir', 'transferencia',
            'atendente.transferido', 'atendente_transferido'        => 'transferido',
            'reaberto', 'reabrir', 'reabertura',
            'atendente.reaberto', 'atendente_reaberto'              => 'reaberto',
            default                                                 => null,
        };
    }

    /**
     * Identifica o usuário autor da ação para nunca notificá-lo do próprio ato.
     *
     * @param array<string, mixed> $chamado
     * @param array<string, mixed> $extra
     */
    private static function identificarAutor(string $eventoNorm, array $chamado, array $extra): ?string
    {
        if ($eventoNorm === 'recebido' || !empty($extra['automatico'])) {
            return null;
        }
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
        if ($eventoNorm === 'criado' && !empty($chamado['solicitante_id'])) {
            return (string) $chamado['solicitante_id'];
        }
        return null;
    }

    /**
     * @param array<string, mixed> $chamado
     * @return array{0:string,1:string,2:string} [assunto, tituloEvento, resumoEvento]
     */
    private static function textosEvento(string $eventoNorm, array $chamado, ?string $eventoPref = null): array
    {
        $id = (string) ($chamado['id'] ?? '');
        $numero = !empty($chamado['numero']) ? '#' . $chamado['numero'] : '#' . substr($id, 0, 8);
        $titulo = self::limparLinha((string) ($chamado['titulo'] ?? t('Chamado')));
        $prefixo = '[Salinas Access Gate] ' . t('Chamado {numero}', ['numero' => $numero]);
        $chave = $eventoPref !== null && $eventoPref !== '' ? $eventoPref : $eventoNorm;

        return match ($chave) {
            'criado', 'atendente.criado' => [
                mb_substr("{$prefixo} — " . t('Novo chamado') . ": {$titulo}", 0, 200),
                t('Novo chamado aberto na área'),
                t('Um novo chamado foi registrado na sua área de atendimento e aguarda triagem.'),
            ],
            'recebido', 'solicitante.criado' => [
                mb_substr("{$prefixo} — " . t('Chamado recebido') . ": {$titulo}", 0, 200),
                t('Seu chamado foi registrado'),
                t('Recebemos a sua solicitação de serviço. Acompanhe o andamento pelo sistema.'),
            ],
            'atribuido', 'atendente.atribuido' => [
                mb_substr("{$prefixo} — " . t('Atribuído a você') . ": {$titulo}", 0, 200),
                t('Chamado atribuído a você'),
                t('Você foi definido como atendente responsável por este chamado.'),
            ],
            'solicitante.atribuido' => [
                mb_substr("{$prefixo} — " . t('Em atendimento') . ": {$titulo}", 0, 200),
                t('Chamado atribuído a um atendente'),
                t('Um atendente assumiu o seu chamado e iniciou o atendimento.'),
            ],
            'comentario', 'solicitante.comentario', 'atendente.comentario' => [
                mb_substr("{$prefixo} — " . t('Novo comentário') . ": {$titulo}", 0, 200),
                t('Novo comentário público no chamado'),
                t('Há um novo acompanhamento público registrado neste chamado.'),
            ],
            'pendente', 'solicitante.pendente' => [
                mb_substr("{$prefixo} — " . t('Aguardando retorno') . ": {$titulo}", 0, 200),
                t('Chamado pendente (aguardando você)'),
                t('O atendimento aguarda uma informação ou confirmação sua para prosseguir.'),
            ],
            'transferido', 'atendente.transferido' => [
                mb_substr("{$prefixo} — " . t('Chamado transferido') . ": {$titulo}", 0, 200),
                t('Chamado transferido para a sua área'),
                t('Um chamado foi transferido para a sua área de atendimento e aguarda triagem.'),
            ],
            'reaberto', 'atendente.reaberto' => [
                mb_substr("{$prefixo} — " . t('Chamado reaberto') . ": {$titulo}", 0, 200),
                t('Chamado reaberto pelo solicitante'),
                t('O solicitante reabriu o chamado para revisão do atendimento.'),
            ],
            'solucionado', 'solicitante.solucionado' => [
                mb_substr("{$prefixo} — " . t('Solucionado') . ": {$titulo}", 0, 200),
                t('Chamado solucionado'),
                t('O chamado foi marcado como solucionado. Acesse o sistema para aprovar a solução ou reabrir o atendimento.'),
            ],
            default => [
                mb_substr("{$prefixo} — " . t('Fechado') . ": {$titulo}", 0, 200),
                t('Chamado encerrado'),
                t('Este chamado foi fechado. Acesse o sistema para consultar o histórico ou avaliar o atendimento.'),
            ],
        };
    }

    /**
     * @param array<string, mixed> $chamado
     * @return array<string, mixed>
     */
    private static function enriquecerChamado(array $chamado): array
    {
        $precisaMeta = empty($chamado['numero']) || empty($chamado['area_nome']) || empty($chamado['categoria_nome']);
        $id = (string) ($chamado['id'] ?? '');
        if (!$precisaMeta || !Uuid::valid($id)) {
            return $chamado;
        }

        if (self::$resolvedor !== null) {
            $meta = (self::$resolvedor)('chamado_meta', $id);
            return is_array($meta) ? array_merge($meta, $chamado) : $chamado;
        }

        try {
            $row = Database::one(
                'SELECT c.numero, c.status, c.prioridade, c.solicitante_id, c.atendente_id, c.area_id, c.categoria_id,
                        a.nome AS area_nome, a.cor AS area_cor, cat.nome AS categoria_nome
                   FROM chamados c
                   JOIN servico_areas a ON a.id = c.area_id
                   JOIN servico_categorias cat ON cat.id = c.categoria_id
                  WHERE c.id = ?',
                [$id]
            );
            if (is_array($row)) {
                foreach ($row as $k => $v) {
                    if (!isset($chamado[$k]) || $chamado[$k] === '' || $chamado[$k] === null) {
                        $chamado[$k] = $v;
                    }
                }
            }
        } catch (Throwable) {
            // Ignora falha de leitura complementar em ambiente sem banco.
        }

        return $chamado;
    }

    /**
     * @param array<string, mixed> $chamado
     * @param array<string, mixed> $extra
     * @return array{id:string,nome:string,email:string}|null
     */
    private static function buscarUsuario(string $usuarioId, string $papel, array $chamado, array $extra): ?array
    {
        // Atendente residual nunca recebe notificação após sair da equipe, mesmo com e-mail no snapshot.
        if ($papel === 'atendente') {
            foreach (self::buscarEquipeArea((string) ($chamado['area_id'] ?? ''), $chamado, $extra) as $membro) {
                if (($membro['id'] ?? '') === $usuarioId) { return $membro; }
            }
            return null;
        }
        if ($papel === 'solicitante' && !empty($chamado['solicitante_email'])) {
            return [
                'id'    => $usuarioId,
                'nome'  => (string) ($chamado['solicitante_nome'] ?? ''),
                'email' => (string) $chamado['solicitante_email'],
            ];
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

    /**
     * @param array<string, mixed> $chamado
     * @param array<string, mixed> $extra
     * @return array<int, array{id:string,nome:string,email:string}>
     */
    private static function buscarEquipeArea(string $areaId, array $chamado, array $extra): array
    {
        if (isset($extra['equipe']) && is_array($extra['equipe'])) {
            return $extra['equipe'];
        }
        if (isset($chamado['equipe']) && is_array($chamado['equipe'])) {
            return $chamado['equipe'];
        }
        if (self::$resolvedor !== null) {
            $lista = (self::$resolvedor)('equipe', $areaId);
            return is_array($lista) ? $lista : [];
        }
        if (!Uuid::valid($areaId)) {
            return [];
        }

        try {
            return Database::all(
                'SELECT u.id, u.nome, u.email
                   FROM servico_area_membros m
                   JOIN usuarios u ON u.id = m.usuario_id
                  WHERE m.area_id = ?
                    AND u.ativo = 1
                  ORDER BY u.nome',
                [$areaId]
            );
        } catch (Throwable) {
            return [];
        }
    }

    /** Revalida também mensagens que foram enfileiradas antes de uma revogação/transferência. */
    public static function destinatarioAtual(string $referencia, string $email): bool
    {
        if (!str_starts_with($referencia, 'chamado:')) { return true; }
        $id = substr($referencia, 8);
        if (!Uuid::valid($id)) { return false; }
        return (bool) Database::value('SELECT 1 FROM chamados c JOIN usuarios u ON u.email = ? AND u.ativo = 1 WHERE c.id = ? AND c.anonimizado_em IS NULL AND (c.solicitante_id = u.id OR EXISTS (SELECT 1 FROM servico_area_membros m WHERE m.area_id = c.area_id AND m.usuario_id = u.id))', [$email, $id]);
    }

    private static function limparLinha(string $valor): string
    {
        return trim((string) preg_replace('/[\r\n\0]+/', ' ', $valor));
    }
}
