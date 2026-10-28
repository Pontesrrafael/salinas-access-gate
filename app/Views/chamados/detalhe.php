<?php
use App\Services\Chamados;
$agora = new DateTimeImmutable();
$prazoSolucao = Chamados::prazoSolucao($c, $agora);
$situacao = Chamados::situacaoSolucao($c, $agora);
$solicitante = $c['solicitante_id'] === $u['id'];
$satisfacao = $satisfacao ?? null;
$anexosEvento = [];
foreach ($anexos as $anexo) { $anexosEvento[$anexo['acompanhamento_id'] ?? ''][] = $anexo; }
$linkAnexos = static function (array $items): string {
    $html = '<ul class="chamado-anexos">';
    foreach ($items as $a) { $html .= '<li><a href="' . e(url('/chamados/anexos/' . $a['id'])) . '">' . icon('download') . e($a['nome_arquivo']) . '</a><span class="sub">' . e((string) ceil($a['tamanho_bytes'] / 1024)) . ' KB</span></li>'; }
    return $html . '</ul>';
};
slot('acoes', '<a class="btn btn-secundario" href="' . e(url($atende ? '/chamados/fila' : '/chamados/meus')) . '">' . e(t('Voltar')) . '</a>');
?>
<div class="chamados">
<section class="cartao">
  <div class="cartao-cabecalho"><h2><?= e($c['titulo']) ?></h2><div class="botoes"><?= badge(t(Chamados::STATUS[$c['status']]), Chamados::corStatus($c['status'])) ?><?= badge(t(Chamados::PRIORIDADES[$c['prioridade']]), Chamados::corPrioridade((int) $c['prioridade'])) ?></div></div>
  <div class="grade-2">
    <p><strong><?= e(t('Área')) ?>:</strong> <?= e($c['area_nome']) ?><span class="sub"><?= e($c['categoria_nome']) ?></span></p>
    <p><strong><?= e(t('Solicitante')) ?>:</strong> <?= e($c['solicitante_nome']) ?><span class="sub"><?= e(fmt_data_hora($c['created_at'])) ?></span></p>
    <p><strong><?= e(t('Atendente')) ?>:</strong> <?= e($c['atendente_nome'] ?? t('Sem atendente')) ?></p>
    <p><strong><?= e(t('Urgência')) ?>:</strong> <?= e(t(Chamados::URGENCIAS[$c['urgencia']])) ?><span class="sub"><?= e(t(Chamados::IMPACTOS[$c['impacto']])) ?></span></p>
    <?php if ($c['beneficiario_nome']): ?><p><strong><?= e(t('Beneficiário')) ?>:</strong> <?= e($c['beneficiario_nome']) ?></p><?php endif; ?>
  </div>
  <?php if ($c['recorrente']): ?><?= badge(t('Problema recorrente'), 'aviso') ?><?php endif; ?>
  <p class="chamado-texto"><?= e($c['descricao'] ?? '') ?></p>
  <?php if ($respostas): ?><dl class="chamado-respostas"><?php foreach ($respostas as $r): ?><dt><?= e($r['rotulo']) ?></dt><dd class="chamado-texto"><?= e($r['valor']) ?></dd><?php endforeach; ?></dl><?php endif; ?>
  <?php if (!empty($anexosEvento[''])): ?><h3><?= e(t('Anexos')) ?></h3><?= $linkAnexos($anexosEvento['']) ?><?php endif; ?>
