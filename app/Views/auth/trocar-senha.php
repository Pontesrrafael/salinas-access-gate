<h1 class="titulo-central"><?= e(t('Trocar senha')) ?></h1>
<?php if ($obrigatoria): ?>
  <div class="alerta alerta-aviso"><?= e(t('Por segurança, defina uma nova senha antes de continuar.')) ?></div>
<?php endif; ?>
<form method="post" action="<?= e(url('/trocar-senha')) ?>" class="form-pilha">
  <?= csrf_field() ?>
  <label class="campo">
    <span><?= e(t('Senha atual')) ?></span>
    <input type="password" name="senha_atual" required autocomplete="current-password">
  </label>
  <label class="campo">
    <span><?= e(t('Nova senha')) ?></span>
    <input type="password" name="nova_senha" required autocomplete="new-password" minlength="8" maxlength="72">
    <small><?= e(t('Mínimo de 8 caracteres, com letras e números.')) ?></small>
  </label>
  <label class="campo">
    <span><?= e(t('Confirmar nova senha')) ?></span>
    <input type="password" name="senha_confirmacao" required autocomplete="new-password" maxlength="72">
  </label>
  <button type="submit" class="btn btn-primario btn-bloco"><?= e(t('Salvar nova senha')) ?></button>
</form>
<?php if (!$obrigatoria): ?><p class="nota-central"><a href="<?= e(url('/painel')) ?>"><?= e(t('Cancelar')) ?></a></p><?php endif; ?>
<form method="post" action="<?= e(url('/logout')) ?>" class="nota-central">
  <?= csrf_field() ?>
  <button type="submit" class="link-botao"><?= e(t('Sair')) ?></button>
</form>
