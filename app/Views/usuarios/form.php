<?php
$novo = $u === null;
$val = static fn (string $k, string $d = '') => old($old, $k, $u[$k] ?? $d);
$areasMarcadas = $old['areas'] ?? array_column(array_filter($areas, fn ($a) => !$novo && $a['responsavel_user_id'] === $u['id']), 'id');
$perfisMarcados = $old ? ($old['perfis'] ?? []) : ($u['perfis'] ?? ['colaborador']);
if (!is_array($perfisMarcados)) { $perfisMarcados = []; }
?>
<?php $origem = old($old, 'auth_origem', $u['auth_origem'] ?? 'local'); ?>
<form method="post" action="<?= e($novo ? url('/usuarios') : url('/usuarios/' . $u['id'])) ?>" id="form-usuario" data-tour="formulario">
  <?= csrf_field() ?>
  <?php if ($ldap || $origem === 'ldap'): ?>
  <section class="cartao">
    <div class="cartao-cabecalho"><h2><?= e(t('Autenticação')) ?></h2>
      <?php if (!$novo && ($u['auth_origem'] ?? '') === 'ldap'): ?>
        <span class="dica"><?= e(t('Última sincronização:')) ?> <?= e(fmt_data_hora($u['ldap_sincronizado_em'])) ?></span>
      <?php endif; ?>
    </div>
    <div class="alternador alternador-claro" role="radiogroup">
      <label class="alt-opcao"><input type="radio" name="auth_origem" value="local"<?= $origem === 'local' ? ' checked' : '' ?>> <?= e(t('Conta local (senha do sistema)')) ?></label>
      <label class="alt-opcao"><input type="radio" name="auth_origem" value="ldap"<?= $origem === 'ldap' ? ' checked' : '' ?><?= $ldap ? '' : ' disabled' ?>> <?= e(t('Active Directory (usuário de rede)')) ?></label>
    </div>
    <div id="bloco-ad" class="bloco-ad"<?= $origem === 'ldap' ? '' : ' hidden' ?>>
      <input type="hidden" name="ldap_guid" id="ldap_guid" value="<?= e(old($old, 'ldap_guid', $u['ldap_guid'] ?? '')) ?>">
      <div class="busca">
        <?= icon('busca', 'icon busca-icone') ?>
        <input type="search" id="busca-ad" class="alto" placeholder="<?= e(t('Buscar no AD por nome, usuário de rede, e-mail ou matrícula')) ?>" autocomplete="off" data-endpoint="<?= e(url('/api/ldap/busca')) ?>">
        <ul class="resultados" id="res-ad" hidden></ul>
      </div>
      <div id="ad-selecionado" class="selecionado"<?= ($u['ldap_usuario'] ?? '') !== '' ? '' : ' hidden' ?>>
        <?php if (!empty($u['ldap_usuario'])): ?>
          <strong><?= e($u['ldap_usuario']) ?></strong><span class="sub mono"><?= e($u['ldap_dn'] ?? '') ?></span>
        <?php endif; ?>
      </div>
      <p class="dica"><?= e(t('A senha é a mesma da rede (validada no AD a cada login). Conta desativada no AD perde o acesso aqui na próxima sincronização.')) ?></p>
      <?php if (!$novo && ($u['auth_origem'] ?? '') === 'ldap' && $ldap): ?>
        <button type="submit" class="btn btn-secundario btn-sm" form="form-sinc-ad"><?= e(t('Sincronizar agora com o AD')) ?></button>
      <?php endif; ?>
    </div>
    <?php if (!$ldap): ?><p class="dica aviso-texto"><?= e(t('Integração LDAP desabilitada no .env — este usuário não conseguirá entrar até a integração ser reativada ou ele voltar a ser conta local.')) ?></p><?php endif; ?>
  </section>
  <?php endif; ?>
  <section class="cartao">
    <h2><?= e(t('Dados de acesso')) ?></h2>
    <p class="dica"><?= e(t('Selecione os perfis permitidos e o perfil padrão para o login. O usuário pode trocar o perfil ativo no topo.')) ?></p>
    <div class="grade-3">
      <label class="campo"><span><?= e(t('Nome')) ?> <span class="obrigatorio">*</span></span><input name="nome" required maxlength="150" value="<?= e($val('nome')) ?>"></label>
      <label class="campo"><span><?= e(t('E-mail corporativo')) ?> <span class="obrigatorio">*</span></span><input type="email" name="email" required maxlength="190" value="<?= e($val('email')) ?>" autocomplete="off"></label>
      <label class="campo"><span><?= e(t('Matrícula')) ?></span><input name="matricula" maxlength="50" value="<?= e($val('matricula')) ?>"></label>
      <label class="campo"><span><?= e(t('Perfil padrão')) ?> <span class="obrigatorio">*</span></span>
        <select name="papel" required><?php foreach (App\Core\Auth::PAPEIS as $k => $r): ?><option value="<?= e($k) ?>"<?= selected($val('papel', 'colaborador'), $k) ?>><?= e(t($r)) ?></option><?php endforeach; ?></select></label>
      <label class="checkbox"><input type="checkbox" name="seguranca_patrimonial" value="1"<?= checked($val('seguranca_patrimonial', '0')) ?>> <span><?= e(t('Segurança Patrimonial')) ?><br><small><?= e(t('Pode autorizar solicitações de qualquer área.')) ?></small></span></label>
      <?php if (!$novo): ?><label class="checkbox"><input type="checkbox" name="ativo" value="1"<?= checked($u['ativo']) ?>> <span><?= e(t('Conta ativa')) ?><br><small><?= e(t('Desativar encerra o acesso imediatamente.')) ?></small></span></label><?php endif; ?>
    </div>
    <fieldset>
      <legend><?= e(t('Perfis permitidos')) ?></legend>
      <div class="grade-3">
      <?php foreach (App\Core\Auth::PAPEIS as $perfil => $rotulo): ?>
        <label class="checkbox"><input type="checkbox" name="perfis[]" value="<?= e($perfil) ?>"<?= in_array($perfil, $perfisMarcados, true) ? ' checked' : '' ?>> <span><?= e(t($rotulo)) ?></span></label>
      <?php endforeach; ?>
      </div>
      <p class="dica"><?= e(t('O gestor de área precisa do vínculo como gestor nas áreas de serviço.')) ?></p>
    </fieldset>
    <div id="bloco-senha"<?= $origem === 'ldap' ? ' hidden' : '' ?>>
    <?php if ($novo): ?>
      <label class="campo"><span><?= e(t('Senha inicial')) ?></span><input type="password" name="senha" maxlength="72" autocomplete="new-password">
        <small><?= e(t('Deixe em branco para gerar uma senha temporária. A troca é obrigatória no primeiro acesso.')) ?></small></label>
    <?php else: ?>
      <label class="checkbox"><input type="checkbox" name="redefinir_senha" value="1"> <span><?= e(t('Gerar nova senha temporária')) ?><br><small><?= e(t('A senha será exibida uma única vez após salvar.')) ?></small></span></label>
    <?php endif; ?>
    </div>
  </section>

  <section class="cartao">
    <h2><?= e(t('Responsável pelas áreas')) ?></h2>
    <p class="dica"><?= e(t('O responsável autoriza as solicitações de acesso da área. Marcar aqui transfere a responsabilidade de quem a tinha antes.')) ?></p>
    <div class="grade-3">
      <?php foreach ($areas as $a): ?>
        <label class="checkbox"><input type="checkbox" name="areas[]" value="<?= e($a['id']) ?>"<?= in_array($a['id'], $areasMarcadas, true) ? ' checked' : '' ?>>
          <span><?= e($a['nome']) ?><?php if ($a['resp_nome'] && ($novo || $a['responsavel_user_id'] !== $u['id'])): ?><br><small><?= e(t('atual:')) ?> <?= e($a['resp_nome']) ?></small><?php endif; ?></span></label>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="cartao">
    <h2><?= e(t('Pessoa vinculada')) ?></h2>
    <label class="campo"><span><?= e(t('Colaborador')) ?></span>
      <select name="pessoa_id"><option value=""><?= e(t('— Sem vínculo —')) ?></option>
        <?php foreach ($pessoas as $p): ?><option value="<?= e($p['id']) ?>"<?= selected($val('pessoa_id'), $p['id']) ?>><?= e($p['nome'] . ($p['matricula'] ? ' · ' . $p['matricula'] : '') . ($p['empresa'] ? ' · ' . $p['empresa'] : '')) ?></option><?php endforeach; ?>
      </select>
      <small><?= e(t('Usada como responsável interno padrão na portaria.')) ?></small></label>
  </section>

  <div class="form-acoes">
    <a class="btn btn-secundario" href="<?= e(url('/usuarios')) ?>"><?= e(t('Voltar')) ?></a>
    <button class="btn btn-primario" type="submit"><?= $novo ? t('Criar usuário') : t('Salvar alterações') ?></button>
  </div>
</form>
<?php if (!$novo && ($u['auth_origem'] ?? '') === 'ldap'): ?>
<form method="post" action="<?= e(url('/usuarios/' . $u['id'] . '/sincronizar-ad')) ?>" id="form-sinc-ad"><?= csrf_field() ?></form>
<?php endif; ?>
