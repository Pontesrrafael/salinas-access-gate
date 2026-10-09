<?php
$novo = $u === null;
$val = static fn (string $k, string $d = '') => old($old, $k, $u[$k] ?? $d);
$areasMarcadas = $old['areas'] ?? array_column(array_filter($areas, fn ($a) => !$novo && $a['responsavel_user_id'] === $u['id']), 'id');
?>
<?php $origem = old($old, 'auth_origem', $u['auth_origem'] ?? 'local'); ?>
<form method="post" action="<?= e($novo ? url('/usuarios') : url('/usuarios/' . $u['id'])) ?>" id="form-usuario">
  <?= csrf_field() ?>
  <?php if ($ldap || $origem === 'ldap'): ?>
  <section class="cartao">
    <div class="cartao-cabecalho"><h2>Autenticação</h2>
      <?php if (!$novo && ($u['auth_origem'] ?? '') === 'ldap'): ?>
        <span class="dica">Última sincronização: <?= e(fmt_data_hora($u['ldap_sincronizado_em'])) ?></span>
      <?php endif; ?>
    </div>
    <div class="alternador alternador-claro" role="radiogroup">
      <label class="alt-opcao"><input type="radio" name="auth_origem" value="local"<?= $origem === 'local' ? ' checked' : '' ?>> Conta local (senha do sistema)</label>
      <label class="alt-opcao"><input type="radio" name="auth_origem" value="ldap"<?= $origem === 'ldap' ? ' checked' : '' ?><?= $ldap ? '' : ' disabled' ?>> Active Directory (usuário de rede)</label>
    </div>
    <div id="bloco-ad" class="bloco-ad"<?= $origem === 'ldap' ? '' : ' hidden' ?>>
      <input type="hidden" name="ldap_guid" id="ldap_guid" value="<?= e(old($old, 'ldap_guid', $u['ldap_guid'] ?? '')) ?>">
      <div class="busca">
        <?= icon('busca', 'icon busca-icone') ?>
        <input type="search" id="busca-ad" class="alto" placeholder="Buscar no AD por nome, usuário de rede, e-mail ou matrícula" autocomplete="off" data-endpoint="<?= e(url('/api/ldap/busca')) ?>">
        <ul class="resultados" id="res-ad" hidden></ul>
      </div>
      <div id="ad-selecionado" class="selecionado"<?= ($u['ldap_usuario'] ?? '') !== '' ? '' : ' hidden' ?>>
        <?php if (!empty($u['ldap_usuario'])): ?>
          <strong><?= e($u['ldap_usuario']) ?></strong><span class="sub mono"><?= e($u['ldap_dn'] ?? '') ?></span>
        <?php endif; ?>
      </div>
      <p class="dica">A senha é a mesma da rede (validada no AD a cada login). Conta desativada no AD perde o acesso aqui na próxima sincronização.</p>
      <?php if (!$novo && ($u['auth_origem'] ?? '') === 'ldap' && $ldap): ?>
        <button type="submit" class="btn btn-secundario btn-sm" form="form-sinc-ad">Sincronizar agora com o AD</button>
      <?php endif; ?>
    </div>
    <?php if (!$ldap): ?><p class="dica aviso-texto">Integração LDAP desabilitada no .env — este usuário não conseguirá entrar até a integração ser reativada ou ele voltar a ser conta local.</p><?php endif; ?>
  </section>
  <?php endif; ?>
  <section class="cartao">
    <h2>Dados de acesso</h2>
    <div class="grade-3">
      <label class="campo"><span>Nome <span class="obrigatorio">*</span></span><input name="nome" required maxlength="150" value="<?= e($val('nome')) ?>"></label>
      <label class="campo"><span>E-mail corporativo <span class="obrigatorio">*</span></span><input type="email" name="email" required maxlength="190" value="<?= e($val('email')) ?>" autocomplete="off"></label>
      <label class="campo"><span>Matrícula</span><input name="matricula" maxlength="50" value="<?= e($val('matricula')) ?>"></label>
      <label class="campo"><span>Perfil <span class="obrigatorio">*</span></span>
        <select name="papel" required><?php foreach (App\Core\Auth::PAPEIS as $k => $r): ?><option value="<?= e($k) ?>"<?= selected($val('papel', 'porteiro'), $k) ?>><?= e($r) ?></option><?php endforeach; ?></select></label>
      <label class="checkbox"><input type="checkbox" name="seguranca_patrimonial" value="1"<?= checked($val('seguranca_patrimonial', '0')) ?>> <span>Segurança Patrimonial<br><small>Pode autorizar solicitações de qualquer área.</small></span></label>
      <?php if (!$novo): ?><label class="checkbox"><input type="checkbox" name="ativo" value="1"<?= checked($u['ativo']) ?>> <span>Conta ativa<br><small>Desativar encerra o acesso imediatamente.</small></span></label><?php endif; ?>
    </div>
    <div id="bloco-senha"<?= $origem === 'ldap' ? ' hidden' : '' ?>>
    <?php if ($novo): ?>
      <label class="campo"><span>Senha inicial</span><input type="password" name="senha" maxlength="72" autocomplete="new-password">
        <small>Deixe em branco para gerar uma senha temporária. A troca é obrigatória no primeiro acesso.</small></label>
    <?php else: ?>
      <label class="checkbox"><input type="checkbox" name="redefinir_senha" value="1"> <span>Gerar nova senha temporária<br><small>A senha será exibida uma única vez após salvar.</small></span></label>
    <?php endif; ?>
  </section>

  <section class="cartao">
    <h2>Responsável pelas áreas</h2>
    <p class="dica">O responsável autoriza as solicitações de acesso da área. Marcar aqui transfere a responsabilidade de quem a tinha antes.</p>
    <div class="grade-3">
      <?php foreach ($areas as $a): ?>
        <label class="checkbox"><input type="checkbox" name="areas[]" value="<?= e($a['id']) ?>"<?= in_array($a['id'], $areasMarcadas, true) ? ' checked' : '' ?>>
          <span><?= e($a['nome']) ?><?php if ($a['resp_nome'] && ($novo || $a['responsavel_user_id'] !== $u['id'])): ?><br><small>atual: <?= e($a['resp_nome']) ?></small><?php endif; ?></span></label>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="cartao">
    <h2>Pessoa vinculada</h2>
    <label class="campo"><span>Colaborador</span>
      <select name="pessoa_id"><option value="">— Sem vínculo —</option>
        <?php foreach ($pessoas as $p): ?><option value="<?= e($p['id']) ?>"<?= selected($val('pessoa_id'), $p['id']) ?>><?= e($p['nome'] . ($p['matricula'] ? ' · ' . $p['matricula'] : '') . ($p['empresa'] ? ' · ' . $p['empresa'] : '')) ?></option><?php endforeach; ?>
      </select>
      <small>Usada como responsável interno padrão na portaria.</small></label>
  </section>

  <div class="form-acoes">
    <a class="btn btn-secundario" href="<?= e(url('/usuarios')) ?>">Voltar</a>
    <button class="btn btn-primario" type="submit"><?= $novo ? 'Criar usuário' : 'Salvar alterações' ?></button>
  </div>
</form>
<?php if (!$novo && ($u['auth_origem'] ?? '') === 'ldap'): ?>
<form method="post" action="<?= e(url('/usuarios/' . $u['id'] . '/sincronizar-ad')) ?>" id="form-sinc-ad"><?= csrf_field() ?></form>
<?php endif; ?>
