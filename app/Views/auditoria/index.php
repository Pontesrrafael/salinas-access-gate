<section class="cartao">
  <form method="get" action="<?= e(url('/auditoria')) ?>" class="filtros" data-tour="filtros">
    <label class="campo"><span><?= e(t('Entidade')) ?></span><select name="entidade"><option value=""><?= e(t('Todas')) ?></option>
      <?php foreach ($entidades as $x): ?><option value="<?= e($x) ?>"<?= selected($f['entidade'], $x) ?>><?= e($x) ?></option><?php endforeach; ?></select></label>
    <label class="campo"><span><?= e(t('Ação')) ?></span><select name="acao"><option value=""><?= e(t('Todas')) ?></option>
      <?php foreach ($listaAcoes as $x): ?><option value="<?= e($x) ?>"<?= selected($f['acao'], $x) ?>><?= e($x) ?></option><?php endforeach; ?></select></label>
    <label class="campo"><span><?= e(t('Usuário')) ?></span><select name="usuario"><option value=""><?= e(t('Todos')) ?></option>
      <?php foreach ($usuarios as $u): ?><option value="<?= e($u['id']) ?>"<?= selected($f['usuario'], $u['id']) ?>><?= e($u['nome']) ?></option><?php endforeach; ?></select></label>
    <label class="campo"><span><?= e(t('Início')) ?></span><input type="date" name="inicio" value="<?= e($f['inicio']) ?>"></label>
    <label class="campo"><span><?= e(t('Fim')) ?></span><input type="date" name="fim" value="<?= e($f['fim']) ?>"></label>
    <button class="btn btn-secundario" type="submit"><?= icon('busca', 'icon icon-sm') ?> <?= e(t('Filtrar')) ?></button>
  </form>
</section>
<section class="cartao sem-padding">
  <?php if (!$rows): ?>
    <p class="mudo vazio"><?= e(t('Nenhum evento.')) ?></p>
  <?php else: ?>
  <div class="tabela-rolagem">
    <table class="tabela">
      <thead><tr><th><?= e(t('Data/Hora')) ?></th><th><?= e(t('Usuário')) ?></th><th><?= e(t('Entidade')) ?></th><th><?= e(t('Ação')) ?></th><th class="ocultar-md"><?= e(t('Detalhes')) ?></th><th class="ocultar-lg"><?= e(t('IP')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td class="nowrap"><?= e(fmt_data_hora($r['data_hora'])) ?></td>
          <td><?= e($r['usuario_nome'] ?? '—') ?></td>
          <td><?= e($r['entidade']) ?><span class="sub mono"><?= e($r['registro_id'] ? mb_substr($r['registro_id'], 0, 8) : '') ?></span></td>
          <td><?= badge($r['acao'], str_contains($r['acao'], 'FALHA') || str_contains($r['acao'], 'BLOQ') || $r['acao'] === 'DELETE' ? 'perigo' : 'neutro') ?></td>
          <td class="ocultar-md"><code class="detalhes"><?= e($r['detalhes'] ? mb_strimwidth($r['detalhes'], 0, 220, '…') : '') ?></code></td>
          <td class="ocultar-lg mono mudo"><?= e($r['ip'] ?? '') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="paginacao">
    <span><?= (int) $total ?> <?= e(t('evento(s) · página')) ?> <?= $pagina ?> <?= e(t('de')) ?> <?= $paginas ?></span>
    <?php if ($pagina > 1): ?><a class="btn btn-secundario btn-sm" href="<?= e(url('/auditoria', [...array_filter($f), 'pagina' => $pagina - 1])) ?>"><?= e(t('Anterior')) ?></a><?php endif; ?>
    <?php if ($pagina < $paginas): ?><a class="btn btn-secundario btn-sm" href="<?= e(url('/auditoria', [...array_filter($f), 'pagina' => $pagina + 1])) ?>"><?= e(t('Próxima')) ?></a><?php endif; ?>
  </div>
  <?php endif; ?>
</section>
