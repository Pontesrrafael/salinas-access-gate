<?php
/**
 * Miolo dos Indicadores — também devolvido sozinho na atualização automática (?parcial=1).
 * @var array $f @var string $periodoTxt @var ?string $localNome @var array $resumo @var array $anterior
 * @var ?int $permanencia @var array $presenca @var array $serie @var array $porHora @var array $empresas
 * @var array $solicitacoes @var array $ssma @var array $documentos @var array $alertas @var array $ultimos
 */
use App\Services\Grafico;
use App\Services\Indicadores;

$n = static fn (int $v): string => Grafico::num($v);
$variacao = static function (int $atual, int $ant): string {
    $p = Indicadores::variacao($atual, $ant);
    if ($p === null) {
        return '<span class="kpi-delta mudo">sem base no período anterior</span>';
    }
    $seta = $p > 0 ? '▲' : ($p < 0 ? '▼' : '■');
    return '<span class="kpi-delta"><span aria-hidden="true">' . $seta . '</span> ' . ($p > 0 ? '+' : '') . $p . '% vs período anterior (' . Grafico::num($ant) . ')</span>';
};
$duracao = static function (?int $min): string {
    if ($min === null) {
        return '—';
    }
    return $min >= 60 ? intdiv($min, 60) . ' h ' . str_pad((string) ($min % 60), 2, '0', STR_PAD_LEFT) . ' min' : $min . ' min';
};
$granTxt = ['hora' => 'hora', 'dia' => 'dia', 'semana' => 'semana'][$f['gran']];
$nomesSerie = Indicadores::SERIES;
$atual = $solicitacoes['atual'];
$totAlertas = count($alertas['documentos']) + count($alertas['permanencia']);
?>
<p class="ind-periodo">
  <?= icon('relogio', 'icon icon-sm') ?> <strong><?= e($periodoTxt) ?></strong>
  · <?= e($localNome ?? 'Todos os locais') ?>
  <?php if (!empty($f['aviso'])): ?> · <span class="badge badge-aviso"><?= e($f['aviso']) ?></span><?php endif; ?>
</p>

<section class="ind-kpis" aria-label="Indicadores principais">
  <div class="kpi kpi-heroi">
    <span class="kpi-rotulo">Presentes agora</span>
    <strong class="kpi-heroi-valor"><?= $n($presenca['total']) ?></strong>
    <ul class="kpi-quebra">
      <?php foreach (['colaborador', 'visitante', 'terceiro'] as $c): ?>
        <li><span class="viz-chave serie-<?= $c ?>" aria-hidden="true"></span><?= e(CATEGORIAS[$c]) ?> <strong><?= $n($presenca['porCategoria'][$c] ?? 0) ?></strong></li>
      <?php endforeach; ?>
    </ul>
  </div>
  <div class="kpi"><span class="kpi-rotulo">Entradas</span><strong class="kpi-valor"><?= $n($resumo['entradas']) ?></strong><?= $variacao($resumo['entradas'], $anterior['entradas']) ?></div>
  <div class="kpi"><span class="kpi-rotulo">Saídas</span><strong class="kpi-valor"><?= $n($resumo['saidas']) ?></strong><?= $variacao($resumo['saidas'], $anterior['saidas']) ?></div>
  <div class="kpi"><span class="kpi-rotulo">Pessoas distintas</span><strong class="kpi-valor"><?= $n($resumo['pessoas']) ?></strong><?= $variacao($resumo['pessoas'], $anterior['pessoas']) ?>
    <span class="kpi-delta"><?= icon('veiculo', 'icon icon-sm') ?> <?= $n($resumo['veiculos']) ?> veículo(s) distinto(s)</span></div>
  <div class="kpi"><span class="kpi-rotulo">Permanência média</span><strong class="kpi-valor"><?= e($duracao($permanencia)) ?></strong><span class="kpi-delta mudo">visitantes e terceiros</span></div>
  <a class="kpi kpi-link" href="<?= e(url('/solicitacoes')) ?>"><span class="kpi-rotulo">Solicitações pendentes</span><strong class="kpi-valor"><?= $n($atual['pendentes'] ?? 0) ?></strong>
    <span class="kpi-delta"><?= $n($atual['aguardando_ssma'] ?? 0) ?> aguardando SSMA · <?= $n($atual['aprovadas_hoje'] ?? 0) ?> aprovadas p/ hoje</span></a>
  <div class="kpi"><span class="kpi-rotulo">Taxa de aprovação</span><strong class="kpi-valor"><?= $solicitacoes['taxaAprovacao'] === null ? '—' : $solicitacoes['taxaAprovacao'] . '%' ?></strong>
    <span class="kpi-delta mudo"><?= $n($solicitacoes['total']) ?> solicitações recebidas no período</span></div>
  <div class="kpi"><span class="kpi-rotulo">Verificações SSMA</span><strong class="kpi-valor"><?= $n($ssma['apto'] + $ssma['nao_apto']) ?></strong>
    <span class="kpi-delta"><?= $n($ssma['apto']) ?> aptos · <?= $n($ssma['nao_apto']) ?> não aptos</span></div>
  <div class="kpi<?= $resumo['sem_solicitacao'] > 0 ? ' kpi-atencao' : '' ?>"><span class="kpi-rotulo">Externos sem solicitação</span><strong class="kpi-valor"><?= $n($resumo['sem_solicitacao']) ?></strong>
    <span class="kpi-delta mudo">de <?= $n($resumo['entradas_externos']) ?> entradas de visitantes/terceiros</span></div>
