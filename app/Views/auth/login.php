<h1 class="titulo-central"><?= e(t('Controle de Acesso')) ?></h1>
<p class="subtitulo-central"><?= e(!empty($ldap) ? t('Use seu usuário e senha de rede (Windows) ou o e-mail da conta local.') : t('Entre com seu e-mail corporativo e senha.')) ?></p>
<form method="post" action="<?= e(url('/login')) ?>" class="form-pilha" autocomplete="on">
  <?= csrf_field() ?>
  <label class="campo">
    <span><?= e(!empty($ldap) ? t('Usuário de rede ou e-mail') : t('E-mail')) ?></span>
    <input type="<?= !empty($ldap) ? 'text' : 'email' ?>" name="email" value="<?= e(old($old, 'email')) ?>" required autofocus autocomplete="username" maxlength="190"<?php if (!empty($ldap)): ?> placeholder="<?= e(t('Ex.: usuário de rede')) ?>"<?php endif; ?>>
  </label>
  <label class="campo">
    <span><?= e(t('Senha')) ?></span>
    <input type="password" name="senha" required autocomplete="current-password" maxlength="200">
  </label>
  <button type="submit" class="btn btn-primario btn-bloco"><?= e(t('Entrar')) ?></button>
</form>
<p class="nota-central"><?= e(t('Esqueceu a senha? Solicite a redefinição ao Admin/TI.')) ?></p>
<p class="nota-central"><a href="<?= e(url('/solicitar-acesso')) ?>"><?= e(t('Visitante ou terceiro? Solicite seu acesso aqui.')) ?></a></p>
