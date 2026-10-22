<section class="cartao">
  <form id="infleet-filtro" action="<?= e(url('/api/infleet/percursos')) ?>" method="get" class="filtros" data-tour="filtros">
    <label class="campo"><span>Veículo</span><select name="veiculo" required><option value="">Selecione</option>
      <?php foreach ($opcoes as $v): ?><option value="<?= e($v['id']) ?>"<?= selected($veiculo['id'] ?? '', $v['id']) ?>><?= e($v['placa'] . ' · ' . ($v['marca_modelo'] ?? '')) ?></option><?php endforeach; ?>
    </select></label>
    <label class="campo"><span>A partir de</span><input name="inicio" type="datetime-local" value="<?= e($inicio) ?>" required></label>
    <label class="campo"><span>Até</span><input name="fim" type="datetime-local" value="<?= e($fim) ?>" required></label>
    <button class="btn btn-primario" type="submit" data-livre>Consultar percursos</button>
  </form>
  <p class="dica">Consulte até 7 dias por vez. Horários de Mato Grosso (UTC−4). Viagens e posições são consultadas na Infleet ao solicitar.</p>
  <p class="dica">Escolha o provedor do mapa abaixo. A visualização cartográfica requer internet. Lacunas entre pontos GPS não comprovam o caminho percorrido.</p>
  <p id="infleet-status" role="status" aria-live="polite">Selecione o veículo e o período para consultar.</p>
</section>
<section class="cartao">
  <h2>Trajeto GPS</h2>
  <label class="campo"><span>Mapa</span><select id="infleet-provedor">
    <option value="osm">OpenStreetMap</option>
    <option value="google"<?= $googleMapsKey !== '' ? ' selected' : ' disabled' ?>>Google Maps<?= $googleMapsKey === '' ? ' — configuração pendente' : '' ?></option>
  </select></label>
  <button type="button" id="infleet-todos" class="btn btn-secundario btn-sm" hidden>Mostrar todo o período</button>
  <div id="infleet-mapa" class="infleet-mapa" aria-label="Mapa do trajeto GPS" data-google-key="<?= e($googleMapsKey) ?>"></div>
  <div id="infleet-mapa-google" class="infleet-mapa" aria-label="Google Maps com trajeto GPS" hidden></div>
  <div class="form-acoes" id="infleet-links-google" hidden>
    <a id="infleet-google-inicio" class="btn btn-secundario btn-sm" target="_blank" rel="noopener noreferrer">Abrir ponto inicial no Google Maps</a>
    <a id="infleet-google-fim" class="btn btn-secundario btn-sm" target="_blank" rel="noopener noreferrer">Abrir ponto final no Google Maps</a>
  </div>
  <p class="dica" id="infleet-mapa-status">O mapa será exibido após a consulta.</p>
</section>
<section class="cartao sem-padding">
  <div class="tabela-rolagem"><table class="tabela">
    <caption>Histórico de viagens · Distância na unidade fornecida pela API</caption>
    <thead><tr><th>Início</th><th>Fim</th><th>Motorista da viagem</th><th>Distância informada</th><th>Trajeto</th></tr></thead>
    <tbody id="infleet-viagens"><tr><td colspan="5">Aguardando consulta.</td></tr></tbody>
  </table></div>
</section>
<a class="btn btn-secundario" href="<?= e(url('/infleet')) ?>">Voltar à frota</a>
