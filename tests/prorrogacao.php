<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Controllers\ServicosConfigController;
use App\Core\View;
use App\Services\Chamados;

$checks = 0;
function expect(bool $ok, string $label): void
{
    global $checks;
    if (!$ok) {
        throw new RuntimeException($label);
    }
    $checks++;
}

function rejeita(callable $fn, string $label): void
{
    global $checks;
    try {
        $fn();
    } catch (InvalidArgumentException) {
        $checks++;
        return;
    }
    throw new RuntimeException($label);
}

// 1. Conversão de unidades, limites (1 min até 365 dias) e atalhos de SLA maiores
expect(Chamados::converterParaMinutos('30', 'min') === 30, '30 min = 30 minutos.');
expect(Chamados::converterParaMinutos('4', 'h') === 240, '4 h = 240 minutos.');
expect(Chamados::converterParaMinutos('5', 'd') === 7200, '5 d = 7200 minutos.');
expect(Chamados::converterParaMinutos('7', 'd') === 10080, '7 d = 10080 minutos.');
expect(Chamados::converterParaMinutos('15', 'd') === 21600, '15 d = 21600 minutos.');
expect(Chamados::converterParaMinutos('30', 'd') === 43200, '30 d = 43200 minutos.');
expect(Chamados::converterParaMinutos('365', 'd') === 525600, '365 d = 525600 minutos (limite superior).');
expect(ServicosConfigController::converterParaMinutos(7, 'd') === 10080, 'ServicosConfigController delega conversão em dias.');

foreach ([
    ['0', 'min'],
    ['-1', 'd'],
    ['1.5', 'h'],
    ['abc', 'min'],
    ['366', 'd'],
    ['525601', 'min'],
    ['10', 'semanas'],
] as [$valorInvalido, $unidadeInvalida]) {
    rejeita(fn () => Chamados::converterParaMinutos($valorInvalido, $unidadeInvalida), "Deve rejeitar {$valorInvalido} {$unidadeInvalida}.");
}

foreach ([7200 => '5 d', 10080 => '7 d', 21600 => '15 d', 43200 => '30 d'] as $min => $rotulo) {
    expect((ServicosConfigController::ATALHOS_SLA[$min] ?? null) === $rotulo, "Atalho de SLA {$rotulo} deve existir.");
    expect(ServicosConfigController::formatarSla($min) === $rotulo, "formatarSla({$min}) deve exibir {$rotulo}.");
}
expect(ServicosConfigController::formatarSla(120) === '2 h', 'formatarSla(120) exibe 2 h.');
expect(ServicosConfigController::formatarSla(45) === '45 min', 'formatarSla(45) exibe 45 min.');
expect(ServicosConfigController::decomporSla(10080) === ['valor' => '7', 'unidade' => 'd'], 'decomporSla(10080) decompõe em 7 d.');
expect(ServicosConfigController::decomporSla(120) === ['valor' => '2', 'unidade' => 'h'], 'decomporSla(120) decompõe em 2 h.');
expect(ServicosConfigController::decomporSla(45) === ['valor' => '45', 'unidade' => 'min'], 'decomporSla(45) decompõe em 45 min.');

$slaPersonalizado = ServicosConfigController::dadosSla([
    'sla_resposta_min'     => '4',
    'sla_resposta_unidade' => 'h',
    'sla_solucao_min'      => '15',
    'sla_solucao_unidade'  => 'd',
]);
expect($slaPersonalizado['sla_resposta_min'] === 240 && $slaPersonalizado['sla_solucao_min'] === 21600, 'dadosSla converte horas e dias personalizados para minutos.');

$slaAtalhoGrande = ServicosConfigController::dadosSla([
    'sla_resposta_atalho' => '1440',
    'sla_solucao_atalho'  => '43200',
]);
expect($slaAtalhoGrande['sla_resposta_min'] === 1440 && $slaAtalhoGrande['sla_solucao_min'] === 43200, 'dadosSla aceita atalhos de 1 d e 30 d.');

rejeita(fn () => ServicosConfigController::dadosSla([
    'sla_resposta_min'     => '7',
    'sla_resposta_unidade' => 'd',
    'sla_solucao_min'      => '2',
    'sla_solucao_unidade'  => 'd',
]), 'dadosSla exige solução >= resposta mesmo quando informados em dias.');

