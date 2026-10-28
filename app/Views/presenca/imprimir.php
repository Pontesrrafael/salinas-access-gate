<?php slot('acoes', '<button type="button" class="btn btn-primario" data-imprimir>' . icon('imprimir') . ' ' . e(t('Imprimir')) . '</button>'); ?>
<section class="cartao">
  <div class="cabecalho-impressao"><img src="<?= e(asset('img/logo-horizontal.png')) ?>" alt="Salinas Gold Mineração"><h2><?= e(t('Lista de presença / evacuação')) ?></h2></div>
  <p><strong><?= e(t('Emitido em')) ?> <?= e(date('d/m/Y H:i')) ?></strong> <?= e(t('(Horário de Mato Grosso – UTC-4) · por')) ?> <?= e(usuario()['nome'] ?? '') ?> <?= e(t('·
     Local:')) ?> <?= e($localId ? (array_column($locais, 'nome', 'id')[$localId] ?? '—') : t('Todos')) ?> <?= e(t('· Total:')) ?> <strong><?= count($lista) ?></strong>
     (<?= implode(', ', array_map(fn ($k, $v) => e(t(['colaborador' => 'Colaboradores', 'visitante' => 'Visitantes', 'terceiro' => 'Terceiros'][$k] ?? $k) . ': ' . $v), array_keys($porCategoria), $porCategoria)) ?>)</p>
  <table class="tabela">
    <thead><tr><th>#</th><th><?= e(t('Pessoa')) ?></th><th><?= e(t('Categoria')) ?></th><th><?= e(t('Empresa')) ?></th><th><?= e(t('Local')) ?></th><th><?= e(t('Entrada')) ?></th><th><?= e(t('Destino')) ?></th><th><?= e(t('Conferido')) ?></th></tr></thead>
    <tbody>
    <?php foreach ($lista as $i => $r): ?>
      <tr>
        <td><?= $i + 1 ?></td>
        <td><?= e($r['pessoa_nome']) ?></td>
        <td><?= e(label_t(CATEGORIAS, $r['pessoa_categoria'], '')) ?></td>
        <td><?= e($r['pessoa_empresa'] ?? '—') ?></td>
        <td><?= e($r['local_nome']) ?></td>
        <td><?= e(fmt_data_hora($r['entrada_em'])) ?></td>
        <td><?= e($r['destino'] ?? '—') ?></td>
        <td>☐</td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>
