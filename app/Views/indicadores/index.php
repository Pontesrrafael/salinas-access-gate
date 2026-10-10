<?php
/**
 * @var array $f @var bool $modoTv @var ?int $atualizar @var array $locais @var string $geradoEm
 */
use App\Core\View;
use App\Services\Indicadores;

$fmtLocal = static fn (DateTimeImmutable $d): string => $d->format('Y-m-d\TH:i');
$qs = array_filter([
    'periodo' => $f['preset'],
    'desde'   => $f['preset'] === 'personalizado' ? ($_GET['desde'] ?? '') : null,
    'ate'     => $f['preset'] === 'personalizado' ? ($_GET['ate'] ?? '') : null,
    'local'   => $f['localId'],
    'atualizar' => $atualizar,
], static fn ($v) => $v !== null && $v !== '');
$urlTv = url('/indicadores', [...$qs, 'tv' => 1]);
$urlNormal = url('/indicadores', $qs);

if (!$modoTv) {
    slot('acoes', '<a class="btn btn-ouro" href="' . e($urlTv) . '">' . icon('tv') . ' Modo TV</a>');
}
$dadosConteudo = array_intersect_key(get_defined_vars(), array_flip([
    'f', 'periodoTxt', 'localNome', 'resumo', 'anterior', 'permanencia', 'presenca', 'serie', 'porHora',
    'empresas', 'solicitacoes', 'ssma', 'documentos', 'alertas', 'ultimos', 'geradoEm',
]));
?>
<?php if ($modoTv): ?>
<header class="tv-topo">
  <img src="<?= e(asset('img/logo-horizontal-branco.png')) ?>" alt="Salinas Gold Mineração" class="tv-logo" width="150" height="50">
  <div class="tv-titulo">
    <h1>Controle de Acesso · Indicadores</h1>
    <p><?= e($localNome ?? 'Todos os locais') ?></p>
  </div>
  <div class="tv-relogio" id="tv-relogio" aria-live="off"><?= e(date('H:i')) ?></div>
  <div class="tv-acoes">
    <button type="button" class="btn btn-secundario btn-sm" data-tela-cheia><?= icon('tela-cheia') ?> Tela cheia</button>
    <a class="btn btn-secundario btn-sm" href="<?= e($urlNormal) ?>"><?= icon('x') ?> Sair do Modo TV</a>
  </div>
</header>
<?php endif; ?>

<?php if ($modoTv): ?><details class="tv-filtros"><summary><?= icon('config', 'icon icon-sm') ?> Filtros e atualização</summary><?php endif; ?>
<form class="cartao filtros filtros-indicadores" method="get" action="<?= e(url('/indicadores')) ?>" id="form-indicadores">
  <?php if ($modoTv): ?><input type="hidden" name="tv" value="1"><?php endif; ?>
  <label class="campo">Período
    <select name="periodo" id="ind-periodo">
      <?php foreach (Indicadores::PRESETS as $k => $rot): ?>
        <option value="<?= e($k) ?>"<?= selected($k, $f['preset']) ?>><?= e($rot) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label class="campo">A partir de
    <input type="datetime-local" name="desde" id="ind-desde" value="<?= e($fmtLocal($f['ini'])) ?>" max="<?= e(date('Y-m-d\TH:i')) ?>">
  </label>
  <label class="campo">Até <small>(vazio = agora)</small>
    <input type="datetime-local" name="ate" id="ind-ate" value="<?= e($f['preset'] === 'personalizado' ? (string) ($_GET['ate'] ?? '') : '') ?>" max="<?= e(date('Y-m-d\TH:i')) ?>">
  </label>
  <label class="campo">Local
    <select name="local">
      <option value="">Todos os locais</option>
      <?php foreach ($locais as $l): ?>
        <option value="<?= e($l['id']) ?>"<?= selected($l['id'], $f['localId']) ?>><?= e($l['nome']) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label class="campo">Atualização automática
    <select name="atualizar" id="ind-atualizar">
      <?php foreach (Indicadores::ATUALIZACOES as $s => $rot): ?>
        <option value="<?= $s ?>"<?= selected($s, $atualizar ?? Indicadores::ATUALIZACAO_PADRAO) ?>><?= e($rot) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <div class="filtros-botoes">
    <button type="submit" class="btn btn-primario"><?= icon('busca') ?> Aplicar</button>
  </div>
  <p class="ind-status" id="ind-status" role="status"><?= icon('atualizar', 'icon icon-sm') ?> <span>Atualizado às <?= e(substr($geradoEm, -8)) ?></span></p>
</form>
<?php if ($modoTv): ?></details><?php endif; ?>

<div id="indicadores" data-indicadores data-atualizar="<?= $atualizar === null ? '' : (int) $atualizar ?>" data-padrao="<?= Indicadores::ATUALIZACAO_PADRAO ?>">
  <?= View::partial('indicadores/_conteudo', $dadosConteudo) ?>
</div>
