<?php slot('acoes', '<a class="btn btn-ouro" href="' . e(url('/relatorios/csv', array_filter($f))) . '">' . icon('download') . ' ' . e(t('Exportar CSV')) . '</a>'); ?>
<section class="cartao">
  <form method="get" action="<?= e(url('/relatorios')) ?>" class="filtros" data-tour="filtros">
    <label class="campo"><span><?= e(t('Início')) ?></span><input type="date" name="inicio" value="<?= e($f['inicio']) ?>"></label>
    <label class="campo"><span><?= e(t('Fim')) ?></span><input type="date" name="fim" value="<?= e($f['fim']) ?>"></label>
    <label class="campo"><span><?= e(t('Local')) ?></span><select name="local_id"><option value=""><?= e(t('Todos')) ?></option>
      <?php foreach ($locais as $l): ?><option value="<?= e($l['id']) ?>"<?= selected($f['local_id'], $l['id']) ?>><?= e($l['nome']) ?></option><?php endforeach; ?></select></label>
    <label class="campo"><span><?= e(t('Sentido')) ?></span><select name="sentido"><option value=""><?= e(t('Todos')) ?></option>
      <option value="entrada"<?= selected($f['sentido'], 'entrada') ?>><?= e(t('Entrada')) ?></option><option value="saida"<?= selected($f['sentido'], 'saida') ?>><?= e(t('Saída')) ?></option></select></label>
    <label class="campo"><span><?= e(t('Categoria')) ?></span><select name="categoria"><option value=""><?= e(t('Todas')) ?></option>
      <?php foreach (CATEGORIAS as $k => $r): ?><option value="<?= e($k) ?>"<?= selected($f['categoria'], $k) ?>><?= e(t($r)) ?></option><?php endforeach; ?></select></label>
    <label class="campo"><span><?= e(t('Buscar')) ?></span><input type="search" name="q" value="<?= e($f['q']) ?>" placeholder="<?= e(t('Pessoa, empresa, placa, destino')) ?>"></label>
    <button class="btn btn-secundario" type="submit"><?= icon('busca', 'icon icon-sm') ?> <?= e(t('Filtrar')) ?></button>
  </form>
</section>

<section class="kpis">
  <div class="kpi"><span class="kpi-rotulo"><?= e(t('Movimentações')) ?></span><strong class="kpi-valor"><?= (int) ($resumo['total'] ?? 0) ?></strong></div>
  <div class="kpi"><span class="kpi-rotulo"><?= e(t('Entradas')) ?></span><strong class="kpi-valor"><?= (int) ($resumo['entradas'] ?? 0) ?></strong></div>
  <div class="kpi"><span class="kpi-rotulo"><?= e(t('Saídas')) ?></span><strong class="kpi-valor"><?= (int) ($resumo['saidas'] ?? 0) ?></strong></div>
  <div class="kpi"><span class="kpi-rotulo"><?= e(t('Pessoas distintas')) ?></span><strong class="kpi-valor"><?= (int) ($resumo['pessoas'] ?? 0) ?></strong></div>
  <div class="kpi"><span class="kpi-rotulo"><?= e(t('Veículos distintos')) ?></span><strong class="kpi-valor"><?= (int) ($resumo['veiculos'] ?? 0) ?></strong></div>
</section>

<section class="cartao sem-padding">
  <?php if (!$rows): ?>
    <p class="mudo vazio"><?= e(t('Nenhum registro no período.')) ?></p>
  <?php else: ?>
  <div class="tabela-rolagem">
    <table class="tabela">
      <thead><tr><th><?= e(t('Data/Hora')) ?></th><th><?= e(t('Sentido')) ?></th><th><?= e(t('Pessoa')) ?></th><th class="ocultar-sm"><?= e(t('Veículo')) ?></th><th class="ocultar-md"><?= e(t('Local')) ?></th><th class="ocultar-lg"><?= e(t('Destino')) ?></th><th class="ocultar-md"><?= e(t('Porteiro')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td class="nowrap"><a href="<?= e(url('/registros/' . $r['id'])) ?>"><?= e(fmt_data_hora($r['data_hora'])) ?></a></td>
          <td><?= $r['sentido'] === 'entrada' ? badge(t('Entrada'), 'sucesso') : badge(t('Saída'), 'neutro') ?></td>
          <td><?= e($r['pessoa_nome'] ?? '—') ?><span class="sub"><?= e($r['pessoa_empresa'] ?? '') ?></span></td>
          <td class="ocultar-sm mono"><?= e($r['placa'] ?? '—') ?></td>
          <td class="ocultar-md"><?= e($r['local_nome']) ?></td>
          <td class="ocultar-lg mudo"><?= e($r['destino'] ?? '—') ?></td>
          <td class="ocultar-md mudo"><?= e($r['porteiro_nome'] ?? '—') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
