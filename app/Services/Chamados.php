<?php
declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use InvalidArgumentException;
use App\Core\{Audit, Database, Uuid};

/** Regras de chamados. Os prazos são 24x7, no fuso configurado na aplicação. */
final class Chamados
{
    public const STATUS = [
        'novo' => 'Novo', 'atribuido' => 'Em atendimento (atribuído)',
        'planejado' => 'Em atendimento (planejado)', 'pendente' => 'Pendente',
        'solucionado' => 'Solucionado', 'fechado' => 'Fechado',
    ];
    public const URGENCIAS = [1 => 'Muito alta', 2 => 'Alta', 3 => 'Média', 4 => 'Baixa', 5 => 'Muito baixa'];
    public const EXPLICACOES = [
        1 => 'Atendimento imediato: operação parada ou risco iminente.',
        2 => 'Atendimento urgente: atividade importante comprometida.',
        3 => 'Atendimento normal: dificuldade com alternativa temporária.',
        4 => 'Pode aguardar: impacto pequeno na rotina.',
        5 => 'Pode ser planejado: melhoria ou orientação sem interrupção.',
    ];
    public const IMPACTOS = [
        1 => 'A — Não afeta a operação', 2 => 'B — Afeta parcialmente uma atividade ou usuário',
        3 => 'C — Afeta um setor ou grupo', 4 => 'D — Processo crítico, parada ou risco',
    ];
    public const PRIORIDADES = [1 => 'Crítica', 2 => 'Muito alta', 3 => 'Alta', 4 => 'Média', 5 => 'Baixa', 6 => 'Muito baixa'];
    public const PRAZOS = ['no_prazo' => 'No prazo', 'vencendo' => 'Vencendo', 'vencido' => 'Vencido'];
    public const TIPOS = ['comentario' => 'Comentário público', 'interno' => 'Nota interna', 'solucao' => 'Solução', 'status' => 'Status', 'atribuicao' => 'Atribuição'];

    /**
     * Matriz inspirada no GLPI, com seis níveis locais (1 = mais crítico).
     * Urgência: 1 muito alta ... 5 muito baixa; impacto: A=1 ... D=4.
     *                A  B  C  D
     * Muito alta     3  2  1  1
     * Alta           4  3  2  1
     * Média          5  4  3  2
     * Baixa          6  5  4  3
     * Muito baixa    6  6  5  4
     */
    public static function prioridade(int $urgencia, int $impacto): int
    {
        if (!isset(self::URGENCIAS[$urgencia], self::IMPACTOS[$impacto])) {
            throw new InvalidArgumentException(t('Urgência ou impacto fora da faixa.'));
        }
        return [[3, 2, 1, 1], [4, 3, 2, 1], [5, 4, 3, 2], [6, 5, 4, 3], [6, 6, 5, 4]][$urgencia - 1][$impacto - 1];
    }

    public static function prazo(DateTimeImmutable $inicio, ?int $minutos): ?DateTimeImmutable
    {
        if ($minutos === null) { return null; }
        if ($minutos <= 0 || $minutos > 5256000) {
            throw new InvalidArgumentException(t('SLA inválido.'));
        }
        return $inicio->modify('+' . $minutos . ' minutes');
    }

    /** O prazo persistido é a base; a pausa é somada apenas na leitura, nunca duas vezes. */
    public static function prazoSolucao(array $chamado, DateTimeImmutable $agora): ?DateTimeImmutable
    {
        if (empty($chamado['prazo_solucao'])) { return null; }
        $segundos = max(0, (int) ($chamado['segundos_pendente'] ?? 0));
        if (($chamado['status'] ?? '') === 'pendente' && !empty($chamado['pendente_desde'])) {
            $segundos += max(0, $agora->getTimestamp() - (new DateTimeImmutable($chamado['pendente_desde']))->getTimestamp());
        }
        return (new DateTimeImmutable($chamado['prazo_solucao']))->modify('+' . $segundos . ' seconds');
    }

    public static function transicoes(string $status): array
    {
        return match ($status) {
            'novo' => ['atribuido', 'planejado', 'pendente', 'solucionado'],
            'atribuido' => ['planejado', 'pendente', 'solucionado'],
            'planejado' => ['atribuido', 'pendente', 'solucionado'],
            'pendente' => ['atribuido', 'planejado', 'solucionado'],
            'solucionado' => ['fechado', 'atribuido'],
            default => [],
        };
    }

    public static function podeTransicionar(string $origem, string $destino): bool
    {
        return in_array($destino, self::transicoes($origem), true);
    }

    /** Alterações determinísticas, aplicadas pelo controller com SELECT FOR UPDATE. */
    public static function mudarStatus(array $chamado, string $destino, DateTimeImmutable $agora): array
    {
        if (!self::podeTransicionar($chamado['status'], $destino)) {
            throw new InvalidArgumentException(t('Transição de status não permitida.'));
        }
        $mudancas = ['status' => $destino];
        if ($chamado['status'] === 'pendente') {
            $inicio = new DateTimeImmutable($chamado['pendente_desde']);
            $mudancas['segundos_pendente'] = (int) $chamado['segundos_pendente'] + max(0, $agora->getTimestamp() - $inicio->getTimestamp());
            $mudancas['pendente_desde'] = null;
        }
        if ($destino === 'pendente') { $mudancas['pendente_desde'] = $agora->format('Y-m-d H:i:s'); }
        if ($destino === 'solucionado') { $mudancas['solucionado_em'] = $agora->format('Y-m-d H:i:s'); }
        if ($destino === 'fechado') { $mudancas['fechado_em'] = $agora->format('Y-m-d H:i:s'); }
        if ($chamado['status'] === 'solucionado' && $destino === 'atribuido') {
            $mudancas['solucionado_em'] = null;
            $mudancas['fechado_em'] = null;
        }
        return $mudancas;
    }

