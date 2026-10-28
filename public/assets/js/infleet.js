/* Leaflet é servido localmente; nenhuma credencial Infleet chega ao navegador. */
(() => {
  'use strict';
  const form = document.getElementById('infleet-filtro');
  if (!form) return;
  const locale = window.SAGI18n?.locale || 'pt-BR';
  const t = window.SAGI18n?.t || ((source, params) => String(source).replace(/\{([A-Za-z0-9_]+)\}/g, (token, key) => params && Object.prototype.hasOwnProperty.call(params, key) ? String(params[key]) : token));
  const status = document.getElementById('infleet-status');
  const mapStatus = document.getElementById('infleet-mapa-status');
  const body = document.getElementById('infleet-viagens');
  const allButton = document.getElementById('infleet-todos');
  const provider = document.getElementById('infleet-provedor');
  const googleElement = document.getElementById('infleet-mapa-google');
  const osmElement = document.getElementById('infleet-mapa');
  const googleLinks = document.getElementById('infleet-links-google');
  const googleKey = osmElement.dataset.googleKey;
  const scriptNonce = document.currentScript?.nonce || '';
  let map, layer, data;
  let googleMap, googlePromise, googleBounds, googleInfoWindow, googleFailed = false, googleOverlays = [], generation = 0;
  let selectedPoints = [], selectedTrips = [];
  const formatDate = value => value ? new Date(value).toLocaleString(locale, { timeZone: 'America/Cuiaba' }) : t("Em andamento");
  const emptyRow = text => {
    body.replaceChildren();
    const row = body.insertRow();
    const cell = row.insertCell(); cell.colSpan = 5; cell.textContent = text;
  };
  function clearGoogle() {
    googleInfoWindow?.close();
    googleBounds = null;
    for (const overlay of googleOverlays) overlay.setMap(null);
    googleOverlays = [];
  }
  function segments(points, trips) {
    const groups = trips.length ? trips.map(trip => points.filter(p =>
      new Date(p.fixTime) >= new Date(trip.startedAt) && (!trip.finishedAt || new Date(p.fixTime) <= new Date(trip.finishedAt))
    )) : [points];
    const result = [];
    for (const group of groups) {
      let segment = [], previous;
      for (const point of group) {
        if (previous && new Date(point.fixTime) - new Date(previous.fixTime) > 600000) {
          if (segment.length) result.push(segment);
          segment = [];
        }
        segment.push(point); previous = point;
      }
      if (segment.length) result.push(segment);
    }
    return result;
  }
  function linkPoints(points) {
    googleLinks.hidden = !points.length;
    if (!points.length) return;
    for (const [id, point] of [['infleet-google-inicio', points[0]], ['infleet-google-fim', points[points.length - 1]]]) {
      const url = new URL('https://www.google.com/maps/search/');
      url.searchParams.set('api', '1'); url.searchParams.set('query', point.latitude + ',' + point.longitude);
      document.getElementById(id).href = url.href;
    }
  }
  function loadGoogle() {
    if (!googleKey || googleFailed) return Promise.reject(new Error(t("Google Maps indisponível.")));
    if (googlePromise) return googlePromise;
    googlePromise = new Promise((resolve, reject) => {
      const script = document.createElement('script');
      let settled = false;
      const fail = () => {
        googleFailed = true;
        if (!settled) { settled = true; clearTimeout(timer); reject(new Error(t("Google Maps não carregou. Confira a chave, o domínio autorizado e o faturamento."))); }
        else if (provider.value === 'google') {
          provider.value = 'osm';
          draw(selectedPoints, selectedTrips).then(() => { mapStatus.textContent += t(" Google Maps indisponível; exibindo OpenStreetMap."); });
        }
      };
      const timer = setTimeout(fail, 15000);
      window.gm_authFailure = fail;
      window.sagGoogleMapsReady = () => {
        if (settled) return;
        settled = true; clearTimeout(timer); resolve(window.google.maps);
      };
      const url = new URL('https://maps.googleapis.com/maps/api/js');
      for (const [key, value] of Object.entries({ key: googleKey, callback: 'sagGoogleMapsReady', loading: 'async', v: 'quarterly', language: locale, region: 'BR', auth_referrer_policy: 'origin' })) url.searchParams.set(key, value);
      script.src = url.href; script.async = true; script.nonce = scriptNonce; script.onerror = fail;
      document.head.append(script);
    });
    return googlePromise;
  }
  function drawGoogle(points, paths) {
    const maps = window.google.maps;
    if (!googleMap) {
      googleMap = new maps.Map(googleElement, { center: { lat: -15.6, lng: -56.1 }, zoom: 6, mapTypeId: 'roadmap', mapTypeControl: true, streetViewControl: true, fullscreenControl: true, gestureHandling: 'cooperative', renderingType: maps.RenderingType?.RASTER });
    }
    clearGoogle();
    const bounds = new maps.LatLngBounds();
    const covered = new Set();
    for (const path of paths) {
      if (path.length > 1) {
        googleOverlays.push(new maps.Polyline({ map: googleMap, path: path.map(p => ({ lat: p.latitude, lng: p.longitude })), strokeColor: '#005b3f', strokeWeight: 4, geodesic: false }));
        path.forEach(p => covered.add(p));
      }
    }
    // Inclui amostras isoladas e fora das viagens sem ligá-las a outros percursos.
    for (const point of points) {
      const center = { lat: point.latitude, lng: point.longitude };
      bounds.extend(center);
      if (!covered.has(point)) googleOverlays.push(new maps.Circle({ map: googleMap, center, radius: 8, fillColor: '#005b3f', fillOpacity: 1, strokeWeight: 1 }));
    }
    for (const [point, label, color] of [[points[0], t("Primeiro ponto"), '#005b3f'], [points[points.length - 1], t("Último ponto"), '#ad7000']]) {
      const circle = new maps.Circle({ map: googleMap, center: { lat: point.latitude, lng: point.longitude }, radius: 25, fillColor: color, fillOpacity: 1, strokeColor: '#ffffff', strokeWeight: 2 });
      const content = document.createElement('span'); content.textContent = label + ' · ' + formatDate(point.fixTime);
      circle.addListener('click', () => {
        googleInfoWindow?.close();
        googleInfoWindow = new maps.InfoWindow({ content, position: circle.getCenter() });
        googleInfoWindow.open({ map: googleMap });
      });
      googleOverlays.push(circle);
    }
    googleBounds = bounds;
    googleMap.fitBounds(bounds, 32);
    maps.event.addListenerOnce(googleMap, 'idle', () => { if (googleMap.getZoom() > 16) googleMap.setZoom(16); });
  }
  async function draw(points, trips) {
    const current = ++generation;
    selectedPoints = points; selectedTrips = trips; linkPoints(points);
    if (!points.length) {
      layer?.clearLayers(); clearGoogle();
      mapStatus.textContent = t("Sem posições GPS válidas para esta seleção."); return;
    }
    const paths = segments(points, trips);
    let fallback = '';
    if (provider.value === 'google') {
      mapStatus.textContent = t("Carregando Google Maps…");
      try {
        await loadGoogle();
        if (current !== generation) return;
        osmElement.hidden = true; googleElement.hidden = false;
        drawGoogle(points, paths);
        mapStatus.textContent = t('{count} posições GPS no Google Maps. Use o controle do mapa para alternar ruas e satélite. Lacunas acima de 10 minutos ficam sem ligação.', { count: points.length });
        return;
      } catch {
        if (current !== generation) return;
        provider.value = 'osm'; fallback = t(" Google Maps indisponível; exibindo OpenStreetMap.");
      }
    }
    osmElement.hidden = false; googleElement.hidden = true;
    clearGoogle();
    if (!window.L) { mapStatus.textContent = t("Biblioteca de mapa indisponível. O histórico continua disponível."); return; }
    if (!map) {
      map = L.map('infleet-mapa', { scrollWheelZoom: false, preferCanvas: true }).setView([-15.6, -56.1], 6);
      const tiles = L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19, attribution: '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a> contributors'
      }).addTo(map);
      tiles.on('tileerror', () => { mapStatus.textContent = t("O fundo cartográfico não carregou. O traçado GPS permanece disponível."); });
      layer = L.featureGroup().addTo(map);
      map.on('resize', () => {
        if (layer.getLayers().length) map.fitBounds(layer.getBounds(), { padding: [24, 24], maxZoom: 16 });
      });
    }
    layer.clearLayers();
    if (!points.length) { mapStatus.textContent = t("Sem posições GPS válidas para esta seleção."); return; }
    for (const path of paths) if (path.length > 1) L.polyline(path.map(p => [p.latitude, p.longitude]), { color: '#005b3f', weight: 4 }).addTo(layer);
    // Pontos fora das viagens também ficam visíveis, sem inventar ligações entre viagens.
    for (const point of points) {
      L.circleMarker([point.latitude, point.longitude], { radius: 2, color: '#005b3f', weight: 1, interactive: false }).addTo(layer);
    }
    for (const [point, label, color] of [[points[0], t("Primeiro ponto"), '#005b3f'], [points[points.length - 1], t("Último ponto"), '#ad7000']]) {
      const popup = document.createElement('span'); popup.textContent = label + ' · ' + formatDate(point.fixTime);
      L.circleMarker([point.latitude, point.longitude], { radius: 7, color, fillOpacity: 1 }).bindPopup(popup).addTo(layer);
    }
    map.invalidateSize(); map.fitBounds(layer.getBounds(), { padding: [24, 24], maxZoom: 16 });
    mapStatus.textContent = t('{count} posições GPS. Primeiro ponto em verde; último em dourado. Lacunas acima de 10 minutos ficam sem ligação.', { count: points.length }) + fallback;
  }
  provider.addEventListener('change', () => { if (selectedPoints.length) draw(selectedPoints, selectedTrips); });
  window.addEventListener?.('resize', () => {
    if (googleMap && googleBounds && !googleElement.hidden) googleMap.fitBounds(googleBounds, 32);
  });
  allButton.addEventListener('click', () => { if (data) draw(data.positions, data.trips); });
  form.addEventListener('submit', async event => {
    event.preventDefault();
    const submit = form.querySelector('button[type="submit"]');
    submit.disabled = true;
    status.textContent = t("Consultando a Infleet…");
    ++generation; selectedPoints = []; selectedTrips = []; googleLinks.hidden = true;
    data = null; layer?.clearLayers(); clearGoogle(); allButton.hidden = true; emptyRow(t("Consultando…"));
    mapStatus.textContent = t("Aguardando posições GPS.");
    try {
      const query = new URLSearchParams(new FormData(form));
      const response = await fetch(form.action + '?' + query, { headers: { Accept: 'application/json' }, cache: 'no-store' });
      const result = await response.json();
      if (!response.ok || result.erro) throw new Error(result.erro || t("Consulta indisponível."));
      if (!Array.isArray(result.trips) || !Array.isArray(result.positions)) throw new Error(t("Resposta de percurso inválida."));
      data = result;
      body.replaceChildren();
      for (const trip of data.trips) {
        const row = body.insertRow();
        for (const text of [formatDate(trip.startedAt), formatDate(trip.finishedAt), trip.driver?.name || t("Não identificado"), trip.distanceTraveled == null ? t("Sem leitura") : Number(trip.distanceTraveled).toLocaleString(locale)]) {
          row.insertCell().textContent = text;
        }
        const button = document.createElement('button');
        button.type = 'button'; button.className = 'btn btn-secundario btn-sm'; button.textContent = t("Ver trajeto");
        button.addEventListener('click', () => draw(data.positions.filter(p => new Date(p.fixTime) >= new Date(trip.startedAt) && (!trip.finishedAt || new Date(p.fixTime) <= new Date(trip.finishedAt))), [trip]));
        row.insertCell().append(button);
      }
      if (!data.trips.length) emptyRow(t("Nenhuma viagem no período."));
      allButton.hidden = false;
      await draw(data.positions, data.trips);
      status.textContent = data.trips.length === 1 ? t("1 viagem consultada.") : t('{count} viagens consultadas.', { count: data.trips.length });
    } catch (error) {
      status.textContent = error.message || t("Não foi possível consultar os percursos.");
      emptyRow(t("Consulta não concluída.")); mapStatus.textContent = t("Trajeto indisponível para esta consulta.");
    } finally { submit.disabled = false; }
  });
})();