</section>
<section class="cartao">
  <div class="cartao-cabecalho"><h2><?= icon('relogio') ?> <?= e(t('Prazos de atendimento')) ?></h2><?php if ($c['status'] === 'pendente'): ?><?= badge(t('Relógio pausado'), 'aviso') ?><?php endif; ?></div>
  <div class="grade-2">
    <p><strong><?= e(t('Prazo de resposta')) ?></strong><span class="sub"><?= e($c['prazo_resposta'] ? fmt_data_hora($c['prazo_resposta']) : t('Sem SLA definido')) ?></span>
    <?php if ($c['prazo_resposta']): $respostaSituacao = Chamados::situacaoPrazo(new DateTimeImmutable($c['created_at']), new DateTimeImmutable($c['prazo_resposta']), new DateTimeImmutable($c['respondido_em'] ?? $agora->format('Y-m-d H:i:s')), Chamados::calendarioChamado($c)); ?><?= badge(t(Chamados::PRAZOS[$respostaSituacao]), ['no_prazo' => 'sucesso', 'vencendo' => 'aviso', 'vencido' => 'perigo'][$respostaSituacao]) ?><?php endif; ?>
    <?php if ($c['respondido_em']): ?><span class="sub"><?= e(t('Respondido em')) ?> <?= e(fmt_data_hora($c['respondido_em'])) ?></span><?php endif; ?></p>
    <p><strong><?= e(t('Prazo de solução')) ?></strong><span class="sub"><?= e($prazoSolucao ? fmt_data_hora($prazoSolucao->format('Y-m-d H:i:s')) : t('Sem SLA definido')) ?></span><?php if ($prazoSolucao): ?><?= badge(t(Chamados::PRAZOS[$situacao]), ['no_prazo' => 'sucesso', 'vencendo' => 'aviso', 'vencido' => 'perigo'][$situacao]) ?><?php endif; ?></p>
  </div>
  <p class="dica"><?= e(Chamados::calendarioChamado($c) === null ? t('SLA em horas corridas (24x7).') : t('SLA em horário comercial, descontando dias sem expediente e feriados.')) ?> <?= e(t('O prazo de solução pausa enquanto o chamado estiver pendente.')) ?></p>
</section>
<?php if ($c['status'] === 'fechado'): ?>
<section class="cartao">
  <h2><?= e(t('Satisfação com o atendimento')) ?></h2>
  <?php if (!empty($c['fechado_automaticamente'])): ?><p class="dica"><?= e(t('Chamado fechado automaticamente após o prazo de aprovação.')) ?></p><?php endif; ?>
  <?php if ($satisfacao): ?>
    <p><strong><?= e(t('{nota} de 5 estrelas', ['nota' => $satisfacao['nota']])) ?></strong><span class="sub"><?= e(fmt_data_hora($satisfacao['created_at'])) ?></span></p>
    <?php if ($satisfacao['comentario']): ?><p class="chamado-texto"><?= e($satisfacao['comentario']) ?></p><?php endif; ?>
  <?php elseif (Chamados::podeAvaliar($c, $u['id'], false)): ?>
    <form method="post" action="<?= e(url('/chamados/' . $c['id'] . '/status')) ?>" class="form-pilha">
      <?= csrf_field() ?><input type="hidden" name="acao" value="avaliar">
      <fieldset><legend><?= e(t('Como foi o atendimento?')) ?></legend>
        <?php for ($nota = 1; $nota <= 5; $nota++): ?>
          <label class="checkbox"><input type="radio" name="nota" value="<?= $nota ?>" required> <span aria-hidden="true"><?= str_repeat('★', $nota) ?></span><span><?= e(t('{nota} de 5 estrelas', ['nota' => $nota])) ?></span></label>
        <?php endfor; ?>
      </fieldset>
      <label class="campo"><span><?= e(t('Comentário opcional')) ?></span><textarea name="comentario" rows="3" maxlength="2000"></textarea></label>
      <p class="dica"><?= e(t('A avaliação é única e fica visível para a equipe da área.')) ?></p>
      <div class="form-acoes"><button type="submit" class="btn btn-primario"><?= e(t('Enviar avaliação')) ?></button></div>
    </form>
  <?php else: ?><p class="mudo"><?= e(t('Sem avaliação registrada.')) ?></p><?php endif; ?>
</section>
<?php endif; ?>
<?php if ($atende && !in_array($c['status'], ['solucionado', 'fechado'], true)): ?>
<section class="cartao">
  <h2><?= e(t('Atribuir atendimento')) ?></h2>
  <form method="post" action="<?= e(url('/chamados/' . $c['id'] . '/atribuir')) ?>" class="filtros">
    <?= csrf_field() ?><label class="campo"><span><?= e(t('Membro da área')) ?></span><select name="atendente_id"><option value=""><?= e(t('Selecione')) ?></option><?php foreach ($membros as $m): ?><option value="<?= e($m['id']) ?>"<?= selected($c['atendente_id'], $m['id']) ?>><?= e($m['nome']) ?></option><?php endforeach; ?></select></label>
    <button class="btn btn-primario" type="submit"><?= e(t('Atribuir')) ?></button>
    <?php if (in_array($u['id'], array_column($membros, 'id'), true)): ?><button class="btn btn-secundario" type="submit" name="a_mim" value="1"><?= e(t('Atribuir a mim')) ?></button><?php endif; ?>
  </form>
