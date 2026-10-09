<div class="erro-pagina">
  <div class="erro-codigo"><?= e($status) ?></div>
  <h1><?= e($titulo) ?></h1>
  <?php if ($mensagem !== ''): ?><p><?= e($mensagem) ?></p><?php endif; ?>
  <p><a class="btn btn-secundario" href="<?= e(url('/')) ?>">Voltar ao início</a></p>
</div>
