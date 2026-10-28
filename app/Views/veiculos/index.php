<?php if (can('veiculos.criar')) { slot('acoes', '<a class="btn btn-ouro" href="' . e(url('/veiculos/novo')) . '">' . icon('mais') . ' ' . e(t('Novo veículo')) . '</a>'); } ?>
<section class="cartao">
  <form method="get" action="<?= e(url('/veiculos')) ?>" class="filtros" data-tour="filtros">
    <label class="campo"><span><?= e(t('Buscar')) ?></span><input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= e(t('Placa, modelo, empresa ou TAG')) ?>"></label>
    <label class="campo"><span><?= e(t('Situação')) ?></span>
      <select name="situacao">
        <option value="ativos"<?= selected($situacao, 'ativos') ?>><?= e(t('Ativos')) ?></option>
        <option value="inativos"<?= selected($situacao, 'inativos') ?>><?= e(t('Inativos')) ?></option>
        <option value="todos"<?= selected($situacao, 'todos') ?>><?= e(t('Todos')) ?></option>
      </select></label>
    <button class="btn btn-secundario" type="submit"><?= icon('busca', 'icon icon-sm') ?> <?= e(t('Filtrar')) ?></button>
  </form>
</section>
<section class="cartao sem-padding">
  <?php if (!$rows): ?>
    <p class="mudo vazio"><?= e(t('Nenhum veículo encontrado.')) ?></p>
  <?php else: ?>
  <div class="tabela-rolagem">
    <table class="tabela">
      <thead><tr><th><?= e(t('Placa')) ?></th><th><?= e(t('Tipo')) ?></th><th class="ocultar-sm"><?= e(t('Modelo / Cor')) ?></th><th class="ocultar-md"><?= e(t('Empresa')) ?></th><th class="ocultar-md"><?= e(t('Mina')) ?></th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $v):
          $comVencido = $v['comissionamento_validade'] && $v['comissionamento_validade'] < hoje(); ?>
        <tr>
          <td><a class="mono" href="<?= e(url('/veiculos/' . $v['id'])) ?>"><strong><?= e($v['placa']) ?></strong></a>
            <?php if (!(int) $v['ativo']): ?><?= badge(t('Inativo'), 'perigo') ?><?php endif; ?>
            <?php if ($v['tag']): ?><span class="sub"><?= e(t('TAG')) ?> <?= e($v['tag']) ?></span><?php endif; ?></td>
          <td><?= e(label_t(TIPOS_VEICULO, $v['tipo'], $v['tipo'])) ?></td>
          <td class="ocultar-sm"><?= e(implode(' · ', array_filter([$v['marca_modelo'], $v['cor']])) ?: '—') ?></td>
          <td class="ocultar-md mudo"><?= e($v['empresa'] ?? '—') ?></td>
          <td class="ocultar-md"><?= (int) $v['comissionado_mina'] ? badge($comVencido ? t('Comissionamento vencido') : 'Comissionado' . ($v['comissionamento_validade'] ? ' ' . t('até') . ' ' . fmt_data($v['comissionamento_validade']) : ''), $comVencido ? 'perigo' : 'sucesso') : '<span class="mudo">—</span>' ?></td>
          <td class="acoes"><a class="btn btn-fantasma btn-sm" href="<?= e(url('/veiculos/' . $v['id'])) ?>"><?= icon('editar', 'icon icon-sm') ?> <?= e(t('Abrir')) ?></a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