// 2. Prorrogação de prazo em 24x7, horário comercial, preservação de pausa e bloqueio em fechado
$agora = new DateTimeImmutable('2026-10-28 10:00:00');
$chamado24x7 = [
    'id'                   => 'e4ef6cc8-aafe-4ad4-80be-a2420a856810',
    'numero'               => 42,
    'status'               => 'atribuido',
    'created_at'           => '2026-10-28 08:00:00',
    'prazo_resposta'       => '2026-10-28 09:00:00',
    'prazo_solucao'        => '2026-10-28 18:00:00',
    'prazo_prorrogado_min' => 0,
    'segundos_pendente'    => 0,
    'pendente_desde'       => null,
    'calendario_sla'       => null,
];

$prorrogado1 = Chamados::prorrogar($chamado24x7, 1440, $agora); // +1 dia (24h em 24x7)
expect($prorrogado1['prazo_solucao'] === '2026-10-29 18:00:00', 'Prorrogação de +1 dia em 24x7 avança 24 horas corridas.');
expect($prorrogado1['prazo_prorrogado_min'] === 1440, 'Acumula 1440 minutos em prazo_prorrogado_min.');

$chamadoAcumulado = array_replace($chamado24x7, $prorrogado1);
$prorrogado2 = Chamados::prorrogar($chamadoAcumulado, 4320, $agora); // +3 dias
expect($prorrogado2['prazo_solucao'] === '2026-11-01 18:00:00', 'Segunda prorrogação soma sobre o prazo já prorrogado.');
expect($prorrogado2['prazo_prorrogado_min'] === 5760, 'Soma cumulativa de prazo_prorrogado_min (1440 + 4320 = 5760).');

// Horário comercial (seg-sex, 07:00 às 17:00 = 10h úteis/dia, com feriado em 2026-11-02)
$calendario = Chamados::calendario([
    'dias'     => [1, 2, 3, 4, 5],
    'inicio'   => '07:00',
    'fim'      => '17:00',
    'feriados' => ['2026-11-02'],
]);
$chamadoComercial = array_replace($chamado24x7, [
    'prazo_solucao'  => '2026-10-30 15:00:00', // Sexta-feira às 15:00 (restam 2h úteis na sexta)
    'calendario_sla' => json_encode($calendario, JSON_THROW_ON_ERROR),
]);
// Prorrogar em +4 horas (240 min): consome 2h na sexta (15:00-17:00), pula sábado (31/10), domingo (01/11) e feriado (02/11),
// e termina na terça-feira (03/11) às 09:00.
$prorrogadoComercial = Chamados::prorrogar($chamadoComercial, 240, $agora);
expect($prorrogadoComercial['prazo_solucao'] === '2026-11-03 09:00:00', 'Prorrogação em horário comercial respeita expediente, fim de semana e feriado.');

// Preservação da pausa de pendente
$chamadoPendente = array_replace($chamado24x7, [
    'status'            => 'pendente',
    'prazo_solucao'     => '2026-10-28 18:00:00',
    'segundos_pendente' => 3600, // 1h já acumulada anteriormente
    'pendente_desde'    => '2026-10-28 09:00:00', // +1h até $agora (10:00)
]);
$mudancasPendente = Chamados::prorrogar($chamadoPendente, 120, $agora); // +2h de prorrogação -> base vai para 20:00
$aposProrrogacao = array_replace($chamadoPendente, $mudancasPendente);
expect($aposProrrogacao['prazo_solucao'] === '2026-10-28 20:00:00', 'Base persistida avança em +2h.');
expect($aposProrrogacao['segundos_pendente'] === 3600 && $aposProrrogacao['pendente_desde'] === '2026-10-28 09:00:00', 'Prorrogação preserva segundos_pendente e pendente_desde.');
// Prazo efetivo em $agora (10:00) = base (20:00) + 1h acumulada + 1h de pausa ativa = 22:00
expect(Chamados::prazoSolucao($aposProrrogacao, $agora)?->format('Y-m-d H:i:s') === '2026-10-28 22:00:00', 'prazoSolucao soma a pausa de pendente sobre o novo prazo prorrogado.');

// Chamado que estava vencendo volta para no_prazo após prorrogação
$vencendo = array_replace($chamado24x7, [
    'created_at'    => '2026-10-28 08:00:00',
    'prazo_solucao' => '2026-10-28 10:10:00',
]);
expect(Chamados::situacaoSolucao($vencendo, $agora) === 'vencendo', 'Antes da prorrogação está vencendo.');
$recalculado = array_replace($vencendo, Chamados::prorrogar($vencendo, 1440, $agora));
expect(Chamados::situacaoSolucao($recalculado, $agora) === 'no_prazo', 'Após prorrogar +1 dia volta para no_prazo.');

