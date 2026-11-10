<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use DateTimeImmutable;

/**
 * Indicadores do portal de chamados: volume, backlog, cumprimento de SLA, tempo de solução e satisfação por área.
 * O cumprimento de SLA usa Chamados::situacaoSolucao (mesma régua da fila: expediente, pausas e prorrogações).
 */
final class IndicadoresChamados
{
    /** Teto de linhas analisadas na tela (o CSV não tem teto). */
    public const LIMITE = 5000;

    /**
     * @param ?list<string> $areas null = todas; lista = restringe às áreas informadas
     * @return array{where: string, params: list<mixed>}
     */
    public static function filtro(string $inicio, string $fim, ?array $areas, string $areaId = ''): array
    {
        $where = ['c.created_at >= ?', 'c.created_at < DATE_ADD(?, INTERVAL 1 DAY)'];
        $params = [$inicio, $fim];
        if ($areas !== null) {
            if ($areas === []) { return ['where' => '1 = 0', 'params' => []]; }
            $where[] = 'c.area_id IN (' . implode(',', array_fill(0, count($areas), '?')) . ')';
            array_push($params, ...$areas);
        }
        if ($areaId !== '') { $where[] = 'c.area_id = ?'; $params[] = $areaId; }
        return ['where' => implode(' AND ', $where), 'params' => $params];
    }

    public static function sql(string $where): string
    {
        return 'SELECT c.*, a.nome AS area_nome, cat.nome AS categoria_nome, s.nome AS solicitante_nome, u.nome AS atendente_nome, sat.nota
                  FROM chamados c
                  JOIN servico_areas a ON a.id = c.area_id
                  LEFT JOIN servico_categorias cat ON cat.id = c.categoria_id
                  LEFT JOIN usuarios s ON s.id = c.solicitante_id
                  LEFT JOIN usuarios u ON u.id = c.atendente_id
                  LEFT JOIN chamado_satisfacao sat ON sat.chamado_id = c.id
                 WHERE ' . $where . ' ORDER BY c.created_at DESC';
    }

    /**
     * Resume as linhas de chamados (já filtradas) no total e por área.
     * @param iterable<array<string, mixed>> $chamados
     * @return array{geral: array<string, mixed>, areas: array<string, array<string, mixed>>}
     */
    public static function resumir(iterable $chamados, DateTimeImmutable $agora): array
    {
        $novo = static fn(string $nome): array => ['nome' => $nome, 'abertos' => 0, 'backlog' => 0, 'sem_atendente' => 0, 'concluidos' => 0,
            'no_prazo' => 0, 'vencidos_abertos' => 0, 'seg_solucao' => 0, 'notas' => 0, 'soma_notas' => 0];
        $geral = $novo('');
        $areas = [];
        foreach ($chamados as $c) {
            $aid = (string) $c['area_id'];
            $areas[$aid] ??= $novo((string) ($c['area_nome'] ?? ''));
            $concluido = in_array($c['status'], ['solucionado', 'fechado'], true);
            $situacao = Chamados::situacaoSolucao($c, $agora);
            foreach ([&$geral, &$areas[$aid]] as &$r) {
                $r['abertos']++;
                if ($concluido) {
                    $r['concluidos']++;
                    if ($situacao !== 'vencido') { $r['no_prazo']++; }
                    if (!empty($c['solucionado_em'])) {
                        $r['seg_solucao'] += max(0, strtotime((string) $c['solucionado_em']) - strtotime((string) $c['created_at']) - (int) ($c['segundos_pendente'] ?? 0));
                    }
                } else {
                    $r['backlog']++;
                    if (empty($c['atendente_id'])) { $r['sem_atendente']++; }
                    if ($situacao === 'vencido') { $r['vencidos_abertos']++; }
                }
                if (isset($c['nota']) && $c['nota'] !== null) { $r['notas']++; $r['soma_notas'] += (int) $c['nota']; }
            }
            unset($r);
        }
        $fechar = static function (array $r): array {
            $r['pct_no_prazo'] = $r['concluidos'] > 0 ? (int) round($r['no_prazo'] / $r['concluidos'] * 100) : null;
            $r['horas_solucao'] = $r['concluidos'] > 0 ? round($r['seg_solucao'] / $r['concluidos'] / 3600, 1) : null;
            $r['nota_media'] = $r['notas'] > 0 ? round($r['soma_notas'] / $r['notas'], 1) : null;
            return $r;
        };
        uasort($areas, static fn(array $a, array $b): int => $b['abertos'] <=> $a['abertos']);
        return ['geral' => $fechar($geral), 'areas' => array_map($fechar, $areas)];
    }
}
