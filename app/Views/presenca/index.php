<?php slot('acoes', '<a class="btn btn-secundario" href="' . e(url('/presenca/imprimir', array_filter(['local_id' => $localId, 'q' => $q]))) . '" target="_blank" rel="noopener">' . icon('imprimir') . ' ' . e(t('Lista de evacuação')) . '</a>'); ?>
<div data-autorefresh="60"></div>
<section class="kpis">
  <div class="kpi kpi-destaque"><span class="kpi-rotulo"><?= e(t('Total no site')) ?></span><strong class="kpi-valor"><?= count($lista) ?></strong></div>
  <?php foreach ($porCategoria as $cat => $n): ?>
    <div class="kpi"><span class="kpi-rotulo"><?= e(t(['colaborador' => 'Colaboradores', 'visitante' => 'Visitantes', 'terceiro' => 'Terceiros'][$cat] ?? $cat)) ?></span><strong class="kpi-valor"><?= (int) $n ?></strong></div>
  <?php endforeach; ?>
</section>

<section class="cartao">
  <form method="get" action="<?= e(url('/presenca')) ?>" class="filtros" data-tour="filtros">
    <label class="campo"><span><?= e(t('Local')) ?></span>
      <select name="local_id" data-autosubmit><option value=""><?= e(t('Todos os locais')) ?></option>
        <?php foreach ($locais as $l): ?><option value="<?= e($l['id']) ?>"<?= selected($localId, $l['id']) ?>><?= e($l['nome']) ?></option><?php endforeach; ?>
      </select></label>
    <label class="campo"><span><?= e(t('Buscar')) ?></span><input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= e(t('Nome, empresa ou placa')) ?>"></label>
    <button class="btn btn-secundario" type="submit"><?= icon('busca', 'icon icon-sm') ?> <?= e(t('Filtrar')) ?></button>
  </form>
</section>

<section class="cartao sem-padding">
  <?php if (!$lista): ?>
    <p class="mudo vazio"><?= e(t('Ninguém registrado como presente.')) ?></p>
  <?php else: ?>
  <div class="tabela-rolagem">
    <table class="tabela">
      <thead><tr><th><?= e(t('Pessoa')) ?></th><th class="ocultar-sm"><?= e(t('Empresa')) ?></th><th class="ocultar-md"><?= e(t('Local')) ?></th><th><?= e(t('Entrada')) ?></th><th class="ocultar-sm"><?= e(t('Permanência')) ?></th><th class="ocultar-md"><?= e(t('Destino')) ?></th><th class="ocultar-md"><?= e(t('Placa')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($lista as $r):
          $longa = $r['pessoa_categoria'] !== 'colaborador' && (int) $r['minutos'] >= $alertaHoras * 60; ?>
        <tr class="<?= $longa ? 'destaque-alerta' : '' ?>">
          <td><a href="<?= e(url('/registros/' . $r['registro_id'])) ?>"><strong><?= e($r['pessoa_nome']) ?></strong></a><span class="sub"><?= e(label_t(CATEGORIAS, $r['pessoa_categoria'], '')) ?></span></td>
          <td class="ocultar-sm"><?= e($r['pessoa_empresa'] ?? '—') ?></td>
          <td class="ocultar-md"><?= e($r['local_nome']) ?></td>
          <td class="nowrap"><?= e(fmt_data_hora($r['entrada_em'])) ?></td>
          <td class="ocultar-sm nowrap"><?= e(sprintf('%dh%02d', intdiv((int) $r['minutos'], 60), (int) $r['minutos'] % 60)) ?><?= $longa ? ' ' . badge(t('acima de') . ' ' . $alertaHoras . 'h', 'aviso') : '' ?></td>
          <td class="ocultar-md mudo"><?= e($r['destino'] ?? '—') ?></td>
          <td class="ocultar-md mono"><?= e($r['veiculo_placa'] ?? '—') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