// Chamado fechado não pode ser prorrogado
rejeita(fn () => Chamados::prorrogar(array_replace($chamado24x7, ['status' => 'fechado']), 1440, $agora), 'Chamado fechado deve rejeitar prorrogação.');
rejeita(fn () => Chamados::prorrogar($chamado24x7, 0, $agora), 'Prorrogação zero deve ser rejeitada.');
rejeita(fn () => Chamados::prorrogar($chamado24x7, 525601, $agora), 'Prorrogação acima de 365 dias deve ser rejeitada.');

// 3. Renderização da tela de detalhe (bloco de prorrogação visível apenas para equipe da área e chamado não fechado, CSP)
$chamadoDetalhe = array_replace($chamado24x7, [
    'area_id'              => 'e4ef6cc8-aafe-4ad4-80be-a2420a856801',
    'categoria_id'         => 'e4ef6cc8-aafe-4ad4-80be-a2420a856820',
    'area_nome'            => 'TI',
    'area_cor'             => '#024B37',
    'categoria_nome'       => 'Projetos',
    'solicitante_id'       => 'u-solic',
    'solicitante_nome'     => 'Maria',
    'atendente_id'         => 'u-atend',
    'atendente_nome'       => 'João',
    'beneficiario_nome'    => null,
    'recorrente'           => 0,
    'urgencia'             => 3,
    'impacto'              => 2,
    'prioridade'           => 4,
    'descricao'            => 'Implantação de link',
    'respondido_em'        => '2026-10-28 08:30:00',
    'solucionado_em'       => null,
    'fechado_em'           => null,
    'prazo_prorrogado_min' => 10080, // 7 d
]);
$dadosDetalhe = [
    'c'                  => $chamadoDetalhe,
    'atende'             => true,
    'u'                  => ['id' => 'u-atend', 'papel' => 'operador', 'nome' => 'João'],
    'eventos'            => [
        ['id' => 'ev-p', 'tipo' => 'prorrogacao', 'texto' => 'Prazo de solução prorrogado em +7 d (novo prazo: 04/11/2026 18:00). Motivo: Aguardando fornecedor', 'usuario_nome' => 'João', 'created_at' => '2026-10-28 09:00:00'],
    ],
    'anexos'             => [],
    'respostas'          => [],
    'membros'            => [['id' => 'u-atend', 'nome' => 'João']],
    'satisfacao'         => null,
    'destinos'           => [],
    'destinoCategoriaId' => '',
    'destinoSelecionado' => null,
    'camposDestino'      => [],
    'old'                => [],
];

$htmlAtendente = View::partial('chamados/detalhe', $dadosDetalhe);
expect(str_contains($htmlAtendente, 'Prorrogar prazo de solução'), 'Equipe da área vê bloco de prorrogação em chamado aberto.');
expect(str_contains($htmlAtendente, 'name="prorrogacao_atalho"') && str_contains($htmlAtendente, 'name="prorrogacao_valor"') && str_contains($htmlAtendente, 'name="prorrogacao_unidade"') && str_contains($htmlAtendente, 'name="prorrogacao_motivo"'), 'Bloco de prorrogação contém atalho, valor, unidade e motivo.');
expect(str_contains($htmlAtendente, 'Prorrogado em +7 d'), 'Prazos exibem o tempo prorrogado acumulado.');
expect(str_contains($htmlAtendente, 'Prorrogação de prazo'), 'Linha do tempo exibe selo do evento de prorrogação.');
expect(!preg_match('/<(script|style)\b|\s(?:style|onload|onclick|onchange)=/i', $htmlAtendente), 'Tela de detalhe com prorrogação respeita a CSP.');

$dadosDetalhe['atende'] = false;
$htmlSolicitante = View::partial('chamados/detalhe', $dadosDetalhe);
expect(!str_contains($htmlSolicitante, 'name="prorrogacao_motivo"'), 'Solicitante comum não vê o formulário de prorrogar prazo.');

$dadosDetalhe['atende'] = true;
$dadosDetalhe['c']['status'] = 'fechado';
$htmlFechado = View::partial('chamados/detalhe', $dadosDetalhe);
expect(!str_contains($htmlFechado, 'name="prorrogacao_motivo"'), 'Chamado fechado não exibe formulário de prorrogação.');

echo "Prorrogação e SLAs maiores: {$checks} verificações sem banco OK.\n";
