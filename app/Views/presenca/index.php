<?php slot('acoes', '<a class="btn btn-secundario" href="' . e(url('/presenca/imprimir', array_filter(['local_id' => $localId, 'q' => $q]))) . '" target="_blank" rel="noopener">' . icon('imprimir') . ' Lista de evacuação</a>'); ?>
<div data-autorefresh="60"></div>
<section class="kpis">
  <div class="kpi kpi-destaque"><span class="kpi-rotulo">Total no site</span><strong class="kpi-valor"><?= count($lista) ?></strong></div>
  <?php foreach ($porCategoria as $cat => $n): ?>
    <div class="kpi"><span class="kpi-rotulo"><?= e(CATEGORIAS[$cat] ?? $cat) ?>s</span><strong class="kpi-valor"><?= (int) $n ?></strong></div>
  <?php endforeach; ?>
</section>

<section class="cartao">
  <form method="get" action="<?= e(url('/presenca')) ?>" class="filtros">
    <label class="campo"><span>Local</span>
      <select name="local_id" data-autosubmit><option value="">Todos os locais</option>
        <?php foreach ($locais as $l): ?><option value="<?= e($l['id']) ?>"<?= selected($localId, $l['id']) ?>><?= e($l['nome']) ?></option><?php endforeach; ?>
      </select></label>
    <label class="campo"><span>Buscar</span><input type="search" name="q" value="<?= e($q) ?>" placeholder="Nome, empresa ou placa"></label>
    <button class="btn btn-secundario" type="submit"><?= icon('busca', 'icon icon-sm') ?> Filtrar</button>
  </form>
</section>

<?php $operar = can('portaria.operar'); ?>
<section class="cartao sem-padding">
  <?php if (!$lista): ?>
    <p class="mudo vazio">Ninguém registrado como presente.</p>
  <?php else: ?>
  <?php if ($operar): ?>
  <form method="post" action="<?= e(url('/portaria/saida-rapida')) ?>" id="form-saida-presenca" data-confirmar-lote>
    <?= csrf_field() ?><input type="hidden" name="voltar" value="/presenca">
    <div class="cartao-cabecalho pad">
      <label class="checkbox"><input type="checkbox" id="presentes-todos"> Selecionar todos</label>
      <button type="submit" class="btn btn-saida" id="btn-saida-lote" disabled data-lote><?= icon('saida', 'icon icon-sm') ?> Registrar saída dos selecionados (<span id="lote-qtd">0</span>)</button>
    </div>
  <?php endif; ?>
  <div class="tabela-rolagem">
    <table class="tabela">
      <thead><tr><?= $operar ? '<th class="col-sel"><span class="sr">Selecionar</span></th>' : '' ?><th>Pessoa</th><th class="ocultar-sm">Empresa</th><th class="ocultar-md">Local</th><th>Entrada</th><th class="ocultar-sm">Permanência</th><th class="ocultar-md">Destino</th><th class="ocultar-md">Placa</th><?= $operar ? '<th class="acoes"><span class="sr">Saída</span></th>' : '' ?></tr></thead>
      <tbody>
      <?php foreach ($lista as $r):
          $longa = $r['pessoa_categoria'] !== 'colaborador' && (int) $r['minutos'] >= $alertaHoras * 60; ?>
        <tr class="<?= $longa ? 'destaque-alerta' : '' ?>">
          <?php if ($operar): $chave = $r['pessoa_id'] . '|' . $r['local_id']; ?><td class="col-sel"><input type="checkbox" name="itens[]" value="<?= e($chave) ?>" aria-label="Selecionar <?= e($r['pessoa_nome']) ?>"></td><?php endif; ?>
          <td><a href="<?= e(url('/registros/' . $r['registro_id'])) ?>"><strong><?= e($r['pessoa_nome']) ?></strong></a><span class="sub"><?= e(CATEGORIAS[$r['pessoa_categoria']] ?? '') ?></span></td>
          <td class="ocultar-sm"><?= e($r['pessoa_empresa'] ?? '—') ?></td>
          <td class="ocultar-md"><?= e($r['local_nome']) ?></td>
          <td class="nowrap"><?= e(fmt_data_hora($r['entrada_em'])) ?></td>
          <td class="ocultar-sm nowrap"><?= e(sprintf('%dh%02d', intdiv((int) $r['minutos'], 60), (int) $r['minutos'] % 60)) ?><?= $longa ? ' ' . badge('acima de ' . $alertaHoras . 'h', 'aviso') : '' ?></td>
          <td class="ocultar-md mudo"><?= e($r['destino'] ?? '—') ?></td>
          <td class="ocultar-md mono"><?= e($r['veiculo_placa'] ?? '—') ?></td>
          <?php if ($operar): ?><td class="acoes"><button type="submit" class="btn btn-saida btn-sm" name="itens[]" value="<?= e($chave) ?>" data-saida-unica title="Registrar a saída de <?= e($r['pessoa_nome']) ?>"><?= icon('saida', 'icon icon-sm') ?> Saída</button></td><?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ($operar): ?></form><?php endif; ?>
  <?php endif; ?>
</section>
