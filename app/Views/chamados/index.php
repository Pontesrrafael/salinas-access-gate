<?php
use App\Services\Chamados;
slot('acoes', '<a class="btn btn-ouro" href="' . e(url('/chamados/abrir')) . '">' . icon('mais') . e(t('Abrir chamado')) . '</a>');
$caminho = $fila ? '/chamados/fila' : '/chamados/meus';
?>
<div class="chamados">
<?php if ($fila && !empty($satisfacaoAreas)): ?>
<section class="cartao">
  <h2><?= e(t('Satisfação por área')) ?></h2>
  <p class="dica"><?= e(t('Histórico de avaliações da área, independente dos demais filtros da fila.')) ?></p>
  <div class="grade-2">
    <?php foreach ($satisfacaoAreas as $s): ?>
      <div class="kpi"><span class="kpi-rotulo"><?= e($s['nome']) ?></span><strong class="kpi-valor"><?= $s['media'] === null ? '—' : e(number_format((float) $s['media'], 1, \App\Core\I18n::current() === 'en' ? '.' : ',', '')) ?> / 5</strong><small><?= e(t('{quantidade} avaliações', ['quantidade' => $s['avaliacoes']])) ?></small></div>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>
<section class="cartao">
  <form method="get" action="<?= e(url($caminho)) ?>" class="filtros">
    <label class="campo"><span><?= e(t('Área de atendimento')) ?></span><select name="area_id"><option value=""><?= e(t('Todas')) ?></option><?php foreach ($areas as $area): ?><option value="<?= e($area['id']) ?>"<?= selected($filtros['area_id'], $area['id']) ?>><?= e($area['nome']) ?></option><?php endforeach; ?></select></label>
    <label class="campo"><span><?= e(t('Status')) ?></span><select name="status"><option value=""><?= e(t('Todos')) ?></option><?php foreach (Chamados::STATUS as $valor => $rotulo): ?><option value="<?= e($valor) ?>"<?= selected($filtros['status'], $valor) ?>><?= e(t($rotulo)) ?></option><?php endforeach; ?></select></label>
    <label class="campo"><span><?= e(t('Categoria do serviço')) ?></span><select name="categoria_id"><option value=""><?= e(t('Todas')) ?></option><?php foreach ($categorias as $cat): ?><option value="<?= e($cat['id']) ?>"<?= selected($filtros['categoria_id'], $cat['id']) ?>><?= e($cat['caminho']) ?></option><?php endforeach; ?></select><small><?= e(t('Filtre uma área para carregar categorias e atendentes.')) ?></small></label>
    <label class="campo"><span><?= e(t('Prioridade')) ?></span><select name="prioridade"><option value=""><?= e(t('Todas')) ?></option><?php foreach (Chamados::PRIORIDADES as $valor => $rotulo): ?><option value="<?= $valor ?>"<?= selected($filtros['prioridade'], $valor) ?>><?= e($valor . ' · ' . t($rotulo)) ?></option><?php endforeach; ?></select></label>
    <?php if ($fila): ?><label class="campo"><span><?= e(t('Atendente')) ?></span><select name="atendente_id"><option value=""><?= e(t('Todos')) ?></option><?php foreach ($membros as $m): ?><option value="<?= e($m['id']) ?>"<?= selected($filtros['atendente_id'], $m['id']) ?>><?= e($m['nome']) ?></option><?php endforeach; ?></select></label><?php endif; ?>
    <label class="campo"><span><?= e(t('Prazo de solução')) ?></span><select name="prazo"><option value=""><?= e(t('Todos')) ?></option><?php foreach (Chamados::PRAZOS as $valor => $rotulo): ?><option value="<?= e($valor) ?>"<?= selected($filtros['prazo'], $valor) ?>><?= e(t($rotulo)) ?></option><?php endforeach; ?></select></label>
    <button type="submit" class="btn btn-secundario"><?= icon('busca') ?> <?= e(t('Filtrar')) ?></button>
    <a class="btn btn-fantasma" href="<?= e(url($caminho)) ?>"><?= e(t('Limpar')) ?></a>
  </form>
