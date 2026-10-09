<?php $totalPresentes = array_sum(array_map(fn ($l) => (int) $l['presentes'], $presencaPorLocal)); ?>
<section class="kpis">
  <div class="kpi"><span class="kpi-rotulo">Entradas hoje</span><strong class="kpi-valor"><?= (int) ($totais['entradas'] ?? 0) ?></strong></div>
  <div class="kpi"><span class="kpi-rotulo">Saídas hoje</span><strong class="kpi-valor"><?= (int) ($totais['saidas'] ?? 0) ?></strong></div>
  <div class="kpi kpi-destaque"><span class="kpi-rotulo">Presentes agora</span><strong class="kpi-valor"><?= $totalPresentes ?></strong></div>
  <a class="kpi kpi-link" href="<?= e(url('/solicitacoes')) ?>"><span class="kpi-rotulo">Solicitações pendentes</span><strong class="kpi-valor"><?= $pendentes ?></strong>
    <?php if ($aguardandoSsma): ?><small><?= $aguardandoSsma ?> aguardando SSMA</small><?php endif; ?></a>
  <div class="kpi"><span class="kpi-rotulo">Visitas aprovadas hoje</span><strong class="kpi-valor"><?= $visitasHoje ?></strong></div>
</section>

<div class="grade-2">
  <section class="cartao">
    <div class="cartao-cabecalho"><h2>Presença por local</h2><?php if (can('registros.ver')): ?><a href="<?= e(url('/presenca')) ?>" class="link">Ver lista</a><?php endif; ?></div>
    <ul class="lista-simples">
      <?php foreach ($presencaPorLocal as $l): ?>
        <li><span><?= icon('local', 'icon icon-sm') ?> <?= e($l['nome']) ?> <small class="mudo">(<?= e(TIPOS_LOCAL[$l['tipo']] ?? $l['tipo']) ?>)</small></span><strong><?= (int) $l['presentes'] ?></strong></li>
      <?php endforeach; ?>
      <?php if (!$presencaPorLocal): ?><li class="mudo">Nenhum local ativo cadastrado.</li><?php endif; ?>
    </ul>
  </section>

  <section class="cartao">
    <div class="cartao-cabecalho"><h2>Documentos vencidos ou a vencer (30 dias)</h2></div>
    <?php if (!$alertasDocs): ?>
      <p class="mudo vazio">Nenhum alerta de documentação.</p>
    <?php else: ?>
      <ul class="lista-simples">
        <?php foreach ($alertasDocs as $d): $st = status_documento($d['validade']); ?>
          <li>
            <span><a href="<?= e(url('/pessoas/' . $d['pessoa_id'])) ?>"><?= e($d['nome']) ?></a> <small class="mudo"><?= e($d['empresa'] ?? '') ?></small></span>
            <span><?= badge(TIPOS_DOC[$d['tipo']] ?? $d['tipo'], $st === 'vencido' ? 'perigo' : 'aviso') ?> <small><?= e(fmt_data($d['validade'])) ?></small></span>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>
</div>

<?php if (can('registros.ver')): ?>
<section class="cartao sem-padding">
  <div class="cartao-cabecalho pad"><h2>Últimos eventos</h2></div>
  <?php if (!$ultimos): ?>
    <p class="mudo vazio">Nenhuma movimentação registrada ainda.</p>
  <?php else: ?>
  <div class="tabela-rolagem">
    <table class="tabela">
      <thead><tr><th>Data/Hora</th><th>Mov.</th><th>Pessoa</th><th class="ocultar-sm">Placa</th><th class="ocultar-md">Local</th><th class="ocultar-md">Destino</th></tr></thead>
      <tbody>
      <?php foreach ($ultimos as $r): ?>
        <tr>
          <td class="nowrap"><a href="<?= e(url('/registros/' . $r['id'])) ?>"><?= e(fmt_data_hora($r['data_hora'])) ?></a></td>
          <td><?= $r['sentido'] === 'entrada' ? badge('Entrada', 'sucesso') : badge('Saída', 'neutro') ?></td>
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
