<?php
/** @var bool $ativo  @var ?string $ativadoEm  @var ?array $novo  @var bool $obrigatorio */
?>
<section class="cartao">
<?php if ($ativo): ?>
  <h2><?= e(t('Ativada')) ?></h2>
  <p><?= e(t('O login pede o código do aplicativo autenticador desde {data}.', ['data' => fmt_data_hora($ativadoEm)])) ?></p>
  <?php if ($obrigatorio): ?>
    <p class="dica"><?= e(t('Para o seu perfil ela é obrigatória. Trocou de celular? Peça ao Admin/TI para redefinir:')) ?> <code>php bin/console.php mfa-redefinir --email=...</code></p>
  <?php else: ?>
    <form method="post" action="<?= e(url('/conta/mfa/desativar')) ?>" class="filtros" data-confirmar="<?= e(t('Desativar a verificação em duas etapas?')) ?>">
      <?= csrf_field() ?>
      <label class="campo"><span><?= e(t('Sua senha')) ?></span><input type="password" name="senha" required autocomplete="current-password"></label>
      <button class="btn btn-perigo" type="submit"><?= e(t('Desativar')) ?></button>
    </form>
  <?php endif; ?>
<?php else: ?>
  <h2><?= e(t('Cadastrar')) ?></h2>
  <ol>
    <li><?= e(t('Instale um aplicativo autenticador no celular (Microsoft Authenticator, Google Authenticator ou Authy).')) ?></li>
    <li><?= e(t('No aplicativo, escolha adicionar conta → ler código QR e aponte a câmera para o QR abaixo.')) ?></li>
    <li><?= e(t('Digite o código de 6 dígitos que aparecer e confirme.')) ?></li>
  </ol>
  <div class="mfa-cadastro">
    <figure class="qr-mfa" id="qr-mfa" data-otpauth="<?= e($novo['uri']) ?>" aria-label="<?= e(t('Código QR para cadastrar no autenticador')) ?>">
      <noscript><p class="dica"><?= e(t('Ative o JavaScript para ver o QR ou use a chave ao lado.')) ?></p></noscript>
    </figure>
    <div>
      <p class="dica"><?= e(t('Não consegue ler o QR? Escolha digitar chave (tipo: baseada em tempo) e informe:')) ?></p>
      <p class="mono chave-mfa"><?= e($novo['segredo']) ?></p>
      <p class="dica"><a href="<?= e($novo['uri']) ?>"><?= e(t('No celular: abrir no autenticador')) ?></a></p>
    </div>
  </div>
  <script src="<?= e(asset('vendor/qrcode/qrcode.js')) ?>"></script>
  <p class="dica"><?= e(t('Não compartilhe esta chave. Ela não será mostrada de novo depois da ativação.')) ?></p>
  <form method="post" action="<?= e(url('/conta/mfa')) ?>" class="filtros" autocomplete="off">
    <?= csrf_field() ?>
    <label class="campo"><span><?= e(t('Código do aplicativo')) ?></span><input name="codigo" required inputmode="numeric" autocomplete="one-time-code" maxlength="7" placeholder="000000"></label>
    <button class="btn btn-primario" type="submit"><?= e(t('Ativar')) ?></button>
  </form>
<?php endif; ?>
</section>
