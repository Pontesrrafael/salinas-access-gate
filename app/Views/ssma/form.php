<section class="cartao">
  <dl class="dados">
    <div><dt><?= e(t('Nome')) ?></dt><dd><strong><?= e($s['visitante_nome']) ?></strong></dd></div>
    <div><dt><?= e(t('Documento')) ?></dt><dd><?= e($s['visitante_doc'] ?? '—') ?></dd></div>
    <div><dt><?= e(t('Empresa')) ?></dt><dd><?= e($s['empresa'] ?? '—') ?></dd></div>
    <div><dt><?= e(t('Área')) ?></dt><dd><?= e($s['area_nome'] ?? '—') ?></dd></div>
    <div><dt><?= e(t('Visita prevista')) ?></dt><dd><?= e(fmt_data($s['data_prevista'])) ?></dd></div>
    <div><dt><?= e(t('Contato')) ?></dt><dd><?= e(trim(($s['contato_telefone'] ?? '') . ' ' . ($s['contato_email'] ?? '')) ?: '—') ?></dd></div>
    <?php if ($s['motivo']): ?><div class="largo"><dt><?= e(t('Atividade')) ?></dt><dd><?= nl2br(e($s['motivo'])) ?></dd></div><?php endif; ?>
  </dl>
</section>

<section class="cartao">
  <div class="cartao-cabecalho"><h2><?= e(t('Documentos cadastrados')) ?> <?= $pessoa ? '— ' . e($pessoa['nome']) : '' ?></h2>
    <?php if ($pessoa && can('documentos.gerir')): ?><a class="btn btn-secundario btn-sm" href="<?= e(url('/pessoas/' . $pessoa['id'])) ?>#documentos"><?= e(t('Gerenciar documentos')) ?></a><?php endif; ?></div>
  <?php if (!$docs): ?>
    <p class="dica"><?= icon('alerta', 'icon icon-sm') ?> <?= e(t('Nenhum documento cadastrado para este terceiro')) ?><?= $pessoa ? '' : ' (pessoa ainda não cadastrada ou CPF não informado)' ?><?= e(t('. Confira os documentos apresentados manualmente.')) ?></p>
  <?php else: ?>
    <ul class="lista-simples">
      <?php foreach ($docs as $d): $st = status_documento($d['validade']); ?>
        <li><span><?= e(label_t(TIPOS_DOC, $d['tipo'], $d['tipo'])) ?> <small class="mudo"><?= e($d['numero'] ?? '') ?></small></span>
          <?= badge('validade ' . fmt_data($d['validade']), $st === 'vencido' ? 'perigo' : ($st === 'proximo_vencimento' ? 'aviso' : 'sucesso')) ?></li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>

<section class="cartao">
  <form method="post" action="<?= e(url('/ssma/' . $s['id'])) ?>" class="form-pilha" data-tour="formulario">
    <?= csrf_field() ?>
    <div>
      <span class="rotulo-caps"><?= e(t('Checklist (todos obrigatórios para "Apto")')) ?></span>
      <?php foreach ($checklist as $k => $rot): ?>
        <label class="checkbox"><input type="checkbox" name="checklist[]" value="<?= e($k) ?>"> <?= e($rot) ?></label>
      <?php endforeach; ?>
    </div>
    <label class="campo"><span><?= e(t('Informações / justificativa')) ?></span>
      <textarea name="observacao" rows="3" maxlength="2000" placeholder="<?= e(t('Obrigatório quando Não Apto. Ex.: ASO vencido — apresentar exame atualizado.')) ?>"><?= e(old($old, 'observacao')) ?></textarea></label>
    <p class="dica"><?= e(t('A aptidão vale para a visita de')) ?> <?= e(fmt_data($s['data_prevista'])) ?><?= e(t('. O registro é imutável e fica na auditoria.')) ?></p>
    <div class="form-acoes">
      <a class="btn btn-secundario" href="<?= e(url('/ssma')) ?>"><?= e(t('Voltar')) ?></a>
      <button type="submit" name="decisao" value="nao_apto" class="btn btn-perigo" data-livre><?= icon('x', 'icon icon-sm') ?> <?= e(t('Marcar Não Apto')) ?></button>
      <button type="submit" name="decisao" value="apto" class="btn btn-sucesso" data-livre><?= icon('ok', 'icon icon-sm') ?> <?= e(t('Marcar Apto')) ?></button>
    </div>
  </form>
</section>
