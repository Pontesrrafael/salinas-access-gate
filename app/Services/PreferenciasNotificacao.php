<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Uuid;
use Throwable;

/**
 * Preferências individuais de notificação por e-mail nos chamados (tabela usuario_notificacoes).
 *
 * Regra de armazenamento:
 * - Ausência de linha na tabela usuario_notificacoes equivale a ligado (ativo = 1 / true).
 * - O padrão para todos os usuários é receber todas as notificações aplicáveis ao seu papel.
 */
final class PreferenciasNotificacao
{
    /**
     * Eventos em que o usuário atua como solicitante do chamado.
     *
     * @var array<string, string>
     */
    public const EVENTOS_SOLICITANTE = [
        'solicitante.criado'      => 'Chamado recebido / aberto',
        'solicitante.atribuido'   => 'Atribuído a um atendente',
        'solicitante.comentario'  => 'Novo comentário público',
        'solicitante.pendente'    => 'Mudou para pendente (aguardando você)',
        'solicitante.solucionado' => 'Solucionado (aprovar ou reabrir)',
        'solicitante.fechado'     => 'Fechado ou fechado automaticamente',
    ];

    /**
     * Eventos em que o usuário atua como atendente ou membro da equipe da área.
     *
     * @var array<string, string>
     */
    public const EVENTOS_ATENDENTE = [
        'atendente.criado'      => 'Novo chamado na minha área',
        'atendente.atribuido'   => 'Chamado atribuído a mim',
        'atendente.comentario'  => 'Comentário do solicitante',
        'atendente.transferido' => 'Chamado transferido para minha área',
        'atendente.reaberto'    => 'Reaberto pelo solicitante',
    ];

    /**
     * Todos os eventos configuráveis.
     *
     * @var array<string, string>
     */
    public const EVENTOS = [
        'solicitante.criado'      => 'Chamado recebido / aberto',
        'solicitante.atribuido'   => 'Atribuído a um atendente',
        'solicitante.comentario'  => 'Novo comentário público',
        'solicitante.pendente'    => 'Mudou para pendente (aguardando você)',
        'solicitante.solucionado' => 'Solucionado (aprovar ou reabrir)',
        'solicitante.fechado'     => 'Fechado ou fechado automaticamente',
        'atendente.criado'        => 'Novo chamado na minha área',
        'atendente.atribuido'     => 'Chamado atribuído a mim',
        'atendente.comentario'    => 'Comentário do solicitante',
        'atendente.transferido'   => 'Chamado transferido para minha área',
        'atendente.reaberto'      => 'Reaberto pelo solicitante',
    ];

    /**
     * Armazenamento em memória para testes sem banco de dados: [usuarioId => [evento => bool]].
     *
     * @var array<string, array<string, bool|int>>|null
     */
    private static ?array $memoria = null;

    /**
     * Resolvedor opcional para testes sem banco: fn(string $usuarioId): array<string, bool|int>
     *
     * @var null|callable(string):array
     */
    private static $resolvedor = null;

    /**
     * Ativa armazenamento em memória para testes sem banco (passe null para restaurar leitura do banco).
     *
     * @param array<string, array<string, bool|int>>|null $inicial
     */
    public static function usarMemoria(?array $inicial = []): void
    {
        self::$memoria = $inicial;
    }

    /**
     * Define resolvedor customizado para testes sem banco.
     */
    public static function definirResolvedor(?callable $resolvedor): void
    {
        self::$resolvedor = $resolvedor;
    }

    /**
     * Mapa padrão com todos os eventos ligados (true).
     *
     * @return array<string, bool>
     */
    /** @return array<string, string> eventos do solicitante (evento => rótulo) */
    public static function eventosSolicitante(): array
    {
        return self::EVENTOS_SOLICITANTE;
    }

    /** @return array<string, string> eventos do atendente/equipe (evento => rótulo) */
    public static function eventosAtendente(): array
    {
        return self::EVENTOS_ATENDENTE;
    }

    /** @return array<string, string> todos os eventos configuráveis (evento => rótulo) */
    public static function todosEventos(): array
    {
        return self::EVENTOS;
    }

