<section class="cartao">
  <form id="infleet-filtro" action="<?= e(url('/api/infleet/percursos')) ?>" method="get" class="filtros" data-tour="filtros">
    <label class="campo"><span><?= e(t('Veículo')) ?></span><select name="veiculo" required><option value=""><?= e(t('Selecione')) ?></option>
      <?php foreach ($opcoes as $v): ?><option value="<?= e($v['id']) ?>"<?= selected($veiculo['id'] ?? '', $v['id']) ?>><?= e($v['placa'] . ' · ' . ($v['marca_modelo'] ?? '')) ?></option><?php endforeach; ?>
    </select></label>
    <label class="campo"><span><?= e(t('A partir de')) ?></span><input name="inicio" type="datetime-local" value="<?= e($inicio) ?>" required></label>
    <label class="campo"><span><?= e(t('Até')) ?></span><input name="fim" type="datetime-local" value="<?= e($fim) ?>" required></label>
    <button class="btn btn-primario" type="submit" data-livre><?= e(t('Consultar percursos')) ?></button>
  </form>
  <p class="dica"><?= e(t('Consulte até 7 dias por vez. Horários de Mato Grosso (UTC−4). Viagens e posições são consultadas na Infleet ao solicitar.')) ?></p>
  <p class="dica"><?= e(t('Escolha o provedor do mapa abaixo. A visualização cartográfica requer internet. Lacunas entre pontos GPS não comprovam o caminho percorrido.')) ?></p>
  <p id="infleet-status" role="status" aria-live="polite"><?= e(t('Selecione o veículo e o período para consultar.')) ?></p>
</section>
<section class="cartao">
  <h2><?= e(t('Trajeto GPS')) ?></h2>
  <label class="campo"><span><?= e(t('Mapa')) ?></span><select id="infleet-provedor">
    <option value="osm"><?= e(t('OpenStreetMap')) ?></option>
    <option value="google"<?= $googleMapsKey !== '' ? ' selected' : ' disabled' ?>><?= e(t('Google Maps')) ?><?= $googleMapsKey === '' ? ' — configuração pendente' : '' ?></option>
  </select></label>
  <button type="button" id="infleet-todos" class="btn btn-secundario btn-sm" hidden><?= e(t('Mostrar todo o período')) ?></button>
  <div id="infleet-mapa" class="infleet-mapa" aria-label="<?= e(t('Mapa do trajeto GPS')) ?>" data-google-key="<?= e($googleMapsKey) ?>"></div>
  <div id="infleet-mapa-google" class="infleet-mapa" aria-label="<?= e(t('Google Maps com trajeto GPS')) ?>" hidden></div>
  <div class="form-acoes" id="infleet-links-google" hidden>
    <a id="infleet-google-inicio" class="btn btn-secundario btn-sm" target="_blank" rel="noopener noreferrer"><?= e(t('Abrir ponto inicial no Google Maps')) ?></a>
    <a id="infleet-google-fim" class="btn btn-secundario btn-sm" target="_blank" rel="noopener noreferrer"><?= e(t('Abrir ponto final no Google Maps')) ?></a>
  </div>
  <p class="dica" id="infleet-mapa-status"><?= e(t('O mapa será exibido após a consulta.')) ?></p>
</section>
<section class="cartao sem-padding">
  <div class="tabela-rolagem"><table class="tabela">
    <caption><?= e(t('Histórico de viagens · Distância na unidade fornecida pela API')) ?></caption>
    <thead><tr><th><?= e(t('Início')) ?></th><th><?= e(t('Fim')) ?></th><th><?= e(t('Motorista da viagem')) ?></th><th><?= e(t('Distância informada')) ?></th><th><?= e(t('Trajeto')) ?></th></tr></thead>
    <tbody id="infleet-viagens"><tr><td colspan="5"><?= e(t('Aguardando consulta.')) ?></td></tr></tbody>
  </table></div>
</section>
<a class="btn btn-secundario" href="<?= e(url('/infleet')) ?>"><?= e(t('Voltar à frota')) ?></a>
