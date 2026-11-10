<?php
declare(strict_types=1);
require __DIR__ . '/../app/Services/Chamados.php';

use App\Services\Chamados;

function expectSla(bool $ok, string $mensagem): void { if (!$ok) { throw new RuntimeException($mensagem); } }

$area = ['sla_resposta_min' => 60, 'sla_solucao_min' => 240, 'usar_horario_comercial' => 0, 'sla_prioridades' => [1 => ['sla_resposta_min' => 15, 'sla_solucao_min' => 60]]];
$categoria = ['sla_resposta_min' => 30, 'sla_solucao_min' => 120, 'sla_prioridades' => [2 => ['sla_resposta_min' => 10, 'sla_solucao_min' => 45]]];
expectSla(Chamados::slaEfetivo($categoria, $area, 2)['resposta_min'] === 10, 'Prioridade da categoria prevalece.');
expectSla(Chamados::slaEfetivo($categoria, $area, 1)['solucao_min'] === 60, 'Prioridade da área é usada sem linha da categoria.');
expectSla(Chamados::slaEfetivo($categoria, $area, 6)['resposta_min'] === 30, 'Prioridade sem linha mantém SLA padrão.');
echo "SLA por prioridade: herança categoria, área e padrão OK.\n";
