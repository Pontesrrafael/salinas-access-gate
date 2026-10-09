<form method="post" action="<?= e(url('/configuracoes')) ?>">
  <?= csrf_field() ?>
  <section class="cartao">
    <h2>Organização</h2>
    <div class="grade-2 compacta">
      <label class="campo"><span>Razão social</span><input name="org_nome" maxlength="200" value="<?= e($cfg['org_nome'] ?? '') ?>"></label>
      <label class="campo"><span>CNPJ</span><input name="org_cnpj" maxlength="20" value="<?= e($cfg['org_cnpj'] ?? '') ?>"></label>
    </div>
  </section>
  <section class="cartao">
    <h2>Regras da portaria</h2>
    <div class="grade-2 compacta">
      <label class="checkbox"><input type="checkbox" name="exigir_destino" value="1"<?= checked($cfg['exigir_destino'] ?? '0') ?>> <span>Exigir destino na entrada<br><small>Campo obrigatório no registro.</small></span></label>
      <label class="campo"><span>Alerta de permanência longa (horas)</span><input type="number" name="alerta_permanencia_horas" min="1" max="48" value="<?= e($cfg['alerta_permanencia_horas'] ?? '8') ?>">
        <small>Destaca visitantes/terceiros acima deste tempo em “Quem está no site”.</small></label>
    </div>
  </section>
  <section class="cartao">
    <h2>Solicitações externas</h2>
    <div class="grade-2 compacta">
      <label class="checkbox"><input type="checkbox" name="form_publico_ativo" value="1"<?= checked($cfg['form_publico_ativo'] ?? '1') ?>> <span>Formulário público ativo<br><small><?= e($linkPublico) ?></small></span></label>
      <label class="campo"><span>Validade do link externo (horas)</span><input type="number" name="link_externo_validade_horas" min="1" max="720" value="<?= e($cfg['link_externo_validade_horas'] ?? '72') ?>"></label>
    </div>
  </section>
  <div class="form-acoes"><button class="btn btn-primario" type="submit">Salvar configurações</button></div>
</form>

<section class="cartao sem-padding">
  <div class="cartao-cabecalho pad"><h2>Áreas e responsáveis</h2></div>
  <div class="tabela-rolagem">
    <table class="tabela">
      <thead><tr><th>Área</th><th>Responsável</th><th>Ativa</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($areas as $a): ?>
        <tr><td colspan="4">
          <form method="post" action="<?= e(url('/configuracoes/areas/' . $a['id'])) ?>" class="filtros">
            <?= csrf_field() ?>
            <label class="campo"><span class="sr">Nome</span><input name="nome" required maxlength="120" value="<?= e($a['nome']) ?>"></label>
            <label class="campo"><span class="sr">Responsável</span>
              <select name="responsavel_user_id"><option value="">— Sem responsável —</option>
                <?php foreach ($usuarios as $u): ?><option value="<?= e($u['id']) ?>"<?= selected($a['responsavel_user_id'], $u['id']) ?>><?= e($u['nome']) ?></option><?php endforeach; ?>
              </select></label>
            <label class="checkbox"><input type="checkbox" name="ativo" value="1"<?= checked($a['ativo']) ?>> Ativa</label>
            <button class="btn btn-secundario btn-sm" type="submit">Salvar</button>
          </form>
        </td></tr>
      <?php endforeach; ?>
      <tr><td colspan="4">
        <form method="post" action="<?= e(url('/configuracoes/areas')) ?>" class="filtros">
          <?= csrf_field() ?>
          <label class="campo"><span>Nova área</span><input name="nome" required maxlength="120"></label>
          <label class="campo"><span>Responsável</span>
            <select name="responsavel_user_id"><option value="">— Sem responsável —</option>
              <?php foreach ($usuarios as $u): ?><option value="<?= e($u['id']) ?>"><?= e($u['nome']) ?></option><?php endforeach; ?>
            </select></label>
          <button class="btn btn-ouro btn-sm" type="submit"><?= icon('mais', 'icon icon-sm') ?> Adicionar área</button>
        </form>
      </td></tr>
      </tbody>
    </table>
  </div>
</section>

<section class="cartao ldap-status">
  <div class="cartao-cabecalho"><h2>Active Directory / LDAP</h2>
    <?= $ldap['enabled'] ? badge('Habilitado', 'sucesso') : badge('Desabilitado', 'neutro') ?></div>
  <?php if (!$ldapExt): ?><div class="alerta alerta-aviso">Extensão PHP <code>ldap</code> não carregada. XAMPP: habilite <code>extension=ldap</code> no php.ini · Ubuntu: <code>apt install php8.3-ldap</code>.</div><?php endif; ?>
  <dl class="dados">
    <div><dt>Servidor(es)</dt><dd class="mono"><?= e($ldap['host'] ?: '—') ?></dd></div>
    <div><dt>Base DN</dt><dd class="mono"><?= e($ldap['base_dn'] ?: '—') ?></dd></div>
    <div><dt>Conta de serviço</dt><dd class="mono"><?= e($ldap['bind_dn'] ?: '—') ?></dd></div>
    <div><dt>Segurança</dt><dd><?= e(str_contains($ldap['host'], 'ldaps://') ? 'LDAPS' : ($ldap['starttls'] ? 'StartTLS' : 'sem TLS')) ?> · certificado <?= $ldap['tls_verify'] ? 'verificado' : 'NÃO verificado' ?></dd></div>
    <div><dt>Provisionamento automático</dt><dd><?= $ldap['auto_provision'] ? 'Sim (1º login de quem está em grupo mapeado)' : 'Não (admin vincula manualmente)' ?></dd></div>
    <div><dt>Perfis pelos grupos</dt><dd><?= $ldap['sync_roles'] ? 'Sim (a cada login/sincronização)' : 'Não (definidos no Access Gate)' ?></dd></div>
    <div><dt>Usuários vinculados ao AD</dt><dd><?= (int) $ldapUsuarios ?></dd></div>
  </dl>
  <?php if ($ldapTeste): ?>
    <?php if ($ldapTeste['ok']): ?>
      <div class="alerta alerta-sucesso compacto-bloco">
        Conectado em <?= (int) $ldapTeste['tempo_ms'] ?> ms · <?= (int) $ldapTeste['usuarios'] ?> conta(s) encontradas pelo filtro.
        <ul><?php foreach ($ldapTeste['grupos'] as $papel => $g): ?>
          <li><?= e($papel === 'seguranca_patrimonial' ? 'Seg. Patrimonial' : papel_label($papel)) ?>: <span class="mono"><?= e($g['dn']) ?></span> — <?= $g['existe'] ? 'encontrado' : '<strong>NÃO encontrado</strong>' ?></li>
        <?php endforeach; ?></ul>
      </div>
    <?php else: ?>
      <div class="alerta alerta-erro"><?= e($ldapTeste['erro']) ?></div>
    <?php endif; ?>
  <?php endif; ?>
  <form method="post" action="<?= e(url('/configuracoes/ldap/testar')) ?>" class="form-acoes">
    <?= csrf_field() ?>
    <button class="btn btn-secundario" type="submit"<?= $ldap['enabled'] && $ldapExt ? '' : ' disabled' ?>>Testar conexão</button>
  </form>
  <p class="dica">Parâmetros e senha da conta de serviço ficam apenas no arquivo <code>.env</code> do servidor (variáveis <code>LDAP_*</code>). A conta local de admin continua funcionando mesmo se o AD estiver fora do ar.</p>
</section>
