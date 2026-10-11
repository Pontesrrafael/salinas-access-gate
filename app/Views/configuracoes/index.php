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
      <label class="checkbox"><input type="checkbox" name="alerta_sem_integracao_mina" value="1"<?= checked($cfg['alerta_sem_integracao_mina'] ?? '1') ?>> <span>Alertar visitante/terceiro sem integração na mina<br><small>Alerta vermelho na portaria; o porteiro confirma e fica registrado.</small></span></label>
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
  <section class="cartao">
    <h2>Privacidade (LGPD)</h2>
    <div class="grade-2 compacta">
      <label class="campo"><span>Encarregado (DPO) — nome</span><input name="lgpd_encarregado_nome" maxlength="150" value="<?= e($cfg['lgpd_encarregado_nome'] ?? '') ?>"></label>
      <label class="campo"><span>Encarregado (DPO) — e-mail</span><input type="email" name="lgpd_encarregado_email" maxlength="190" value="<?= e($cfg['lgpd_encarregado_email'] ?? '') ?>"><small>Aparece no <a href="<?= e(url('/privacidade')) ?>" target="_blank" rel="noopener">aviso de privacidade</a>.</small></label>
      <label class="campo"><span>Anonimizar visitantes/terceiros sem acesso há (meses)</span><input type="number" name="lgpd_retencao_meses" min="6" max="120" value="<?= e($cfg['lgpd_retencao_meses'] ?? '24') ?>"></label>
      <label class="campo"><span>Apagar fotos da portaria após (meses)</span><input type="number" name="lgpd_fotos_meses" min="1" max="120" value="<?= e($cfg['lgpd_fotos_meses'] ?? '12') ?>"><small>Aplicado mensalmente (cron <code>lgpd-retencao</code>).</small></label>
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
  <div class="cartao-cabecalho"><h2>Active Directory / LDAP <small>(opcional)</small></h2>
    <?= $ldap['enabled'] ? badge('Habilitado', 'sucesso') : badge('Desabilitado — só contas locais', 'neutro') ?></div>
  <?php if (!$ldapExt): ?><div class="alerta alerta-aviso">Extensão PHP <code>ldap</code> não carregada. XAMPP: habilite <code>extension=ldap</code> no php.ini · Ubuntu: <code>apt install php8.3-ldap</code> · Docker: já incluída na imagem.</div><?php endif; ?>

  <form method="post" action="<?= e(url('/configuracoes/ldap')) ?>" data-ldap-form autocomplete="off">
    <?= csrf_field() ?>
    <label class="checkbox"><input type="checkbox" name="enabled" value="1" data-ldap-liga<?= checked($ldap['enabled'] ? 1 : 0) ?>>
      <span>Usar o Active Directory para login<br><small>Desligado, o sistema funciona só com contas locais (e-mail ou usuário + senha). Contas locais continuam entrando mesmo com o AD ligado ou fora do ar.</small></span></label>

    <div class="ldap-campos" data-ldap-campos<?= $ldap['enabled'] ? '' : ' hidden' ?>>
      <h3>Conexão</h3>
      <div class="grade-2 compacta">
        <label class="campo"><span>Servidor(es)</span><input name="host" maxlength="500" class="mono" placeholder="ldaps://dc01.salinasgold.local ldaps://dc02.salinasgold.local" value="<?= e($ldap['host']) ?>">
          <small>Separe vários controladores por espaço (failover). Use <code>ldaps://</code> ou marque StartTLS.</small></label>
        <label class="campo"><span>Porta</span><input type="number" name="port" min="0" max="65535" value="<?= (int) $ldap['port'] ?>"><small>0 = padrão (636 LDAPS / 389 LDAP)</small></label>
        <label class="checkbox"><input type="checkbox" name="starttls" value="1"<?= checked($ldap['starttls'] ? 1 : 0) ?>> <span>StartTLS na porta 389</span></label>
        <label class="checkbox"><input type="checkbox" name="tls_verify" value="1"<?= checked($ldap['tls_verify'] ? 1 : 0) ?>> <span>Verificar certificado do servidor<br><small>Desmarque só em homologação.</small></span></label>
        <label class="campo"><span>Tempo limite (s)</span><input type="number" name="timeout" min="1" max="60" value="<?= (int) $ldap['timeout'] ?>"></label>
        <label class="campo"><span>Certificado da CA interna (PEM)</span><textarea name="ca_pem" rows="3" class="mono" placeholder="-----BEGIN CERTIFICATE-----&#10;...&#10;-----END CERTIFICATE-----"></textarea>
          <small><?= $ldap['ca_cert'] !== '' ? 'Atual: <span class="mono">' . e(basename($ldap['ca_cert'])) . '</span>. Cole outro para substituir.' : 'Opcional. Cole o certificado raiz da CA do AD.' ?></small></label>
      </div>

      <h3>Diretório e conta de serviço</h3>
      <div class="grade-2 compacta">
        <label class="campo"><span>Base DN</span><input name="base_dn" maxlength="500" class="mono" placeholder="DC=salinasgold,DC=local" value="<?= e($ldap['base_dn']) ?>"></label>
        <label class="campo"><span>OU dos usuários <small>(opcional)</small></span><input name="users_base_dn" maxlength="500" class="mono" placeholder="OU=Usuarios,DC=salinasgold,DC=local" value="<?= e($ldap['users_base_dn']) ?>"></label>
        <label class="campo"><span>Conta de serviço (DN)</span><input name="bind_dn" maxlength="500" class="mono" placeholder="CN=svc-accessgate,OU=Servicos,DC=salinasgold,DC=local" value="<?= e($ldap['bind_dn']) ?>"></label>
        <label class="campo"><span>Senha da conta de serviço</span><input type="password" name="bind_password" maxlength="256" autocomplete="new-password" placeholder="<?= $ldap['bind_password'] !== '' ? '•••••••• (definida — deixe em branco para manter)' : 'não definida' ?>">
          <small>Gravada cifrada no banco (libsodium). Nunca é exibida.</small></label>
      </div>

      <h3>Perfis e provisionamento</h3>
      <div class="grade-2 compacta">
        <label class="checkbox"><input type="checkbox" name="auto_provision" value="1"<?= checked($ldap['auto_provision'] ? 1 : 0) ?>> <span>Criar usuário no 1º login<br><small>Só para quem está em um grupo mapeado abaixo.</small></span></label>
        <label class="checkbox"><input type="checkbox" name="sync_roles" value="1"<?= checked($ldap['sync_roles'] ? 1 : 0) ?>> <span>Perfil definido pelos grupos do AD<br><small>Desmarcado, o perfil é definido no Access Gate.</small></span></label>
        <label class="checkbox"><input type="checkbox" name="nested_groups" value="1"<?= checked($ldap['nested_groups'] ? 1 : 0) ?>> <span>Considerar grupos aninhados</span></label>
      </div>
      <div class="grade-2 compacta">
        <?php foreach (['group_admin' => 'Administrador', 'group_supervisor' => 'Supervisor', 'group_ssma' => 'SSMA', 'group_porteiro' => 'Porteiro', 'group_seg_patrimonial' => 'Segurança Patrimonial (complemento)'] as $k => $rot): ?>
          <label class="campo"><span>Grupo — <?= e($rot) ?></span><input name="<?= $k ?>" maxlength="500" class="mono" placeholder="CN=SG-AccessGate-...,OU=Grupos,DC=salinasgold,DC=local" value="<?= e($ldap[$k]) ?>"></label>
        <?php endforeach; ?>
      </div>
      <p class="dica">Usuários vinculados ao AD: <strong><?= (int) $ldapUsuarios ?></strong>.</p>
    </div>

    <div class="form-acoes">
      <button class="btn btn-primario" type="submit">Salvar LDAP</button>
      <label class="checkbox"><input type="checkbox" name="testar" value="1" checked> Testar a conexão ao salvar</label>
    </div>
  </form>

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
  <p class="dica">O que for salvo aqui tem prioridade sobre as variáveis <code>LDAP_*</code> do <code>.env</code>, que continuam valendo como padrão. <?= $ldap['configurado_pela_tela'] ? 'Origem atual: <strong>tela</strong>.' : 'Origem atual: <strong>.env</strong>.' ?></p>
</section>
