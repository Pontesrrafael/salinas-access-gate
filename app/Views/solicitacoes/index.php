<?php
$abas = ['pendentes' => 'Pendentes', 'hoje' => 'Visitas de hoje', 'minhas' => 'Criadas por mim', 'todas' => 'Todas'];
slot('acoes', '<button type="button" class="btn btn-ouro" data-abrir="dlg-nova">' . icon('mais') . ' Nova solicitação</button>');
?>
<?php if ($linkGerado): ?>
  <section class="cartao cartao-sucesso">
    <h2>Link externo gerado</h2>
    <p class="dica">Envie este link ao visitante/terceiro para que ele preencha os dados. Expira em <strong><?= e(fmt_data_hora($linkGerado['expira'])) ?></strong>.</p>
    <div class="botoes">
      <input type="text" readonly value="<?= e($linkGerado['url']) ?>" class="mono">
      <button type="button" class="btn btn-secundario" data-copiar="<?= e($linkGerado['url']) ?>"><?= icon('copiar', 'icon icon-sm') ?> Copiar</button>
    </div>
  </section>
<?php endif; ?>

<section class="cartao">
  <form method="get" action="<?= e(url('/solicitacoes')) ?>" class="filtros" data-tour="filtros">
    <input type="hidden" name="aba" value="<?= e($aba) ?>">
    <div class="botoes">
      <?php foreach ($abas as $k => $v): ?>
        <a class="btn btn-sm <?= $aba === $k ? 'btn-primario' : 'btn-secundario' ?>" href="<?= e(url('/solicitacoes', ['aba' => $k])) ?>"><?= e($v) ?></a>
      <?php endforeach; ?>
    </div>
    <label class="campo"><span>Buscar</span><input type="search" name="q" value="<?= e($q) ?>" placeholder="Nome, empresa ou documento"></label>
    <button class="btn btn-secundario" type="submit"><?= icon('busca', 'icon icon-sm') ?> Filtrar</button>
  </form>
</section>

<section class="cartao sem-padding">
  <?php if (!$lista): ?>
    <p class="mudo vazio">Nenhuma solicitação.</p>
  <?php else: ?>
  <div class="tabela-rolagem">
    <table class="tabela">
      <thead><tr><th>Visitante / Terceiro</th><th>Área</th><th class="ocultar-sm">Data prevista</th><th class="ocultar-md">Origem</th><th>Status</th><th class="acoes">Ações</th></tr></thead>
      <tbody>
      <?php foreach ($lista as $s):
          $finalizada = in_array($s['status'], ['aprovada', 'recusada', 'realizada', 'expirada'], true);
          $aguardandoForm = (int) $s['aguardando_preenchimento'] === 1; ?>
        <tr>
          <td>
            <strong><?= e($s['visitante_nome']) ?></strong>
            <?php if ((int) $s['atividade_risco'] === 1): ?><?= badge('Risco', 'perigo') ?><?php endif; ?>
            <span class="sub"><?= e(implode(' · ', array_filter([$s['empresa'], $s['tipo'] === 'terceiro' ? 'Terceiro' : 'Visitante', $s['visitante_doc'], $s['contato_telefone']]))) ?></span>
            <?php if ($s['motivo']): ?><span class="sub"><?= e(mb_strimwidth($s['motivo'], 0, 120, '…')) ?></span><?php endif; ?>
            <?php if ($s['pessoa_nome']): ?><span class="sub"><?= icon('link', 'icon icon-sm') ?> Vinculada a <?= e($s['pessoa_nome']) ?></span><?php endif; ?>
          </td>
          <td><?= e($s['area_nome'] ?? '—') ?><span class="sub"><?= e($s['local_nome'] ?? '') ?></span></td>
          <td class="ocultar-sm nowrap"><?= e(fmt_data($s['data_prevista'])) ?></td>
          <td class="ocultar-md"><?= badge(['interna' => 'Interna', 'link' => 'Link externo', 'publica' => 'Formulário público'][$s['origem']] ?? $s['origem'], 'neutro') ?><span class="sub">por <?= e($s['criador_nome'] ?? 'formulário externo') ?></span></td>
          <td>
            <?= $aguardandoForm && $s['status'] === 'pendente' ? badge('Aguardando preenchimento', 'info') : badge_status_solicitacao($s['status']) ?>
            <?php if ($s['status'] === 'recusada' && $s['motivo_recusa']): ?><span class="sub aviso-texto"><?= e($s['motivo_recusa']) ?></span><?php endif; ?>
            <?php if ($s['status_observacao'] && $s['status'] !== 'recusada'): ?><span class="sub"><?= e($s['status_observacao']) ?></span><?php endif; ?>
            <?php if ($s['autorizador_nome'] && $finalizada): ?><span class="sub">por <?= e($s['autorizador_nome']) ?> em <?= e(fmt_data_hora($s['autorizado_em'])) ?></span><?php endif; ?>
          </td>
          <td class="acoes">
            <?php if (!$finalizada && $s['pode_autorizar'] && !$aguardandoForm): ?>
              <?php if ($s['status'] === 'pendente'): ?>
                <form method="post" action="<?= e(url('/solicitacoes/' . $s['id'] . '/aprovar')) ?>">
                  <?= csrf_field() ?><input type="hidden" name="aba" value="<?= e($aba) ?>">
                  <button class="btn btn-sucesso btn-sm" type="submit"><?= icon('ok', 'icon icon-sm') ?> Aprovar</button>
                </form>
              <?php else: ?>
                <span class="sub">aguardando SSMA</span>
              <?php endif; ?>
              <details class="recusar">
                <summary class="btn btn-perigo btn-sm"><?= icon('x', 'icon icon-sm') ?> Recusar</summary>
                <form method="post" action="<?= e(url('/solicitacoes/' . $s['id'] . '/recusar')) ?>" class="form-pilha">
                  <?= csrf_field() ?><input type="hidden" name="aba" value="<?= e($aba) ?>">
                  <textarea name="motivo_recusa" rows="2" required minlength="5" maxlength="1000" placeholder="Motivo da recusa"></textarea>
                  <button class="btn btn-perigo btn-sm" type="submit">Confirmar recusa</button>
                </form>
              </details>
            <?php elseif (!$finalizada): ?>
              <span class="sub"><?= $aguardandoForm ? 'aguardando visitante' : 'aguardando autorizador' ?></span>
            <?php endif; ?>
            <?php if ($s['pode_link'] && $aguardandoForm): ?>
              <button type="button" class="btn btn-fantasma btn-sm" title="Copiar link externo" data-copiar-link="<?= e('/solicitacoes/' . $s['id'] . '/link') ?>"><?= icon('link', 'icon icon-sm') ?></button>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
