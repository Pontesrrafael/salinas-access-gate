<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Http;
use App\Services\Chamados;
use App\Services\Csv;
use App\Services\IndicadoresChamados;
use DateTimeImmutable;

/**
 * Serviços → Indicadores de chamados (/chamados/indicadores) e exportação CSV.
 * Admin/TI e Supervisor veem todas as áreas; o Gestor de área vê somente as áreas que gere.
 */
final class ChamadosRelatorioController extends Controller
{
    public function index(): void
    {
        [$f, $areas, $filtro] = $this->contexto();
        $linhas = Database::all(IndicadoresChamados::sql($filtro['where']) . ' LIMIT ' . (IndicadoresChamados::LIMITE + 1), $filtro['params']);
        $truncado = count($linhas) > IndicadoresChamados::LIMITE;
        if ($truncado) { array_pop($linhas); }
        $this->view('chamados/indicadores', [
            'titulo'    => t('Indicadores de chamados'),
            'descricao' => t('Volume, backlog, cumprimento de SLA e satisfação por área'),
            'f'         => $f,
            'areas'     => $areas,
            'resumo'    => IndicadoresChamados::resumir($linhas, new DateTimeImmutable()),
            'truncado'  => $truncado,
        ]);
    }

    public function csv(): void
    {
        [$f, , $filtro] = $this->contexto();
        $rows = Database::run(IndicadoresChamados::sql($filtro['where']), $filtro['params']);
        Audit::log('chamados', null, 'EXPORT_CSV', ['filtros' => $f]);
        $agora = new DateTimeImmutable();
        Csv::download('chamados-' . date('Ymd-His') . '.csv',
            [t('Número'), t('Título'), t('Área'), t('Categoria'), t('Status'), t('Prioridade'), t('Urgência'), t('Solicitante'), t('Atendente'),
             t('Aberto em'), t('Prazo de solução'), t('Solucionado em'), t('Fechado em'), t('Situação do SLA'), t('Nota')],
            (function () use ($rows, $agora) {
                while ($c = $rows->fetch()) {
                    yield [$c['numero'], $c['titulo'], $c['area_nome'], $c['categoria_nome'], t(Chamados::STATUS[$c['status']] ?? $c['status']),
                        t(Chamados::PRIORIDADES[(int) $c['prioridade']] ?? ''), t(Chamados::URGENCIAS[(int) $c['urgencia']] ?? ''),
                        $c['solicitante_nome'], $c['atendente_nome'], fmt_data_hora($c['created_at']), fmt_data_hora($c['prazo_solucao']),
                        fmt_data_hora($c['solucionado_em']), fmt_data_hora($c['fechado_em']),
                        t(Chamados::PRAZOS[Chamados::situacaoSolucao($c, $agora)] ?? ''), $c['nota']];
                }
            })());
    }

    /** @return array{0: array{inicio: string, fim: string, area_id: string}, 1: list<array<string, mixed>>, 2: array{where: string, params: list<mixed>}} */
    private function contexto(): array
    {
        Auth::requireLogin();
        $geridas = Auth::areasGeridas();                       // null = admin; [] = não é gestor
        $todas = Auth::can('relatorios.ver');
        if (!$todas && !$geridas) { Http::abort(403, 'Seu perfil não tem permissão para esta ação.'); }
        $escopo = $todas ? null : $geridas;

        $data = static fn(string $v, string $padrao): string => preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : $padrao;
        $f = [
            'inicio'  => $data(Http::str('inicio'), date('Y-m-d', strtotime('-30 days'))),
            'fim'     => $data(Http::str('fim'), date('Y-m-d')),
            'area_id' => Http::str('area_id'),
        ];
        $areas = $escopo === null
            ? Database::all('SELECT id, nome FROM servico_areas ORDER BY ordem, nome')
            : Database::all('SELECT id, nome FROM servico_areas WHERE id IN (' . implode(',', array_fill(0, count($escopo), '?')) . ') ORDER BY ordem, nome', $escopo);
        if (!in_array($f['area_id'], array_column($areas, 'id'), true)) { $f['area_id'] = ''; }
        return [$f, $areas, IndicadoresChamados::filtro($f['inicio'], $f['fim'], $escopo, $f['area_id'])];
    }
}