    public static function padrao(): array
    {
        return array_fill_keys(array_keys(self::EVENTOS), true);
    }

    /**
     * Lista de eventos disponíveis conforme o usuário seja ou não membro de área / Admin.
     *
     * @return array<string, string>
     */
    public static function eventosDisponiveis(bool $atende = true): array
    {
        return $atende ? self::EVENTOS : self::EVENTOS_SOLICITANTE;
    }

    /**
     * Normaliza chaves ou apelidos de evento para o código canônico em EVENTOS.
     */
    public static function normalizarEvento(string $evento): ?string
    {
        $chave = strtolower(trim($evento));
        if (isset(self::EVENTOS[$chave])) {
            return $chave;
        }

        return match ($chave) {
            'solicitante_criado', 'solicitante.aberto', 'solicitante_aberto',
            'solicitante.recebido', 'solicitante_recebido', 'recebido', 'aberto' => 'solicitante.criado',
            'solicitante_atribuido'                                              => 'solicitante.atribuido',
            'solicitante_comentario'                                             => 'solicitante.comentario',
            'solicitante_pendente', 'pendente', 'aguardando_solicitante'         => 'solicitante.pendente',
            'solicitante_solucionado', 'solucionado', 'solucao'                  => 'solicitante.solucionado',
            'solicitante_fechado', 'fechado'                                     => 'solicitante.fechado',
            'atendente_criado', 'atendente.novo', 'atendente_novo',
            'equipe.criado', 'equipe_criado', 'criado', 'novo'                   => 'atendente.criado',
            'atendente_atribuido', 'equipe.atribuido', 'atribuido'               => 'atendente.atribuido',
            'atendente_comentario', 'equipe.comentario'                          => 'atendente.comentario',
            'atendente_transferido', 'equipe.transferido',
            'transferido', 'transferencia'                                       => 'atendente.transferido',
            'atendente_reaberto', 'equipe.reaberto', 'reaberto', 'reabertura'    => 'atendente.reaberto',
            default                                                              => null,
        };
    }

    /**
     * Retorna todas as preferências do usuário (ausência de linha = true).
     *
     * @return array<string, bool>
     */
    public static function todas(string $usuarioId): array
    {
        $mapa = self::padrao();
        $uid = trim($usuarioId);
        if ($uid === '') {
            return $mapa;
        }

        $linhas = self::carregarBruto($uid);
        foreach ($linhas as $ev => $ativo) {
            $norm = self::normalizarEvento((string) $ev);
            if ($norm !== null) {
                $mapa[$norm] = (bool) $ativo;
            }
        }

        return $mapa;
    }

    /**
     * Verifica se o usuário deseja receber e-mail para o evento informado.
     * Ausência de configuração retorna true (padrão ligado).
     */
    public static function quer(string $usuarioId, string $evento): bool
    {
        $uid = trim($usuarioId);
        if ($uid === '') {
            return false; // sem usuário não há destinatário
        }

        $todas = self::todas($uid);
        $chave = strtolower(trim($evento));

        // Fechamento aprovado pelo solicitante avisa a equipe, salvo quando o atendente desligou todos os alertas de atendente.
        if ($chave === 'atendente.fechado' || $chave === 'atendente_fechado') {
            foreach (array_keys(self::EVENTOS_ATENDENTE) as $evAtend) {
                if (!empty($todas[$evAtend])) {
                    return true;
                }
            }
            return false;
        }

        $norm = self::normalizarEvento($chave);
        if ($norm === null) {
            return true;
        }

        return $todas[$norm] ?? true;
    }

    /**
     * Define uma preferência individual (em memória nos testes ou no banco em produção).
     */
    public static function definir(string $usuarioId, string $evento, bool $ativo): void
    {
        $uid = trim($usuarioId);
        $norm = self::normalizarEvento($evento);
        if ($uid === '' || $norm === null) {
            return;
        }

        if (self::$memoria !== null) {
            self::$memoria[$uid][$norm] = $ativo;
            return;
        }

        Database::run(
            'INSERT INTO usuario_notificacoes (usuario_id, evento, ativo)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE ativo = VALUES(ativo), updated_at = CURRENT_TIMESTAMP',
            [$uid, $norm, $ativo ? 1 : 0]
        );
    }

