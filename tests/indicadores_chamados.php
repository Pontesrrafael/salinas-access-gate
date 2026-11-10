<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Services\IndicadoresChamados;

$checks = 0;
function expect(bool $ok, string $label): void
{
    global $checks;
    if (!$ok) {
        throw new RuntimeException($label);
    }
    $checks++;
}

$agora = new DateTimeImmutable('2026-11-10 12:00:00');
$base = ['calendario_sla' => null, 'sla_inicio' => null, 'segundos_pendente' => 0, 'pendente_desde' => null, 'fechado_em' => null, 'nota' => null];
$linhas = [
    // Facilities: solucionado no prazo em 2 h (1 h pendente descontada), nota 5
    ['area_id' => 'f', 'area_nome' => 'Facilities', 'status' => 'fechado', 'atendente_id' => 'u1', 'created_at' => '2026-11-09 08:00:00',
        'prazo_solucao' => '2026-11-09 18:00:00', 'solucionado_em' => '2026-11-09 11:00:00', 'segundos_pendente' => 3600, 'nota' => 5] + $base,
    // Facilities: solucionado fora do prazo, nota 2
    ['area_id' => 'f', 'area_nome' => 'Facilities', 'status' => 'solucionado', 'atendente_id' => 'u1', 'created_at' => '2026-11-08 08:00:00',
        'prazo_solucao' => '2026-11-08 10:00:00', 'solucionado_em' => '2026-11-08 14:00:00', 'nota' => 2] + $base,
    // Facilities: aberto, vencido e sem atendente
    ['area_id' => 'f', 'area_nome' => 'Facilities', 'status' => 'novo', 'atendente_id' => null, 'created_at' => '2026-11-09 08:00:00',
        'prazo_solucao' => '2026-11-09 12:00:00', 'solucionado_em' => null] + $base,
    // SSMA: aberto, no prazo, com atendente
    ['area_id' => 's', 'area_nome' => 'SSMA', 'status' => 'atribuido', 'atendente_id' => 'u2', 'created_at' => '2026-11-10 08:00:00',
        'prazo_solucao' => '2026-11-12 08:00:00', 'solucionado_em' => null] + $base,
];
$r = IndicadoresChamados::resumir($linhas, $agora);
$g = $r['geral'];
expect($g['abertos'] === 4 && $g['backlog'] === 2 && $g['concluidos'] === 2, 'Totais gerais devem contar abertos, backlog e concluídos.');
expect($g['sem_atendente'] === 1 && $g['vencidos_abertos'] === 1, 'Backlog deve separar sem atendente e SLA vencido.');
expect($g['pct_no_prazo'] === 50, 'Metade dos concluídos ficou no prazo.');
expect($g['horas_solucao'] === 4.0, 'Tempo médio desconta a pausa: (2 h + 6 h) / 2.');
expect($g['nota_media'] === 3.5 && $g['notas'] === 2, 'Satisfação média considera só chamados avaliados.');
expect(array_keys($r['areas']) === ['f', 's'], 'Áreas ordenadas pelo volume.');
expect($r['areas']['s']['pct_no_prazo'] === null && $r['areas']['s']['nota_media'] === null && $r['areas']['s']['horas_solucao'] === null,
    'Área sem concluídos não inventa percentual, tempo nem nota.');
expect($r['areas']['f']['backlog'] === 1 && $r['areas']['s']['backlog'] === 1, 'Backlog por área.');

$vazio = IndicadoresChamados::resumir([], $agora);
expect($vazio['areas'] === [] && $vazio['geral']['abertos'] === 0 && $vazio['geral']['pct_no_prazo'] === null, 'Sem chamados: zeros e nulos.');

$f = IndicadoresChamados::filtro('2026-10-01', '2026-10-31', null);
expect($f['params'] === ['2026-10-01', '2026-10-31'] && !str_contains($f['where'], 'area_id'), 'Admin/Supervisor: sem restrição de área.');
$f = IndicadoresChamados::filtro('2026-10-01', '2026-10-31', ['a1', 'a2'], 'a2');
expect($f['params'] === ['2026-10-01', '2026-10-31', 'a1', 'a2', 'a2'] && substr_count($f['where'], '?') === 5, 'Gestor: restrito às áreas geridas, tudo parametrizado.');
expect(IndicadoresChamados::filtro('2026-10-01', '2026-10-31', [])['where'] === '1 = 0', 'Gestor sem áreas não enxerga nada.');

$perm = (new ReflectionClass(App\Core\Auth::class))->getConstant('PERMISSOES');
expect($perm['chamados.indicadores'] === ['admin', 'supervisor', 'gestor_area'], 'Indicadores de chamados: Admin/TI, Supervisor e Gestor de área.');
expect($perm['api.tokens'] === ['admin'], 'Tokens de API: somente Admin/TI.');

echo "Indicadores de chamados: {$checks} verificações sem banco OK.\n";
