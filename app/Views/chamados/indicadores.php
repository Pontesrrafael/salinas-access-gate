<?php
/**
 * @var array{inicio: string, fim: string, area_id: string} $f
 * @var list<array<string, mixed>> $areas
 * @var array{geral: array<string, mixed>, areas: array<string, array<string, mixed>>} $resumo
 * @var bool $truncado
 */
use App\Services\Grafico;

$g = $resumo['geral'];
$ou = static fn($v, string $sufixo = ''): string => $v === null ? '—' : e(str_replace('.', ',', (string) $v)) . $sufixo;
slot('acoes', '<a class="btn btn-ouro" href="' . e(url('/chamados/indicadores/csv', array_filter($f))) . '">' . icon('download') . ' ' . e(t('Exportar CSV')) . '</a>');
?>
<section class="cartao">
  <form method="get" action="<?= e(url('/chamados/indicadores')) ?>" class="filtros">
    <label class="campo"><span><?= e(t('Início')) ?></span><input type="date" name="inicio" value="<?= e($f['inicio']) ?>"></label>
    <label class="campo"><span><?= e(t('Fim')) ?></span><input type="date" name="fim" value="<?= e($f['fim']) ?>"></label>
    <label class="campo"><span><?= e(t('Área de atendimento')) ?></span><select name="area_id"><option value=""><?= e(t('Todas')) ?></option>
      <?php foreach ($areas as $a): ?><option value="<?= e($a['id']) ?>"<?= selected($f['area_id'], $a['id']) ?>><?= e($a['nome']) ?></option><?php endforeach; ?></select></label>
    <button class="btn btn-secundario" type="submit"><?= icon('busca', 'icon icon-sm') ?> <?= e(t('Filtrar')) ?></button>
  </form>
</section>

<?php if ($truncado): ?>
  <div class="alerta alerta-aviso" role="status"><?= e(t('O período tem mais chamados do que a tela analisa de uma vez. Reduza o período ou use o CSV para o conjunto completo.')) ?></div>
<?php endif; ?>

<section class="kpis" aria-label="<?= e(t('Indicadores principais')) ?>">
  <div class="kpi"><span class="kpi-rotulo"><?= e(t('Abertos no período')) ?></span><strong class="kpi-valor"><?= (int) $g['abertos'] ?></strong></div>
  <div class="kpi"><span class="kpi-rotulo"><?= e(t('Ainda em aberto')) ?></span><strong class="kpi-valor"><?= (int) $g['backlog'] ?></strong></div>
  <div class="kpi"><span class="kpi-rotulo"><?= e(t('Em aberto com SLA vencido')) ?></span><strong class="kpi-valor"><?= (int) $g['vencidos_abertos'] ?></strong></div>
  <div class="kpi"><span class="kpi-rotulo"><?= e(t('Solucionados no prazo')) ?></span><strong class="kpi-valor"><?= $ou($g['pct_no_prazo'], '%') ?></strong></div>
  <div class="kpi"><span class="kpi-rotulo"><?= e(t('Tempo médio de solução')) ?></span><strong class="kpi-valor"><?= $ou($g['horas_solucao'], ' h') ?></strong></div>
  <div class="kpi"><span class="kpi-rotulo"><?= e(t('Satisfação média')) ?></span><strong class="kpi-valor"><?= $ou($g['nota_media'], ' / 5') ?></strong></div>
</section>

<section class="cartao">
  <h2><?= e(t('Chamados abertos por área')) ?></h2>
  <?= Grafico::barras(t('Chamados abertos por área'), array_map(static fn(array $a): array => [
      'rotulo' => $a['nome'], 'valor' => (int) $a['abertos'], 'extra' => sprintf(t('%d em aberto'), (int) $a['backlog']),
  ], array_values($resumo['areas'])), 'unica', t('Sem dados no período.')) ?>
</section>

<section class="cartao sem-padding">
  <div class="tabela-rolagem">
    <table class="tabela">
      <thead><tr>
        <th><?= e(t('Área')) ?></th><th><?= e(t('Abertos no período')) ?></th><th><?= e(t('Ainda em aberto')) ?></th><th><?= e(t('Sem atendente')) ?></th>
        <th><?= e(t('Em aberto com SLA vencido')) ?></th><th><?= e(t('Solucionados no prazo')) ?></th><th><?= e(t('Tempo médio de solução')) ?></th><th><?= e(t('Satisfação média')) ?></th>
      </tr></thead>
      <tbody>
      <?php if (!$resumo['areas']): ?><tr><td colspan="8" class="vazio"><?= e(t('Nenhum chamado no período.')) ?></td></tr><?php endif; ?>
      <?php foreach ($resumo['areas'] as $a): ?>
        <tr>
          <td><?= e($a['nome']) ?></td><td><?= (int) $a['abertos'] ?></td><td><?= (int) $a['backlog'] ?></td><td><?= (int) $a['sem_atendente'] ?></td>
          <td><?= (int) $a['vencidos_abertos'] ?></td><td><?= $ou($a['pct_no_prazo'], '%') ?></td><td><?= $ou($a['horas_solucao'], ' h') ?></td>
          <td><?= $ou($a['nota_media'], ' / 5') ?><?= $a['notas'] ? ' <small class="mudo">(' . (int) $a['notas'] . ')</small>' : '' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<p class="dica"><?= e(t('O tempo médio de solução desconta o período em que o chamado ficou pendente. "No prazo" usa a mesma régua de SLA da fila de atendimento.')) ?></p>
