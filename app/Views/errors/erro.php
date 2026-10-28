<div class="erro-pagina">
  <div class="erro-codigo"><?= e($status) ?></div>
  <h1><?= e(t($titulo)) ?></h1>
  <?php if ($mensagem !== ''): ?><p><?= e(t($mensagem)) ?></p><?php endif; ?>
  <p><a class="btn btn-secundario" href="<?= e(url('/')) ?>"><?= e(t('Voltar ao início')) ?></a></p>
</div>