<p class="dica"><?= icon('escudo', 'icon icon-sm') ?> Autorização: responsável da área, usuários com Segurança Patrimonial ou Admin/TI. Atividade de risco passa antes pelo SSMA. Porteiro não autoriza.</p>

<dialog id="dlg-nova" class="dialogo"<?= $old ? ' data-auto-abrir' : '' ?>>
  <form method="post" action="<?= e(url('/solicitacoes')) ?>">
    <?= csrf_field() ?>
    <h2>Nova solicitação de acesso</h2>
    <div class="grade-2 compacta">
      <label class="campo campo-largo"><span>Nome do visitante / terceiro <span class="obrigatorio">*</span></span><input name="visitante_nome" required maxlength="200" value="<?= e(old($old, 'visitante_nome')) ?>"></label>
      <label class="campo"><span>CPF / documento</span><input name="visitante_doc" maxlength="30" value="<?= e(old($old, 'visitante_doc')) ?>"></label>
      <label class="campo"><span>Empresa</span><input name="empresa" maxlength="150" value="<?= e(old($old, 'empresa')) ?>"></label>
      <label class="campo"><span>Telefone</span><input name="contato_telefone" maxlength="30" data-mascara="telefone" value="<?= e(old($old, 'contato_telefone')) ?>"></label>
      <label class="campo"><span>E-mail</span><input type="email" name="contato_email" maxlength="190" value="<?= e(old($old, 'contato_email')) ?>"></label>
      <label class="campo"><span>Tipo</span><select name="tipo"><option value="visitante">Visitante</option><option value="terceiro"<?= selected(old($old, 'tipo'), 'terceiro') ?>>Terceiro</option></select></label>
      <label class="campo"><span>Área responsável <span class="obrigatorio">*</span></span>
        <select name="area_id" required><option value="">Selecione</option>
          <?php foreach ($areas as $a): ?><option value="<?= e($a['id']) ?>"<?= selected(old($old, 'area_id'), $a['id']) ?>><?= e($a['nome']) ?></option><?php endforeach; ?>
        </select></label>
      <label class="campo"><span>Local</span>
        <select name="local_id"><option value="">Selecione</option>
          <?php foreach ($locais as $l): ?><option value="<?= e($l['id']) ?>"<?= selected(old($old, 'local_id'), $l['id']) ?>><?= e($l['nome']) ?></option><?php endforeach; ?>
        </select></label>
      <label class="campo"><span>Data prevista <span class="obrigatorio">*</span></span><input type="date" name="data_prevista" required min="<?= e(hoje()) ?>" value="<?= e(old($old, 'data_prevista', hoje())) ?>"></label>
      <label class="campo campo-largo"><span>Motivo da visita</span><textarea name="motivo" rows="2" maxlength="2000"><?= e(old($old, 'motivo')) ?></textarea></label>
      <label class="checkbox caixa-risco campo-largo"><input type="checkbox" name="atividade_risco" value="1"<?= checked(old($old, 'atividade_risco')) ?>>
        <span><strong>Atividade de risco</strong><br><small>Exigirá verificação do SSMA (ASO, identificação e autorização) antes da liberação.</small></span></label>
    </div>
    <div class="dialogo-acoes">
      <button type="button" class="btn btn-secundario" data-fechar>Cancelar</button>
      <button type="submit" class="btn btn-secundario" formaction="<?= e(url('/solicitacoes/link')) ?>" formnovalidate title="Usa apenas área, local e data"><?= icon('link', 'icon icon-sm') ?> Gerar link externo (<?= (int) $validadeLinkHoras ?>h)</button>
      <button type="submit" class="btn btn-ouro">Criar solicitação</button>
    </div>
  </form>
</dialog>
