<h1 class="titulo-central">Controle de Acesso</h1>
<p class="subtitulo-central"><?= !empty($ldap) ? 'Use seu usuário e senha de rede (Windows) ou o e-mail da conta local.' : 'Entre com seu e-mail corporativo e senha.' ?></p>
<form method="post" action="<?= e(url('/login')) ?>" class="form-pilha" autocomplete="on">
  <?= csrf_field() ?>
  <label class="campo">
    <span><?= !empty($ldap) ? 'Usuário de rede ou e-mail' : 'E-mail' ?></span>
    <input type="<?= !empty($ldap) ? 'text' : 'email' ?>" name="email" value="<?= e(old($old, 'email')) ?>" required autofocus autocomplete="username" maxlength="190"<?= !empty($ldap) ? ' placeholder="ex.: rafael.pontes"' : '' ?>>
  </label>
  <label class="campo">
    <span>Senha</span>
    <input type="password" name="senha" required autocomplete="current-password" maxlength="200">
  </label>
  <button type="submit" class="btn btn-primario btn-bloco">Entrar</button>
</form>
<p class="nota-central">Esqueceu a senha? Solicite a redefinição ao Admin/TI.</p>
<p class="nota-central"><a href="<?= e(url('/solicitar-acesso')) ?>">Visitante ou terceiro? Solicite seu acesso aqui.</a></p>
