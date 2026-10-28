<form method="post" action="<?= e(url('/configuracoes')) ?>">
  <?= csrf_field() ?>
  <section class="cartao">
    <h2><?= e(t('Organização')) ?></h2>
    <div class="grade-2 compacta">
      <label class="campo"><span><?= e(t('Razão social')) ?></span><input name="org_nome" maxlength="200" value="<?= e($cfg['org_nome'] ?? '') ?>"></label>
      <label class="campo"><span><?= e(t('CNPJ')) ?></span><input name="org_cnpj" maxlength="20" value="<?= e($cfg['org_cnpj'] ?? '') ?>"></label>
    </div>
  </section>
  <section class="cartao">
    <h2><?= e(t('Regras da portaria')) ?></h2>
    <div class="grade-2 compacta">
      <label class="checkbox"><input type="checkbox" name="exigir_destino" value="1"<?= checked($cfg['exigir_destino'] ?? '0') ?>> <span><?= e(t('Exigir destino na entrada')) ?><br><small><?= e(t('Campo obrigatório no registro.')) ?></small></span></label>
      <label class="campo"><span><?= e(t('Alerta de permanência longa (horas)')) ?></span><input type="number" name="alerta_permanencia_horas" min="1" max="48" value="<?= e($cfg['alerta_permanencia_horas'] ?? '8') ?>">
        <small><?= e(t('Destaca visitantes/terceiros acima deste tempo em “Quem está no site”.')) ?></small></label>
    </div>
  </section>
  <section class="cartao">
    <h2><?= e(t('Solicitações externas')) ?></h2>
    <div class="grade-2 compacta">
      <label class="checkbox"><input type="checkbox" name="form_publico_ativo" value="1"<?= checked($cfg['form_publico_ativo'] ?? '1') ?>> <span><?= e(t('Formulário público ativo')) ?><br><small><?= e($linkPublico) ?></small></span></label>
      <label class="campo"><span><?= e(t('Validade do link externo (horas)')) ?></span><input type="number" name="link_externo_validade_horas" min="1" max="720" value="<?= e($cfg['link_externo_validade_horas'] ?? '72') ?>"></label>
    </div>
  </section>
  <section class="cartao">
    <h2><?= e(t('Privacidade (LGPD) e segurança')) ?></h2>
    <p class="dica"><?= e(t('Aparece no Aviso de Privacidade. A retenção é aplicada todo dia pelo comando aplicar-retencao.')) ?> <a href="<?= e(url('/privacidade')) ?>" target="_blank" rel="noopener"><?= e(t('Ver aviso')) ?></a></p>
    <div class="grade-2 compacta">
      <label class="campo"><span><?= e(t('Encarregado (DPO)')) ?></span><input name="encarregado_nome" maxlength="150" value="<?= e($cfg['encarregado_nome'] ?? '') ?>"></label>
      <label class="campo"><span><?= e(t('E-mail do Encarregado')) ?></span><input type="email" name="encarregado_email" maxlength="190" value="<?= e($cfg['encarregado_email'] ?? '') ?>"></label>
      <label class="campo"><span><?= e(t('Fotos da portaria (dias)')) ?></span><input type="number" name="retencao_fotos_dias" min="7" max="3650" value="<?= e($cfg['retencao_fotos_dias'] ?? '180') ?>"></label>
      <label class="campo"><span><?= e(t('Solicitações de acesso (meses)')) ?></span><input type="number" name="retencao_solicitacoes_meses" min="1" max="120" value="<?= e($cfg['retencao_solicitacoes_meses'] ?? '12') ?>"></label>
      <label class="campo"><span><?= e(t('Observações dos registros (meses)')) ?></span><input type="number" name="retencao_registros_meses" min="6" max="240" value="<?= e($cfg['retencao_registros_meses'] ?? '24') ?>"></label>
      <label class="campo"><span><?= e(t('Cadastros sem acesso — visitantes/terceiros (meses)')) ?></span><input type="number" name="retencao_cadastros_meses" min="6" max="240" value="<?= e($cfg['retencao_cadastros_meses'] ?? '24') ?>"></label>
      <label class="campo"><span><?= e(t('Trilha de auditoria (meses)')) ?></span><input type="number" name="retencao_auditoria_meses" min="6" max="240" value="<?= e($cfg['retencao_auditoria_meses'] ?? '60') ?>">
        <small><?= e(t('Mínimo de 6 meses (Marco Civil da Internet, art. 15).')) ?></small></label>
      <label class="checkbox"><input type="checkbox" name="mfa_obrigatorio_admin" value="1"<?= checked($cfg['mfa_obrigatorio_admin'] ?? '1') ?>> <span><?= e(t('Exigir autenticação em dois fatores para Admin/TI')) ?><br><small><?= e(t('Vale para contas locais; contas do AD seguem a política da rede.')) ?></small></span></label>
    </div>
  </section>
  <section class="cartao">
    <h2><?= e(t('Chamados')) ?></h2>
    <p class="dica"><?= e(t('O calendário vale para novos chamados de categorias com SLA em horário comercial.')) ?></p>
    <div class="grade-2 compacta">
      <label class="checkbox"><input type="checkbox" name="horario_comercial_ativo" value="1"<?= checked($cfg['horario_comercial_ativo'] ?? '1') ?>> <?= e(t('Ativar horário comercial no SLA')) ?></label>
      <label class="checkbox"><input type="checkbox" name="email_ativo" value="1"<?= checked($cfg['email_ativo'] ?? '0') ?>> <?= e(t('Ativar notificações por e-mail')) ?></label>
      <label class="campo"><span><?= e(t('Início do expediente')) ?></span><input type="time" name="horario_inicio" required value="<?= e($cfg['horario_inicio'] ?? '07:00') ?>"></label>
      <label class="campo"><span><?= e(t('Fim do expediente')) ?></span><input type="time" name="horario_fim" required value="<?= e($cfg['horario_fim'] ?? '17:00') ?>"></label>
    </div>
    <fieldset><legend><?= e(t('Dias úteis')) ?></legend>
      <?php $diasSelecionados = explode(',', $cfg['horario_dias'] ?? '1,2,3,4,5'); ?>
      <?php foreach ([1 => t('Segunda-feira'), 2 => t('Terça-feira'), 3 => t('Quarta-feira'), 4 => t('Quinta-feira'), 5 => t('Sexta-feira'), 6 => t('Sábado'), 7 => t('Domingo')] as $dia => $rotulo): ?>
        <label class="checkbox"><input type="checkbox" name="horario_dias[]" value="<?= $dia ?>"<?= checked(in_array((string) $dia, $diasSelecionados, true)) ?>> <?= e($rotulo) ?></label>
      <?php endforeach; ?>
    </fieldset>
    <label class="campo"><span><?= e(t('Feriados')) ?></span><textarea name="feriados" rows="3" maxlength="50000"><?= e($cfg['feriados'] ?? '') ?></textarea><small><?= e(t('Datas AAAA-MM-DD separadas por vírgula ou linha.')) ?></small></label>
    <div class="grade-2 compacta">
      <label class="campo"><span><?= e(t('Retenção de chamados fechados (meses)')) ?></span><input type="number" name="retencao_chamados_meses" min="6" max="240" required value="<?= e($cfg['retencao_chamados_meses'] ?? '24') ?>"></label>
      <label class="campo"><span><?= e(t('Fechar chamados solucionados após (dias)')) ?></span><input type="number" name="chamados_fechar_dias" min="0" max="365" required value="<?= e($cfg['chamados_fechar_dias'] ?? '7') ?>"><small><?= e(t('Zero desativa o fechamento automático.')) ?></small></label>
    </div>
  </section>
  <div class="form-acoes"><button class="btn btn-primario" type="submit"><?= e(t('Salvar configurações')) ?></button></div>
