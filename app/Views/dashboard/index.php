<?php $totalPresentes = array_sum(array_map(fn ($l) => (int) $l['presentes'], $presencaPorLocal)); ?>
<section class="kpis">
  <div class="kpi"><span class="kpi-rotulo"><?= e(t('Entradas hoje')) ?></span><strong class="kpi-valor"><?= (int) ($totais['entradas'] ?? 0) ?></strong></div>
  <div class="kpi"><span class="kpi-rotulo"><?= e(t('Saídas hoje')) ?></span><strong class="kpi-valor"><?= (int) ($totais['saidas'] ?? 0) ?></strong></div>
  <div class="kpi kpi-destaque"><span class="kpi-rotulo"><?= e(t('Presentes agora')) ?></span><strong class="kpi-valor"><?= $totalPresentes ?></strong></div>
  <a class="kpi kpi-link" href="<?= e(url('/solicitacoes')) ?>"><span class="kpi-rotulo"><?= e(t('Solicitações pendentes')) ?></span><strong class="kpi-valor"><?= $pendentes ?></strong>
    <?php if ($aguardandoSsma): ?><small><?= $aguardandoSsma ?> <?= e(t('aguardando SSMA')) ?></small><?php endif; ?></a>
  <div class="kpi"><span class="kpi-rotulo"><?= e(t('Visitas aprovadas hoje')) ?></span><strong class="kpi-valor"><?= $visitasHoje ?></strong></div>
</section>

<div class="grade-2">
  <section class="cartao">
    <div class="cartao-cabecalho"><h2><?= e(t('Presença por local')) ?></h2><?php if (can('registros.ver')): ?><a href="<?= e(url('/presenca')) ?>" class="link"><?= e(t('Ver lista')) ?></a><?php endif; ?></div>
    <ul class="lista-simples">
      <?php foreach ($presencaPorLocal as $l): ?>
        <li><span><?= icon('local', 'icon icon-sm') ?> <?= e($l['nome']) ?> <small class="mudo">(<?= e(label_t(TIPOS_LOCAL, $l['tipo'], $l['tipo'])) ?>)</small></span><strong><?= (int) $l['presentes'] ?></strong></li>
      <?php endforeach; ?>
      <?php if (!$presencaPorLocal): ?><li class="mudo"><?= e(t('Nenhum local ativo cadastrado.')) ?></li><?php endif; ?>
    </ul>
  </section>

  <section class="cartao">
    <div class="cartao-cabecalho"><h2><?= e(t('Documentos vencidos ou a vencer (30 dias)')) ?></h2></div>
    <?php if (!$alertasDocs): ?>
      <p class="mudo vazio"><?= e(t('Nenhum alerta de documentação.')) ?></p>
    <?php else: ?>
      <ul class="lista-simples">
        <?php foreach ($alertasDocs as $d): $st = status_documento($d['validade']); ?>
          <li>
            <span><a href="<?= e(url('/pessoas/' . $d['pessoa_id'])) ?>"><?= e($d['nome']) ?></a> <small class="mudo"><?= e($d['empresa'] ?? '') ?></small></span>
            <span><?= badge(label_t(TIPOS_DOC, $d['tipo'], $d['tipo']), $st === 'vencido' ? 'perigo' : 'aviso') ?> <small><?= e(fmt_data($d['validade'])) ?></small></span>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>
</div>

<?php if (can('registros.ver')): ?>
<section class="cartao sem-padding">
  <div class="cartao-cabecalho pad"><h2><?= e(t('Últimos eventos')) ?></h2></div>
  <?php if (!$ultimos): ?>
    <p class="mudo vazio"><?= e(t('Nenhuma movimentação registrada ainda.')) ?></p>
  <?php else: ?>
  <div class="tabela-rolagem">
    <table class="tabela">
      <thead><tr><th><?= e(t('Data/Hora')) ?></th><th><?= e(t('Mov.')) ?></th><th><?= e(t('Pessoa')) ?></th><th class="ocultar-sm"><?= e(t('Placa')) ?></th><th class="ocultar-md"><?= e(t('Local')) ?></th><th class="ocultar-md"><?= e(t('Destino')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($ultimos as $r): ?>
        <tr>
          <td class="nowrap"><a href="<?= e(url('/registros/' . $r['id'])) ?>"><?= e(fmt_data_hora($r['data_hora'])) ?></a></td>
          <td><?= $r['sentido'] === 'entrada' ? badge(t('Entrada'), 'sucesso') : badge(t('Saída'), 'neutro') ?></td>
          <td><?= e($r['pessoa_nome'] ?? '—') ?></td>
          <td class="ocultar-sm mono"><?= e($r['placa'] ?? '—') ?></td>
          <td class="ocultar-md"><?= e($r['local_nome']) ?></td>
          <td class="ocultar-md mudo"><?= e($r['destino'] ?? '—') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
<?php endif; ?>