</section>

<section class="cartao ind-alertas<?= $totAlertas ? ' com-alerta' : '' ?>" aria-label="Alertas de segurança">
  <div class="cartao-cabecalho"><h2><?= icon($totAlertas ? 'alerta' : 'ok') ?> Alertas de segurança agora</h2>
    <?php if ($totAlertas): ?><span class="badge badge-perigo"><?= $n($totAlertas) ?> alerta(s)</span><?php else: ?><span class="badge badge-sucesso">Nenhum alerta</span><?php endif; ?></div>
  <?php if ($totAlertas): ?>
  <div class="grade-2">
    <div>
      <h3>Presentes com documento vencido</h3>
      <?php if (!$alertas['documentos']): ?><p class="mudo">Nenhum.</p><?php else: ?>
      <ul class="lista-simples">
        <?php foreach ($alertas['documentos'] as $a): ?>
          <li><span><a href="<?= e(url('/pessoas/' . $a['pessoa_id'])) ?>"><?= e($a['pessoa_nome']) ?></a> <small class="mudo"><?= e(CATEGORIAS[$a['pessoa_categoria']] ?? '') ?> · <?= e($a['local_nome']) ?></small></span>
            <span><?php foreach (explode(',', (string) $a['tipos']) as $t): ?><?= badge((TIPOS_DOC[$t] ?? $t) . ' vencido', 'perigo') ?> <?php endforeach; ?></span></li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
    </div>
    <div>
      <h3>Dentro há mais de <?= (int) $alertas['horas'] ?> h</h3>
      <?php if (!$alertas['permanencia']): ?><p class="mudo">Nenhum.</p><?php else: ?>
      <ul class="lista-simples">
        <?php foreach ($alertas['permanencia'] as $a): ?>
          <li><span><?= e($a['pessoa_nome']) ?> <small class="mudo"><?= e(CATEGORIAS[$a['pessoa_categoria']] ?? '') ?> · <?= e($a['local_nome']) ?></small></span>
            <span><?= badge('desde ' . fmt_data_hora($a['entrada_em']), 'aviso') ?></span></li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
</section>

<section class="cartao">
  <div class="cartao-cabecalho"><h2><?= icon('grafico') ?> Entradas por <?= e($granTxt) ?> e categoria</h2>
    <span class="mudo"><?= $n($resumo['entradas']) ?> entradas</span></div>
  <?= Grafico::colunas('Entradas por ' . $granTxt . ' e categoria', $serie['rotulos'], $serie['dicas'], $serie['series'], $nomesSerie) ?>
</section>

<div class="grade-2">
  <section class="cartao">
    <div class="cartao-cabecalho"><h2><?= icon('relogio') ?> Horário de pico</h2><span class="mudo">entradas por hora do dia</span></div>
    <?= Grafico::colunas('Entradas por hora do dia', array_map(fn ($h) => $h . 'h', range(0, 23)), array_map(fn ($h) => sprintf('%02d:00–%02d:59', $h, $h), range(0, 23)), ['unica' => $porHora], ['unica' => 'Entradas']) ?>
  </section>
  <section class="cartao">
    <div class="cartao-cabecalho"><h2><?= icon('local') ?> Presença agora por local</h2><?php if (can('registros.ver')): ?><a class="link" href="<?= e(url('/presenca')) ?>">Ver lista</a><?php endif; ?></div>
    <?= Grafico::barras('Presença agora por local', array_map(fn ($l) => [
        'rotulo' => $l['nome'], 'valor' => $l['n'], 'dica' => $l['nome'] . ': ' . $l['n'] . ' pessoa(s) dentro agora',
    ], $presenca['porLocal']), 'unica', 'Ninguém dentro agora.') ?>
  </section>
