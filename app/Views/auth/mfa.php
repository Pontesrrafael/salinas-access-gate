<h1 class="titulo-central"><?= e(t('Verificação em duas etapas')) ?></h1>
<p class="subtitulo-central"><?= e(t('Abra o aplicativo autenticador no celular e digite o código de 6 dígitos do Salinas Access Gate.')) ?></p>
<form method="post" action="<?= e(url('/login/mfa')) ?>" class="form-pilha" autocomplete="off">
  <?= csrf_field() ?>
  <label class="campo">
    <span><?= e(t('Código')) ?></span>
    <input name="codigo" required autofocus inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]{6,7}" maxlength="7" placeholder="000000">
  </label>
  <button type="submit" class="btn btn-primario btn-bloco"><?= e(t('Confirmar')) ?></button>
</form>
<p class="nota-central"><?= e(t('Trocou de celular ou perdeu o acesso? Peça ao Admin/TI para redefinir a verificação em duas etapas.')) ?></p>
<p class="nota-central"><a href="<?= e(url('/login')) ?>"><?= e(t('Voltar ao login')) ?></a></p>
