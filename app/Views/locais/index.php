<section class="cartao">
  <h2><?= e(t('Novo local')) ?></h2>
  <form method="post" action="<?= e(url('/locais')) ?>" class="filtros" data-tour="filtros">
    <?= csrf_field() ?>
    <label class="campo"><span><?= e(t('Nome')) ?> <span class="obrigatorio">*</span></span><input name="nome" required maxlength="150"></label>
    <label class="campo"><span><?= e(t('Tipo')) ?></span><select name="tipo"><?php foreach (TIPOS_LOCAL as $k => $r): ?><option value="<?= e($k) ?>"><?= e(t($r)) ?></option><?php endforeach; ?></select></label>
    <label class="campo"><span><?= e(t('Endereço')) ?></span><input name="endereco" maxlength="255"></label>
    <button class="btn btn-ouro" type="submit"><?= icon('mais', 'icon icon-sm') ?> <?= e(t('Adicionar')) ?></button>
  </form>
</section>

<section class="cartao sem-padding">
  <div class="tabela-rolagem">
    <table class="tabela">
      <thead><tr><th><?= e(t('Local')) ?></th><th><?= e(t('Tipo')) ?></th><th class="ocultar-md"><?= e(t('Endereço')) ?></th><th><?= e(t('Situação')) ?></th><th class="ocultar-sm"><?= e(t('Registros')) ?></th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $l): ?>
        <?php if ($editar === $l['id']): ?>
          <tr><td colspan="6">
            <form method="post" action="<?= e(url('/locais/' . $l['id'])) ?>" class="filtros">
              <?= csrf_field() ?>
              <label class="campo"><span><?= e(t('Nome')) ?></span><input name="nome" required maxlength="150" value="<?= e($l['nome']) ?>"></label>
              <label class="campo"><span><?= e(t('Tipo')) ?></span><select name="tipo"><?php foreach (TIPOS_LOCAL as $k => $r): ?><option value="<?= e($k) ?>"<?= selected($l['tipo'], $k) ?>><?= e(t($r)) ?></option><?php endforeach; ?></select></label>
              <label class="campo"><span><?= e(t('Endereço')) ?></span><input name="endereco" maxlength="255" value="<?= e($l['endereco'] ?? '') ?>"></label>
              <label class="checkbox"><input type="checkbox" name="ativo" value="1"<?= checked($l['ativo']) ?>> <?= e(t('Ativo')) ?></label>
              <a class="btn btn-secundario" href="<?= e(url('/locais')) ?>"><?= e(t('Cancelar')) ?></a>
              <button class="btn btn-primario" type="submit"><?= e(t('Salvar')) ?></button>
            </form>
          </td></tr>
        <?php else: ?>
          <tr>
            <td><strong><?= e($l['nome']) ?></strong></td>
            <td><?= e(label_t(TIPOS_LOCAL, $l['tipo'], $l['tipo'])) ?></td>
            <td class="ocultar-md mudo"><?= e($l['endereco'] ?? '—') ?></td>
            <td><?= (int) $l['ativo'] ? badge(t('Ativo'), 'sucesso') : badge(t('Inativo'), 'neutro') ?></td>
            <td class="ocultar-sm"><?= (int) $l['registros'] ?></td>
            <td class="acoes">
              <a class="btn btn-fantasma btn-sm" href="<?= e(url('/locais', ['editar' => $l['id']])) ?>"><?= icon('editar', 'icon icon-sm') ?> <?= e(t('Editar')) ?></a>
              <?php if (can('locais.excluir') && !(int) $l['registros']): ?>
                <form method="post" action="<?= e(url('/locais/' . $l['id'] . '/excluir')) ?>" data-confirmar="Excluir o local <?= e($l['nome']) ?>?">
                  <?= csrf_field() ?><button class="btn btn-fantasma btn-sm" type="submit"><?= icon('lixo', 'icon icon-sm') ?></button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endif; ?>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