    /**
     * Salva o conjunto de preferências do usuário.
     *
     * @param array<int|string, mixed> $marcados Lista de chaves marcadas (ex.: ['solicitante.criado']) ou mapa [evento => bool]
     * @return array<string, int> Mapa persistido [evento => 0|1]
     */
    public static function salvar(string $usuarioId, array $marcados, bool $incluirAtendente = true): array
    {
        $uid = trim($usuarioId);
        $disponiveis = self::eventosDisponiveis($incluirAtendente);

        $ativosSet = [];
        $ehLista = array_is_list($marcados);
        foreach ($marcados as $k => $v) {
            if ($ehLista) {
                if (is_string($v) || is_int($v)) {
                    $norm = self::normalizarEvento((string) $v);
                    if ($norm !== null) {
                        $ativosSet[$norm] = true;
                    }
                }
            } else {
                $norm = self::normalizarEvento((string) $k);
                if ($norm !== null && in_array($v, [true, 1, '1', 'on', 'sim', 'true'], true)) {
                    $ativosSet[$norm] = true;
                }
            }
        }

        $persistido = [];
        foreach (array_keys($disponiveis) as $ev) {
            $persistido[$ev] = isset($ativosSet[$ev]) ? 1 : 0;
        }

        if (self::$memoria !== null) {
            foreach ($persistido as $ev => $val) {
                self::$memoria[$uid][$ev] = $val === 1;
            }
            return $persistido;
        }

        Database::transaction(static function () use ($uid, $persistido): void {
            foreach ($persistido as $ev => $val) {
                Database::run(
                    'INSERT INTO usuario_notificacoes (usuario_id, evento, ativo)
                     VALUES (?, ?, ?)
                     ON DUPLICATE KEY UPDATE ativo = VALUES(ativo), updated_at = CURRENT_TIMESTAMP',
                    [$uid, $ev, $val]
                );
            }
        });

        return $persistido;
    }

    /**
     * Desliga todas as notificações disponíveis para o usuário.
     *
     * @return array<string, int>
     */
    public static function desligarTodos(string $usuarioId, bool $incluirAtendente = true): array
    {
        return self::salvar($usuarioId, [], $incluirAtendente);
    }

    /**
     * Restaura o padrão (tudo ligado), removendo as linhas personalizadas do usuário.
     */
    public static function restaurarPadrao(string $usuarioId): void
    {
        $uid = trim($usuarioId);
        if ($uid === '') {
            return;
        }

        if (self::$memoria !== null) {
            unset(self::$memoria[$uid]);
            return;
        }

        Database::run('DELETE FROM usuario_notificacoes WHERE usuario_id = ?', [$uid]);
    }

    /**
     * @return array<string, bool>
     */
    private static function carregarBruto(string $usuarioId): array
    {
        if (self::$memoria !== null) {
            $dados = self::$memoria[$usuarioId] ?? [];
            $out = [];
            foreach ($dados as $k => $v) {
                $out[(string) $k] = (bool) $v;
            }
            return $out;
        }

        if (self::$resolvedor !== null) {
            $dados = (self::$resolvedor)($usuarioId);
            if (!is_array($dados)) {
                return [];
            }
            $out = [];
            foreach ($dados as $k => $v) {
                if (is_array($v) && isset($v['evento'])) {
                    $out[(string) $v['evento']] = (bool) ($v['ativo'] ?? true);
                } else {
                    $out[(string) $k] = (bool) $v;
                }
            }
            return $out;
        }

        if (!Uuid::valid($usuarioId)) {
            return [];
        }

        try {
            $rows = Database::all(
                'SELECT evento, ativo FROM usuario_notificacoes WHERE usuario_id = ?',
                [$usuarioId]
            );
            $out = [];
            foreach ($rows as $r) {
                if (isset($r['evento'])) {
                    $out[(string) $r['evento']] = (int) ($r['ativo'] ?? 1) === 1;
                }
            }
            return $out;
        } catch (Throwable) {
            return [];
        }
    }
}