</div>

<div class="grade-2">
  <section class="cartao">
    <div class="cartao-cabecalho"><h2><?= icon('pessoas') ?> Empresas com mais entradas</h2><span class="mudo">visitantes e terceiros</span></div>
    <?= Grafico::barras('Empresas com mais entradas', array_map(fn ($r) => [
        'rotulo' => $r['empresa'], 'valor' => (int) $r['entradas'], 'extra' => (int) $r['pessoas'] . ' pessoa(s)',
        'dica' => $r['empresa'] . ': ' . $r['entradas'] . ' entradas de ' . $r['pessoas'] . ' pessoa(s)',
    ], $empresas), 'unica', 'Nenhuma entrada de visitantes ou terceiros no período.') ?>
  </section>
  <section class="cartao">
    <div class="cartao-cabecalho"><h2><?= icon('solicit') ?> Solicitações recebidas no período</h2><span class="mudo"><?= $n($solicitacoes['total']) ?> no total</span></div>
    <?= Grafico::barras('Solicitações por situação', array_map(fn ($st, $v) => [
        'rotuloHtml' => badge_status_solicitacao($st), 'rotulo' => STATUS_SOLICITACAO[$st], 'valor' => $v,
        'dica' => STATUS_SOLICITACAO[$st] . ': ' . $v,
    ], array_keys($solicitacoes['porStatus']), $solicitacoes['porStatus']), 'unica', 'Nenhuma solicitação recebida no período.') ?>
  </section>
</div>

<div class="grade-2">
  <section class="cartao sem-padding">
    <div class="cartao-cabecalho pad"><h2><?= icon('escudo') ?> Conformidade documental</h2><span class="mudo">pessoas ativas · documento mais recente</span></div>
    <?php if (!$documentos['porTipo']): ?>
      <p class="mudo vazio">Nenhum documento cadastrado.</p>
    <?php else: ?>
    <div class="tabela-rolagem"><table class="tabela">
      <thead><tr><th>Documento</th><th class="num">Vencidos</th><th class="num">A vencer (30 d)</th><th class="num">Válidos</th></tr></thead>
      <tbody>
        <?php foreach ($documentos['porTipo'] as $tipo => $d): ?>
          <tr><td><?= e(TIPOS_DOC[$tipo] ?? $tipo) ?></td>
            <td class="num"><?= $d['vencidos'] ? badge($n($d['vencidos']), 'perigo') : '0' ?></td>
            <td class="num"><?= $d['a_vencer'] ? badge($n($d['a_vencer']), 'aviso') : '0' ?></td>
            <td class="num"><?= $n($d['validos']) ?></td></tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot><tr><th>Total</th><th class="num"><?= $n($documentos['total']['vencidos']) ?></th><th class="num"><?= $n($documentos['total']['a_vencer']) ?></th><th class="num"><?= $n($documentos['total']['validos']) ?></th></tr></tfoot>
    </table></div>
    <?php endif; ?>
  </section>

  <section class="cartao sem-padding">
    <div class="cartao-cabecalho pad"><h2><?= icon('auditoria') ?> Últimos eventos do período</h2></div>
    <?php if (!$ultimos): ?>
      <p class="mudo vazio"><?= can('registros.ver') ? 'Nenhuma movimentação no período.' : 'Sem permissão para ver movimentações.' ?></p>
    <?php else: ?>
    <div class="tabela-rolagem"><table class="tabela">
      <thead><tr><th>Data/Hora</th><th>Mov.</th><th>Pessoa / placa</th><th class="ocultar-sm">Local</th></tr></thead>
      <tbody>
        <?php foreach ($ultimos as $r): ?>
          <tr>
            <td class="nowrap"><?= e(fmt_data_hora($r['data_hora'])) ?></td>
            <td><?= $r['sentido'] === 'entrada' ? badge('Entrada', 'sucesso') : badge('Saída', 'neutro') ?></td>
            <td><?= e($r['pessoa_nome'] ?? '—') ?><?php if ($r['placa']): ?> <small class="mono mudo"><?= e($r['placa']) ?></small><?php endif; ?></td>
            <td class="ocultar-sm"><?= e($r['local_nome']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </section>
</div>