    public static function situacaoPrazo(DateTimeImmutable $inicio, ?DateTimeImmutable $prazo, DateTimeImmutable $agora): string
    {
        if ($prazo === null) { return 'no_prazo'; }
        $restante = $prazo->getTimestamp() - $agora->getTimestamp();
        if ($restante < 0) { return 'vencido'; }
        $total = max(0, $prazo->getTimestamp() - $inicio->getTimestamp());
        return $restante <= $total * 0.2 ? 'vencendo' : 'no_prazo';
    }

    /** Mede o orçamento original do SLA; a pausa não aumenta a janela de 20%. */
    public static function situacaoSolucao(array $chamado, DateTimeImmutable $agora): string
    {
        $prazo = self::prazoSolucao($chamado, $agora);
        if ($prazo === null) { return 'no_prazo'; }
        $fim = new DateTimeImmutable($chamado['solucionado_em'] ?? $chamado['fechado_em'] ?? $agora->format('Y-m-d H:i:s'));
        $total = max(0, (new DateTimeImmutable($chamado['prazo_solucao']))->getTimestamp() - (new DateTimeImmutable($chamado['created_at']))->getTimestamp());
        $restante = $prazo->getTimestamp() - $fim->getTimestamp();
        return $restante < 0 ? 'vencido' : ($restante <= $total * 0.2 ? 'vencendo' : 'no_prazo');
    }

    public static function podeFecharAutomaticamente(array $chamado, DateTimeImmutable $agora, int $dias = 7): bool
    {
        return $dias > 0 && $chamado['status'] === 'solucionado' && !empty($chamado['solucionado_em'])
            && (new DateTimeImmutable($chamado['solucionado_em']))->modify('+' . $dias . ' days') <= $agora;
    }

    /**
     * Entrada para o agendador do gerente: não executa em GET nem no carregamento do menu.
     * Configuração zero desliga o fechamento. Revalida sob lock para não fechar uma reabertura concorrente.
     */
    public static function fecharAutomaticamente(int $limite = 100): int
    {
        $dias = Settings::int('chamados_fechar_dias', 7);
        if (!Settings::bool('chamados_ativo') || $dias <= 0) { return 0; }
        $agora = new DateTimeImmutable();
        $corte = $agora->modify('-' . $dias . ' days')->format('Y-m-d H:i:s');
        $candidatos = Database::all("SELECT id FROM chamados WHERE status = 'solucionado' AND solucionado_em <= ? ORDER BY solucionado_em LIMIT " . max(1, min(1000, $limite)), [$corte]);
        $total = 0;
        foreach ($candidatos as $item) {
            $total += Database::transaction(static function () use ($item, $agora, $dias): int {
                $c = Database::one('SELECT * FROM chamados WHERE id = ? FOR UPDATE', [$item['id']]);
                if (!$c || !self::podeFecharAutomaticamente($c, $agora, $dias)) { return 0; }
                Database::update('chamados', self::mudarStatus($c, 'fechado', $agora), 'id = ?', [$c['id']]);
                Database::insert('chamado_acompanhamentos', [
                    'id' => Uuid::v4(), 'chamado_id' => $c['id'], 'usuario_id' => null,
                    'tipo' => 'status', 'texto' => 'fechado',
                ]);
                Audit::log('chamados', $c['id'], 'fechar', ['automatico' => true, 'dias' => $dias]);
                return 1;
            });
        }
        return $total;
    }

    public static function corStatus(string $status): string
    {
        return match ($status) { 'novo', 'pendente' => 'aviso', 'atribuido', 'planejado' => 'info', 'solucionado' => 'sucesso', default => 'neutro' };
    }

    public static function corPrioridade(int $prioridade): string
    {
        return match ($prioridade) { 1, 2 => 'perigo', 3 => 'aviso', 4 => 'info', default => 'neutro' };
    }

    /** Caminhos completos para select sem JS; evita loops em cadastros inconsistentes. */
    public static function categoriasEmArvore(array $categorias): array
    {
        $porId = array_column($categorias, null, 'id');
        foreach ($categorias as &$categoria) {
            $nomes = [$categoria['nome']];
            $visitados = [$categoria['id'] => true];
            $pai = $categoria['parent_id'];
            while ($pai !== null && isset($porId[$pai]) && !isset($visitados[$pai])) {
                $visitados[$pai] = true;
                array_unshift($nomes, $porId[$pai]['nome']);
                $pai = $porId[$pai]['parent_id'];
            }
            $categoria['caminho'] = implode(' › ', $nomes);
        }
        unset($categoria);
        usort($categorias, static fn ($a, $b) => strnatcasecmp($a['caminho'], $b['caminho']));
        return $categorias;
    }
}
