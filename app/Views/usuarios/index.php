<?php slot('acoes', '<a class="btn btn-ouro" href="' . e(url('/usuarios/novo')) . '">' . icon('mais') . ' Novo usuário</a>'); ?>
<?php if ($senhaTemp && $senhaTemp['senha']): ?>
  <section class="cartao cartao-sucesso">
    <h2>Senha temporária — exibida apenas uma vez</h2>
    <p>Usuário <strong><?= e($senhaTemp['email']) ?></strong> · senha temporária: <code class="mono destaque-senha"><?= e($senhaTemp['senha']) ?></code>
      <button type="button" class="btn btn-secundario btn-sm" data-copiar="<?= e($senhaTemp['senha']) ?>"><?= icon('copiar', 'icon icon-sm') ?> Copiar</button></p>
    <p class="dica">Entregue por canal seguro (pessoalmente ou Teams). A troca é obrigatória no primeiro acesso.</p>
  </section>
<?php endif; ?>
<?php if ($areasSemResp): ?>
  <div class="alerta alerta-aviso">Áreas sem responsável (ninguém além de Admin/Seg. Patrimonial pode autorizar): <?= e(implode(', ', array_column($areasSemResp, 'nome'))) ?>.</div>
<?php endif; ?>
<section class="cartao sem-padding">
  <div class="tabela-rolagem">
    <table class="tabela">
      <thead><tr><th>Usuário</th><th>Perfil</th><th class="ocultar-md">Áreas (responsável)</th><th class="ocultar-md">Pessoa vinculada</th><th class="ocultar-sm">Último acesso</th><th>Situação</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $u): ?>
        <tr>
          <td><strong><?= e($u['nome']) ?></strong>
            <?= ($u['auth_origem'] ?? 'local') === 'ldap' ? badge('AD', 'info') : '' ?>
            <span class="sub"><?= e($u['email']) ?><?= !empty($u['ldap_usuario']) ? ' · ' . e($u['ldap_usuario']) : '' ?></span></td>
          <td><?= badge(papel_label($u['papel']), $u['papel'] === 'admin' ? 'ouro' : 'neutro') ?>
            <?php if ((int) $u['seguranca_patrimonial']): ?><span class="sub">Seg. Patrimonial</span><?php endif; ?></td>
          <td class="ocultar-md mudo"><?= e($u['areas'] ?? '—') ?></td>
          <td class="ocultar-md"><?= $u['pessoa_id'] ? '<a href="' . e(url('/pessoas/' . $u['pessoa_id'])) . '">' . e($u['pessoa_nome']) . '</a>' : '<span class="mudo">—</span>' ?></td>
          <td class="ocultar-sm nowrap"><?= e(fmt_data_hora($u['ultimo_login_em'])) ?></td>
          <td><?= (int) $u['ativo'] ? badge('Ativo', 'sucesso') : badge('Desativado', 'perigo') ?>
            <?php if ((int) $u['must_change_password'] && ($u['auth_origem'] ?? 'local') === 'local'): ?><span class="sub">troca de senha pendente</span><?php endif; ?></td>
          <td class="acoes"><a class="btn btn-fantasma btn-sm" href="<?= e(url('/usuarios/' . $u['id'])) ?>"><?= icon('editar', 'icon icon-sm') ?> Editar</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<p class="dica">Perfis: <strong>Admin/TI</strong> (tudo) · <strong>Supervisor</strong> (cadastros, relatórios, auditoria, CPF completo) · <strong>SSMA</strong> (verificação de aptidão e documentos) · <strong>Porteiro</strong> (operação da portaria).</p>