</form>

<section class="cartao sem-padding">
  <div class="cartao-cabecalho pad"><h2><?= e(t('Áreas e responsáveis')) ?></h2></div>
  <div class="tabela-rolagem">
    <table class="tabela">
      <thead><tr><th><?= e(t('Área')) ?></th><th><?= e(t('Responsável')) ?></th><th><?= e(t('Ativa')) ?></th><th></th></tr></thead>
      <tbody>
      <?php foreach ($areas as $a): ?>
        <tr><td colspan="4">
          <form method="post" action="<?= e(url('/configuracoes/areas/' . $a['id'])) ?>" class="filtros" data-tour="filtros">
            <?= csrf_field() ?>
            <label class="campo"><span class="sr"><?= e(t('Nome')) ?></span><input name="nome" required maxlength="120" value="<?= e($a['nome']) ?>"></label>
            <label class="campo"><span class="sr"><?= e(t('Responsável')) ?></span>
              <select name="responsavel_user_id"><option value=""><?= e(t('— Sem responsável —')) ?></option>
                <?php foreach ($usuarios as $u): ?><option value="<?= e($u['id']) ?>"<?= selected($a['responsavel_user_id'], $u['id']) ?>><?= e($u['nome']) ?></option><?php endforeach; ?>
              </select></label>
            <label class="checkbox"><input type="checkbox" name="ativo" value="1"<?= checked($a['ativo']) ?>> <?= e(t('Ativa')) ?></label>
            <button class="btn btn-secundario btn-sm" type="submit"><?= e(t('Salvar')) ?></button>
          </form>
        </td></tr>
      <?php endforeach; ?>
      <tr><td colspan="4">
        <form method="post" action="<?= e(url('/configuracoes/areas')) ?>" class="filtros">
          <?= csrf_field() ?>
          <label class="campo"><span><?= e(t('Nova área')) ?></span><input name="nome" required maxlength="120"></label>
          <label class="campo"><span><?= e(t('Responsável')) ?></span>
            <select name="responsavel_user_id"><option value=""><?= e(t('— Sem responsável —')) ?></option>
              <?php foreach ($usuarios as $u): ?><option value="<?= e($u['id']) ?>"><?= e($u['nome']) ?></option><?php endforeach; ?>
            </select></label>
          <button class="btn btn-ouro btn-sm" type="submit"><?= icon('mais', 'icon icon-sm') ?> <?= e(t('Adicionar área')) ?></button>
        </form>
      </td></tr>
      </tbody>
    </table>
  </div>