</section>
<?php endif; ?>
<?php if ($c['status'] === 'solucionado' && $solicitante): ?>
<section class="cartao">
  <h2><?= e(t('Aprovar solução ou reabrir')) ?></h2>
  <form method="post" action="<?= e(url('/chamados/' . $c['id'] . '/status')) ?>" class="form-pilha">
    <?= csrf_field() ?><label class="campo"><span><?= e(t('Motivo da reabertura')) ?></span><textarea name="texto" rows="3" maxlength="20000"><?= e(old($old, 'texto')) ?></textarea><small><?= e(t('Obrigatório para reabrir.')) ?></small></label>
    <div class="form-acoes"><button class="btn btn-secundario" type="submit" name="status" value="atribuido"><?= e(t('Reabrir')) ?></button><button class="btn btn-ouro" type="submit" name="status" value="fechado"><?= icon('ok') ?> <?= e(t('Aprovar e fechar')) ?></button></div>
  </form>
</section>
<?php endif; ?>
<?php if ($atende && $c['status'] !== 'fechado'): $transicoes = array_values(array_filter(Chamados::transicoes($c['status']), static fn ($s) => $s !== 'fechado')); ?>
<section class="cartao">
  <h2><?= e(t('Atualizar atendimento')) ?></h2>
  <form method="post" action="<?= e(url('/chamados/' . $c['id'] . '/status')) ?>" class="form-pilha">
    <?= csrf_field() ?><label class="campo"><span><?= e(t('Novo status')) ?></span><select name="status" required><?php foreach ($transicoes as $s): ?><option value="<?= e($s) ?>"><?= e(t(Chamados::STATUS[$s])) ?></option><?php endforeach; ?></select></label>
    <label class="campo"><span><?= e(t('Solução ou justificativa')) ?></span><textarea name="texto" rows="3" maxlength="20000"><?= e(old($old, 'texto')) ?></textarea><small><?= e(t('Obrigatório para solucionar ou reabrir. Este texto é público.')) ?></small></label>
    <div class="form-acoes"><button class="btn btn-primario" type="submit"><?= e(t('Atualizar status')) ?></button></div>
  </form>
</section>
<?php endif; ?>
<section class="cartao">
  <div class="cartao-cabecalho"><h2><?= icon('relogio') ?> <?= e(t('Linha do tempo')) ?></h2></div>
  <ol class="chamado-timeline">
    <li><strong><?= e(t('Chamado aberto')) ?></strong><span class="sub"><?= e(fmt_data_hora($c['created_at'])) ?> · <?= e($c['solicitante_nome']) ?></span></li>
    <?php foreach ($eventos as $ev): ?>
      <li<?= $ev['tipo'] === 'interno' ? ' class="chamado-nota-interna"' : '' ?>>
        <?= badge(t(Chamados::TIPOS[$ev['tipo']]), $ev['tipo'] === 'interno' ? 'aviso' : 'neutro') ?>
        <span class="sub"><?= e($ev['usuario_nome'] ?? t('Sistema')) ?> · <?= e(fmt_data_hora($ev['created_at'])) ?></span>
        <p class="chamado-texto"><?= e($ev['tipo'] === 'status' ? label_t(Chamados::STATUS, $ev['texto'], $ev['texto']) : $ev['texto']) ?></p>
        <?php if (!empty($anexosEvento[$ev['id']])): ?><?= $linkAnexos($anexosEvento[$ev['id']]) ?><?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ol>
  <?php if ($c['status'] !== 'fechado'): ?>
  <form method="post" action="<?= e(url('/chamados/' . $c['id'] . '/acompanhar')) ?>" enctype="multipart/form-data" class="form-pilha">
    <?= csrf_field() ?><label class="campo"><span><?= e(t('Visibilidade')) ?></span><select name="tipo"><option value="comentario"><?= e(t('Comentário público')) ?></option><?php if ($atende): ?><option value="interno"><?= e(t('Nota interna')) ?></option><?php endif; ?></select></label>
    <label class="campo"><span><?= e(t('Acompanhamento')) ?></span><textarea name="texto" rows="4" required maxlength="20000"><?= e(old($old, 'texto')) ?></textarea></label>
    <label class="campo"><span><?= e(t('Anexos')) ?></span><input name="anexos[]" type="file" multiple accept="application/pdf,image/jpeg,image/png,image/webp"><small><?= e(t('PDF, JPG, PNG ou WEBP, até 4 MB por arquivo.')) ?></small></label>
    <div class="form-acoes"><button class="btn btn-primario" type="submit"><?= e(t('Adicionar acompanhamento')) ?></button></div>
  </form>
  <?php endif; ?>
</section>
</div>