</section>
<section class="cartao sem-padding">
  <div class="cartao-cabecalho pad"><h2><?= icon('solicit') ?> <?= e($fila ? t('Fila de atendimento') : t('Meus chamados')) ?></h2><?= badge(t('{quantidade} chamados', ['quantidade' => $total])) ?></div>
  <?php if (!$lista): ?><p class="mudo vazio"><?= e(t('Nenhum chamado encontrado.')) ?></p><?php else: ?>
  <div class="tabela-rolagem"><table class="tabela chamado-tabela">
    <thead><tr><th><?= e(t('Chamado')) ?></th><th><?= e(t('Área')) ?></th><th><?= e(t('Status')) ?></th><th><?= e(t('Prioridade')) ?></th><th><?= e(t('Atendente')) ?></th><th><?= e(t('Prazo de solução')) ?></th></tr></thead>
    <tbody><?php foreach ($lista as $c): $situacao = Chamados::situacaoSolucao($c, new DateTimeImmutable()); ?>
      <tr>
        <td data-rotulo="<?= e(t('Chamado')) ?>"><a href="<?= e(url('/chamados/' . $c['id'])) ?>"><strong>#<?= e($c['numero']) ?> · <?= e($c['titulo']) ?></strong></a><span class="sub"><?= e(fmt_data_hora($c['created_at'])) ?></span></td>
        <td data-rotulo="<?= e(t('Área')) ?>"><?= e($c['area_nome']) ?><span class="sub"><?= e($c['categoria_nome']) ?></span></td>
        <td data-rotulo="<?= e(t('Status')) ?>"><?= badge(t(Chamados::STATUS[$c['status']]), Chamados::corStatus($c['status'])) ?></td>
        <td data-rotulo="<?= e(t('Prioridade')) ?>"><?= badge($c['prioridade'] . ' · ' . t(Chamados::PRIORIDADES[$c['prioridade']]), Chamados::corPrioridade((int) $c['prioridade'])) ?></td>
        <td data-rotulo="<?= e(t('Atendente')) ?>"><?= e($c['atendente_nome'] ?? t('Sem atendente')) ?></td>
        <td data-rotulo="<?= e(t('Prazo de solução')) ?>">
          <?php if ($c['prazo_solucao']): ?><?= badge(t(Chamados::PRAZOS[$situacao]), ['no_prazo' => 'sucesso', 'vencendo' => 'aviso', 'vencido' => 'perigo'][$situacao]) ?><span class="sub"><?= e(fmt_data_hora(Chamados::prazoSolucao($c, new DateTimeImmutable())?->format('Y-m-d H:i:s'))) ?></span><?php else: ?><span class="sub"><?= e(t('Sem SLA definido')) ?></span><?php endif; ?>
          <?php if ($c['status'] === 'pendente'): ?><?= badge(t('Relógio pausado'), 'neutro') ?><?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?></tbody>
  </table></div>
  <?php endif; ?>
  <nav class="paginacao" aria-label="<?= e(t('Paginação')) ?>">
    <?php if ($pagina > 1): ?><a class="btn btn-secundario btn-sm" href="<?= e(url($caminho, array_merge($filtros, ['pagina' => $pagina - 1]))) ?>"><?= e(t('Anterior')) ?></a><?php endif; ?>
    <span><?= e(t('Página {pagina}', ['pagina' => $pagina])) ?></span>
    <?php if ($pagina * $limite < $total): ?><a class="btn btn-secundario btn-sm" href="<?= e(url($caminho, array_merge($filtros, ['pagina' => $pagina + 1]))) ?>"><?= e(t('Próxima')) ?></a><?php endif; ?>
  </nav>
</section>
</div>