</section>

<section class="cartao ldap-status">
  <div class="cartao-cabecalho"><h2><?= e(t('Active Directory / LDAP')) ?></h2>
    <?= $ldap['enabled'] ? badge(t('Habilitado'), 'sucesso') : badge(t('Desabilitado'), 'neutro') ?></div>
  <?php if (!$ldapExt): ?><div class="alerta alerta-aviso"><?= e(t('Extensão PHP')) ?> <code><?= e('ldap') ?></code> <?= e(t('não carregada. XAMPP: habilite')) ?> <code><?= e('extension=ldap') ?></code> <?= e(t('no php.ini · Ubuntu:')) ?> <code><?= e('apt install php8.3-ldap') ?></code>.</div><?php endif; ?>
  <dl class="dados">
    <div><dt><?= e(t('Servidor(es)')) ?></dt><dd class="mono"><?= e($ldap['host'] ?: '—') ?></dd></div>
    <div><dt><?= e(t('Base DN')) ?></dt><dd class="mono"><?= e($ldap['base_dn'] ?: '—') ?></dd></div>
    <div><dt><?= e(t('Conta de serviço')) ?></dt><dd class="mono"><?= e($ldap['bind_dn'] ?: '—') ?></dd></div>
    <div><dt><?= e(t('Segurança')) ?></dt><dd><?= e(str_contains($ldap['host'], 'ldaps://') ? 'LDAPS' : ($ldap['starttls'] ? 'StartTLS' : t('sem TLS'))) ?> <?= e(t('· certificado')) ?> <?= $ldap['tls_verify'] ? t('verificado') : t('NÃO verificado') ?></dd></div>
    <div><dt><?= e(t('Provisionamento automático')) ?></dt><dd><?= $ldap['auto_provision'] ? t('Sim (1º login de quem está em grupo mapeado)') : t('Não (admin vincula manualmente)') ?></dd></div>
    <div><dt><?= e(t('Perfis pelos grupos')) ?></dt><dd><?= $ldap['sync_roles'] ? t('Sim (a cada login/sincronização)') : t('Não (definidos no Access Gate)') ?></dd></div>
    <div><dt><?= e(t('Usuários vinculados ao AD')) ?></dt><dd><?= (int) $ldapUsuarios ?></dd></div>
  </dl>
  <?php if ($ldapTeste): ?>
    <?php if ($ldapTeste['ok']): ?>
      <div class="alerta alerta-sucesso compacto-bloco">
        <?= e(t('Conectado em')) ?> <?= (int) $ldapTeste['tempo_ms'] ?> <?= e(t('ms ·')) ?> <?= (int) $ldapTeste['usuarios'] ?> <?= e(t('conta(s) encontradas pelo filtro.')) ?>
        <ul><?php foreach ($ldapTeste['grupos'] as $papel => $g): ?>
          <li><?= e($papel === 'seguranca_patrimonial' ? t('Seg. Patrimonial') : papel_label($papel)) ?>: <span class="mono"><?= e($g['dn']) ?></span> — <?php if ($g['existe']): ?><?= e(t('encontrado')) ?><?php else: ?><strong><?= e(t('NÃO encontrado')) ?></strong><?php endif; ?></li>
        <?php endforeach; ?></ul>
      </div>
    <?php else: ?>
      <div class="alerta alerta-erro"><?= e($ldapTeste['erro']) ?></div>
    <?php endif; ?>
  <?php endif; ?>
  <form method="post" action="<?= e(url('/configuracoes/ldap/testar')) ?>" class="form-acoes">
    <?= csrf_field() ?>
    <button class="btn btn-secundario" type="submit"<?= $ldap['enabled'] && $ldapExt ? '' : ' disabled' ?>><?= e(t('Testar conexão')) ?></button>
  </form>
  <p class="dica"><?= e(t('Parâmetros e senha da conta de serviço ficam apenas no arquivo')) ?> <code><?= e('.env') ?></code> <?= e(t('do servidor (variáveis')) ?> <code><?= e('LDAP_*') ?></code><?= e(t('). A conta local de admin continua funcionando mesmo se o AD estiver fora do ar.')) ?></p>
</section>
