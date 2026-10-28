<?php
$novo = $v === null;
$podeEditar = $novo ? can('veiculos.criar') : can('veiculos.editar');
$dis = $podeEditar ? '' : ' disabled';
$val = static fn (string $k, string $d = '') => old($old, $k, $v[$k] ?? $d);
?>
<form method="post" action="<?= e($novo ? url('/veiculos') : url('/veiculos/' . $v['id'])) ?>">
  <?= csrf_field() ?>
  <section class="cartao">
    <div class="grade-3">
      <label class="campo"><span><?= e(t('Placa')) ?> <span class="obrigatorio">*</span></span><input name="placa" required maxlength="10" class="maiusculo mono" value="<?= e($val('placa')) ?>"<?= $dis ?>></label>
      <label class="campo"><span><?= e(t('Tipo')) ?></span><select name="tipo"<?= $dis ?>><?php foreach (TIPOS_VEICULO as $k => $r): ?><option value="<?= e($k) ?>"<?= selected($val('tipo', 'carro'), $k) ?>><?= e(t($r)) ?></option><?php endforeach; ?></select></label>
      <label class="campo"><span><?= e(t('TAG')) ?> <small><?= e(t('(opcional, única)')) ?></small></span><input name="tag" maxlength="60" value="<?= e($val('tag')) ?>"<?= $dis ?>></label>
      <label class="campo"><span><?= e(t('Marca / Modelo')) ?></span><input name="marca_modelo" maxlength="120" value="<?= e($val('marca_modelo')) ?>"<?= $dis ?>></label>
      <label class="campo"><span><?= e(t('Cor')) ?></span><input name="cor" maxlength="40" value="<?= e($val('cor')) ?>"<?= $dis ?>></label>
      <label class="campo"><span><?= e(t('Empresa')) ?></span><input name="empresa" maxlength="150" value="<?= e($val('empresa')) ?>"<?= $dis ?>></label>
      <label class="checkbox"><input type="checkbox" name="comissionado_mina" value="1"<?= checked($val('comissionado_mina', '0')) ?><?= $dis ?>> <?= e(t('Comissionado para a mina')) ?></label>
      <label class="campo"><span><?= e(t('Validade do comissionamento')) ?></span><input type="date" name="comissionamento_validade" value="<?= e($val('comissionamento_validade')) ?>"<?= $dis ?>></label>
      <?php if (!$novo): ?><label class="checkbox"><input type="checkbox" name="ativo" value="1"<?= checked($v['ativo']) ?><?= $dis ?>> <?= e(t('Veículo ativo')) ?></label><?php endif; ?>
      <label class="campo campo-largo"><span><?= e(t('Observações')) ?></span><textarea name="observacoes" rows="2" maxlength="2000"<?= $dis ?>><?= e($val('observacoes')) ?></textarea></label>
    </div>
  </section>
  <div class="form-acoes">
    <a class="btn btn-secundario" href="<?= e(url('/veiculos')) ?>"><?= e(t('Voltar')) ?></a>
    <?php if ($podeEditar): ?><button class="btn btn-primario" type="submit"><?= $novo ? t('Cadastrar') : t('Salvar alterações') ?></button><?php endif; ?>
  </div>
</form>
<?php if (!$novo && can('veiculos.excluir')): ?>
<section class="cartao zona-perigo">
  <h2><?= e(t('Excluir veículo')) ?></h2>
  <p class="dica"><?= e(t('Só é possível excluir veículos sem histórico de acesso.')) ?></p>
  <form method="post" action="<?= e(url('/veiculos/' . $v['id'] . '/excluir')) ?>" data-confirmar="Excluir o veículo <?= e($v['placa']) ?>?">
    <?= csrf_field() ?><button class="btn btn-perigo" type="submit"><?= icon('lixo', 'icon icon-sm') ?> <?= e(t('Excluir veículo')) ?></button>
  </form>
</section>
<?php endif; ?>
