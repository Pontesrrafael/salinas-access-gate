<?php
/** @var bool $ativo  @var ?string $ativadoEm  @var ?array $novo  @var bool $obrigatorio */
?>
<section class="cartao">
<?php if ($ativo): ?>
  <h2>Ativada</h2>
  <p>O login pede o código do aplicativo autenticador desde <?= e(fmt_data_hora($ativadoEm)) ?>.</p>
  <?php if ($obrigatorio): ?>
    <p class="dica">Para o seu perfil ela é obrigatória. Trocou de celular? Peça ao Admin/TI: <code>php bin/console.php mfa-redefinir --email=...</code></p>
  <?php else: ?>
    <form method="post" action="<?= e(url('/conta/mfa/desativar')) ?>" class="filtros" data-confirmar="Desativar a verificação em duas etapas?">
      <?= csrf_field() ?>
      <label class="campo"><span>Sua senha</span><input type="password" name="senha" required autocomplete="current-password"></label>
      <button class="btn btn-perigo" type="submit">Desativar</button>
    </form>
  <?php endif; ?>
<?php else: ?>
  <h2>Cadastrar</h2>
  <ol>
    <li>Instale um aplicativo autenticador no celular (Microsoft Authenticator, Google Authenticator ou Authy).</li>
    <li>No aplicativo, escolha <strong>adicionar conta → digitar chave</strong> e informe a chave abaixo (tipo: baseada em tempo).
      No próprio celular, você também pode tocar em <a href="<?= e($novo['uri']) ?>">abrir no autenticador</a>.</li>
    <li>Digite o código de 6 dígitos que aparecer e confirme.</li>
  </ol>
  <p class="mono chave-mfa"><?= e($novo['segredo']) ?></p>
  <p class="dica">Não compartilhe esta chave. Ela não será mostrada de novo depois da ativação.</p>
  <form method="post" action="<?= e(url('/conta/mfa')) ?>" class="filtros" autocomplete="off">
    <?= csrf_field() ?>
    <label class="campo"><span>Código do aplicativo</span><input name="codigo" required inputmode="numeric" autocomplete="one-time-code" maxlength="7" placeholder="000000"></label>
    <button class="btn btn-primario" type="submit">Ativar</button>
  </form>
<?php endif; ?>
</section>
