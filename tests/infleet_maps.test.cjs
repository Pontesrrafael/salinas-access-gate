const test = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const path = require('node:path');

const source = fs.readFileSync(path.join(__dirname, '../public/assets/js/infleet.js'), 'utf8');
const flush = async () => { for (let i = 0; i < 4; i++) await new Promise(resolve => setImmediate(resolve)); };
function fixture({ google = true, fail = false } = {}) {
  const nodes = {}, scripts = [], overlays = [], osmPaths = [], events = {};
  function element() {
    return {
      hidden: false, dataset: {}, textContent: '', children: [], listeners: {},
      addEventListener(name, fn) { this.listeners[name] = fn; },
      replaceChildren() { this.children = []; }, append(value) { this.children.push(value); },
      insertRow() { const row = element(); row.insertCell = () => { const cell = element(); row.children.push(cell); return cell; }; this.children.push(row); return row; }
    };
  }
  for (const id of ['infleet-filtro', 'infleet-status', 'infleet-mapa-status', 'infleet-viagens', 'infleet-todos', 'infleet-provedor', 'infleet-mapa-google', 'infleet-mapa', 'infleet-links-google', 'infleet-google-inicio', 'infleet-google-fim']) nodes[id] = element();
  const form = nodes['infleet-filtro']; form.action = 'https://app.teste.invalid/api/infleet/percursos';
  const submit = element(); form.querySelector = () => submit;
  nodes['infleet-mapa'].dataset.googleKey = google ? 'CHAVE_FICTICIA' : '';
  nodes['infleet-provedor'].value = google ? 'google' : 'osm';
  class Overlay {
    constructor(options) { this.options = options; overlays.push(this); }
    setMap(map) { this.map = map; }
    addListener() {}
    getCenter() { return this.options.center; }
  }
  const map = { fitBounds() {}, getZoom() { return 10; }, setZoom() {} };
  const googleApi = { Map: class { constructor() { return map; } }, Polyline: Overlay, Circle: Overlay,
    LatLngBounds: class { extend() {} }, InfoWindow: class { open() {} },
    RenderingType: { RASTER: 'RASTER' }, event: { addListenerOnce(target, name, fn) { fn(); } } };
  const layer = { addTo() { return this; }, clearLayers() {}, getBounds() { return {}; }, getLayers() { return [1]; } };
  const leafletMap = { setView() { return this; }, on() {}, invalidateSize() {}, fitBounds() {} };
  const L = { map: () => leafletMap, tileLayer: () => ({ addTo() { return this; }, on() {} }), featureGroup: () => layer,
    polyline(points) { osmPaths.push(points); return { addTo() {} }; }, circleMarker: () => ({ addTo() { return this; }, bindPopup() { return this; } }) };
  let response;
  const window = { google: { maps: googleApi }, L };
  const document = {
    currentScript: { nonce: 'NONCE_TESTE' }, getElementById: id => nodes[id], createElement: () => element(),
    head: { append(script) { scripts.push(script); queueMicrotask(() => fail ? script.onerror() : window.sagGoogleMapsReady()); } }
  };
  const context = vm.createContext({ window, document, L, URL, URLSearchParams, Date, Set, Object, Number, Error, Promise, setTimeout, clearTimeout,
    FormData: class { *[Symbol.iterator]() { yield ['veiculo', 'v1']; yield ['inicio', '2026-10-14T08:00']; yield ['fim', '2026-10-14T09:00']; } },
    fetch: async () => ({ ok: true, json: async () => response }) });
  vm.runInContext(source, context);
  return { nodes, scripts, overlays, osmPaths, window, submit,
    async query(data) { response = data; await form.listeners.submit({ preventDefault() {} }); await flush(); },
    async switchTo(value) { nodes['infleet-provedor'].value = value; nodes['infleet-provedor'].listeners.change(); await flush(); }
  };
}
const points = [
  { latitude: -15.60, longitude: -56.10, fixTime: '2026-10-14T12:00:00Z' },
  { latitude: -15.61, longitude: -56.11, fixTime: '2026-10-14T12:05:00Z' },
  { latitude: -15.62, longitude: -56.12, fixTime: '2026-10-14T12:30:00Z' },
  { latitude: -15.63, longitude: -56.13, fixTime: '2026-10-14T12:35:00Z' }
];
test('Google: carregamento único com nonce, segmentos GPS e troca para OpenStreetMap', async () => {
  const f = fixture();
  await f.query({ trips: [], positions: points });
  assert.equal(f.scripts.length, 1);
  assert.equal(f.scripts[0].nonce, 'NONCE_TESTE');
  const url = new URL(f.scripts[0].src);
  assert.equal(url.origin, 'https://maps.googleapis.com');
  assert.equal(url.searchParams.get('auth_referrer_policy'), 'origin');
  assert.equal(f.overlays.filter(o => o.options.path).length, 2, 'Não deve ligar lacuna acima de 10 minutos');
  assert.equal(f.nodes['infleet-mapa-google'].hidden, false);
  await f.switchTo('osm');
  assert.equal(f.osmPaths.length, 2);
  await f.switchTo('google');
  assert.equal(f.scripts.length, 1, 'Não deve recarregar API a cada troca');
  assert.equal(f.submit.disabled, false);
});
test('Links Google sem chave abrem coordenadas reais, sem calcular rota alternativa', async () => {
  const f = fixture({ google: false });
  await f.query({ trips: [], positions: points });
  assert.equal(f.scripts.length, 0);
  const start = new URL(f.nodes['infleet-google-inicio'].href);
  const end = new URL(f.nodes['infleet-google-fim'].href);
  assert.equal(start.pathname, '/maps/search/');
  assert.equal(start.searchParams.get('query'), '-15.6,-56.1');
  assert.equal(end.searchParams.get('query'), '-15.63,-56.13');
  await f.query({ trips: [], positions: [] });
  assert.equal(f.nodes['infleet-links-google'].hidden, true);
});
test('Falha ao carregar Google conserva o histórico e exibe OpenStreetMap', async () => {
  const f = fixture({ fail: true });
  await f.query({ trips: [], positions: points });
  assert.equal(f.nodes['infleet-provedor'].value, 'osm');
  assert.match(f.nodes['infleet-mapa-status'].textContent, /Google Maps indisponível/);
  assert.equal(f.osmPaths.length, 2);
  assert.equal(f.submit.disabled, false);
});
test('Falha de autenticação após carregar Google também retorna ao OpenStreetMap', async () => {
  const f = fixture();
  await f.query({ trips: [], positions: points });
  f.window.gm_authFailure(); await flush();
  assert.equal(f.nodes['infleet-provedor'].value, 'osm');
  assert.equal(f.nodes['infleet-mapa-google'].hidden, true);
});
test('Viagem selecionada limita desenho e links Google aos pontos daquela viagem', async () => {
  const f = fixture();
  await f.query({ trips: [{ startedAt: points[0].fixTime, finishedAt: points[1].fixTime, driver: { name: '<script>teste</script>' } }], positions: points });
  const row = f.nodes['infleet-viagens'].children[0];
  assert.equal(row.children[2].textContent, '<script>teste</script>');
  row.children[4].children[0].listeners.click(); await flush();
  assert.equal(new URL(f.nodes['infleet-google-fim'].href).searchParams.get('query'), '-15.61,-56.11');
});
