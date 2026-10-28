/* Ajuda e tours locais. Não faz requisições nem aciona controles de negócio. */
(function () {
  'use strict';
  const T = window.SAGI18n ? window.SAGI18n.t : (text, params = {}) => String(text).replace(/\{(\w+)\}/g, (match, key) => Object.prototype.hasOwnProperty.call(params, key) ? String(params[key]) : match);
  const configNode = document.getElementById('tutorial-config');
  if (!configNode || typeof HTMLDialogElement === 'undefined') { return; }
  const config = JSON.parse(configNode.textContent);
  const catalog = config.catalog;
  const help = document.getElementById('tutorial-help');
  const tour = document.getElementById('tutorial-tour');
  const body = document.getElementById('tutorial-help-body');
  const invite = document.getElementById('tutorial-invite');
  const balloon = document.getElementById('tutorial-balloon');
  const highlight = document.getElementById('tutorial-highlight');
  const preference = document.getElementById('tutorial-suggestions');
  const prefix = 'sag:tutoriais:' + config.user + ':';
  let storage = null;
  try { const probe = prefix + 'probe'; localStorage.setItem(probe, '1'); localStorage.removeItem(probe); storage = localStorage; } catch (_) { /* Ajuda funciona mesmo com armazenamento bloqueado. */ }
  const memory = new Map();
  const read = key => { try { return JSON.parse((storage ? storage.getItem(prefix + key) : memory.get(key)) || 'null'); } catch (_) { return null; } };
  const write = (key, value) => { const json = JSON.stringify(value); memory.set(key, json); try { if (storage) { storage.setItem(prefix + key, json); } } catch (_) { storage = null; } };
  const progressKey = m => (m.context || m.id) + ':v' + m.version;
  const progress = m => read(progressKey(m)) || { state: 'nao_iniciado' };
  const statusNames = { nao_iniciado: T('Não iniciado'), em_andamento: T('Em andamento'), concluido: T('Concluído'), dispensado: T('Dispensado') };
  function refreshStatuses() {
    document.querySelectorAll('[data-tutorial-status]').forEach(el => {
      const m = catalog[el.dataset.tutorialStatus];
      if (m) { el.textContent = statusNames[progress(m).state] || statusNames.nao_iniciado; }
    });
  }
  function save(m, value) { write(progressKey(m), { ...progress(m), ...value }); refreshStatuses(); }
  const visible = el => !!el && el.getClientRects().length > 0 && getComputedStyle(el).visibility !== 'hidden' && !el.closest('[hidden],dialog:not([open])');
  let invoker = null, active = null, steps = [], index = 0, target = null, waiting = false, epoch = 0;
  function restore() {
    if (document.querySelector('dialog[open]')) { return; }
    const contextual = document.querySelector('[data-tutorial-open="' + config.current + '"]');
    const el = invoker && invoker !== document.body && invoker.isConnected && visible(invoker) && !invoker.closest('#tutorial-invite') ? invoker : contextual;
    if (visible(el)) { el.focus(); }
  }
  // Aqui entram somente textos do tutorial e rótulos estáticos, nunca dados de cadastros.
  function element(tag, value, parent, cls) { const el = document.createElement(tag); if (value) { el.textContent = T(value); } if (cls) { el.className = cls; } if (parent) { parent.append(el); } return el; }
  function button(label, parent, fn) { const b = element('button', label, parent, 'btn btn-secundario'); b.type = 'button'; b.addEventListener('click', fn); return b; }
  function openHelp(id, trigger) {
    const m = catalog[id]; if (!m || document.querySelector('dialog[open]:not(#tutorial-help):not(#tutorial-tour)')) { return; }
    if (tour.open) { finish('em_andamento'); }
    invoker = trigger || document.activeElement; invite.hidden = true;
    document.getElementById('tutorial-help-title').textContent = m.title + ' — ' + T('Como usar');
    body.replaceChildren(); element('p', m.summary, body);
    if (id === config.current) {
      const actions = element('div', '', body, 'tutorial-botoes');
      const state = progress(m).state;
      button(state === 'em_andamento' ? 'Retomar tour' : state === 'nao_iniciado' ? 'Fazer o tour' : 'Reiniciar tour', actions, () => start(id, false));
      if (state === 'em_andamento') { button('Reiniciar tour', actions, () => start(id, true)); }
    } else if (id !== 'registros') {
      const a = element('a', 'Abrir módulo e fazer o tour', body, 'btn btn-secundario');
      const url = new URL(m.route, location.href); url.searchParams.set('tutorial', id); url.searchParams.set('tour', '1'); a.href = url.href;
    } else {
      element('p', 'Para fazer este tour, abra um registro pela data e hora em Relatórios.', body, 'dica');
    }
    element('h3', 'O que você pode fazer aqui', body);
    const ul = element('ul', '', body); m.capabilities.forEach(c => element('li', c, ul));
    element('h3', 'Passo a passo', body);
    m.procedures.forEach((p, n) => {
      const d = element('details', '', body); d.open = n === 0; d.id = 'tutorial-section-' + p.id;
      element('summary', p.title, d); const list = element('ol', '', d); p.steps.forEach(s => element('li', s, list));
      element('h4', 'Como conferir', d); element('p', p.expectedResult, d);
      element('h4', 'Se não funcionar', d); element('p', p.recovery, d);
    });
    element('h3', 'Dicas e permissões', body); m.tips.forEach(t => element('p', t, body));
    element('p', m.review, body, 'dica');
    element('p', storage ? 'Progresso salvo por usuário e versão apenas neste navegador. Concluir o tour não comprova treinamento formal ou aceite de política.' : 'O navegador não permite salvar o progresso. A ajuda continua disponível, mas o progresso será perdido ao sair desta página.', body, 'dica');
    preference.checked = (read('preferencias') || {}).suggest !== false;
    preference.disabled = !storage;
    if (!help.open) { help.showModal(); } body.scrollTop = 0;
    help.querySelector('[data-tutorial-close]').focus();
  }
  // As telas são renderizadas no servidor. Alvos opcionais ausentes são omitidos imediatamente.
  function start(id, restart) {
    if (waiting || id !== config.current || !catalog[id] || document.querySelector('dialog[open]:not(#tutorial-help):not(#tutorial-tour)')) { return; }
    waiting = true; const token = ++epoch; active = catalog[id]; invite.hidden = true;
    if (!help.open) { invoker = document.activeElement; } else { help.close(); }
    const found = [];
    for (const step of active.tour) {
      const el = step.target ? document.querySelector(step.target) : null;
      if (step.target && !visible(el) && step.onMissing === 'skip') { continue; }
      found.push(step);
    }
    waiting = false; if (token !== epoch) { return; }
    steps = found;
    if (!steps.length) { openHelp(id, invoker); return; }
    const saved = progress(active);
    const resume = !restart && saved.state === 'em_andamento' ? steps.findIndex(s => s.id === saved.step) : -1;
    index = resume >= 0 ? resume : 0;
    tour.showModal(); showStep(); document.getElementById('tutorial-next').focus();
  }
  function showStep() {
    // O alvo pode desaparecer durante o tour. Recalcula os passos e o contador.
    const currentId = steps[index] && steps[index].id;
    steps = steps.filter(s => !s.target || visible(document.querySelector(s.target)) || s.onMissing !== 'skip');
    index = Math.max(0, Math.min(steps.findIndex(s => s.id === currentId) >= 0 ? steps.findIndex(s => s.id === currentId) : index, steps.length - 1));
    if (!steps.length) { finish('em_andamento'); openHelp(active.id, invoker); return; }
    const s = steps[index]; target = s.target ? document.querySelector(s.target) : null;
    if (!visible(target)) { target = null; }
    if (target) {
      const rect = target.getBoundingClientRect();
      // Um alvo grande usa explicação central; não desloca o usuário para o meio do formulário.
      if (rect.height < window.innerHeight * .65 && rect.width < window.innerWidth - 24) {
        target.scrollIntoView({ behavior: 'instant', block: 'center', inline: 'nearest' });
      }
    }
    document.getElementById('tutorial-counter').textContent = T('Passo {current} de {total}', { current: index + 1, total: steps.length });
    document.getElementById('tutorial-tour-title').textContent = s.title;
    document.getElementById('tutorial-tour-text').textContent = s.text;
    const prev = document.getElementById('tutorial-prev'), wasFocused = document.activeElement === prev;
    prev.disabled = index === 0;
    if (index === 0 && wasFocused) { document.getElementById('tutorial-next').focus(); }
    document.getElementById('tutorial-next').textContent = T(index === steps.length - 1 ? 'Concluir' : 'Próximo');
    save(active, { state: 'em_andamento', step: s.id, suggested: true }); position();
  }
  function position() {
    if (!tour.open) { return; }
    const w = window.innerWidth, h = window.innerHeight, r = visible(target) ? target.getBoundingClientRect() : null;
    const bw = Math.min(410, w - 24), bh = balloon.getBoundingClientRect().height;
    let x = (w - bw) / 2, y = Math.max(12, (h - bh) / 2);
    const usable = r && r.width < w - 24 && r.height < h * .65;
    highlight.hidden = !usable; tour.toggleAttribute('data-central', !usable);
    if (usable) {
      Object.assign(highlight.style, { left: Math.max(0, r.left - 4) + 'px', top: Math.max(0, r.top - 4) + 'px', width: Math.min(w, r.width + 8) + 'px', height: Math.min(h, r.height + 8) + 'px' });
      if (r.bottom + bh + 20 < h) { x = r.left; y = r.bottom + 12; }
      else if (r.top > bh + 20) { x = r.left; y = r.top - bh - 12; }
      else if (r.right + bw + 20 < w) { x = r.right + 12; }
      else if (r.left > bw + 20) { x = r.left - bw - 12; }
      else { highlight.hidden = true; tour.setAttribute('data-central', ''); }
    }
    balloon.style.left = Math.max(12, Math.min(x, w - bw - 12)) + 'px';
    balloon.style.top = Math.max(12, Math.min(y, h - bh - 12)) + 'px';
  }
  function finish(state) { ++epoch; waiting = false; if (active) { save(active, { state, suggested: true }); } if (tour.open) { tour.close(); } target = null; restore(); }
  document.addEventListener('click', ev => { const b = ev.target.closest('[data-tutorial-open]'); if (b) { openHelp(b.dataset.tutorialOpen, b); } });
  help.querySelector('[data-tutorial-close]').addEventListener('click', () => help.close());
  help.addEventListener('close', () => { if (!tour.open && !waiting) { restore(); } });
  preference.addEventListener('change', () => write('preferencias', { suggest: preference.checked }));
  document.getElementById('tutorial-prev').addEventListener('click', () => { if (index > 0) { index--; showStep(); } });
  document.getElementById('tutorial-next').addEventListener('click', () => { if (index < steps.length - 1) { index++; showStep(); } else { finish('concluido'); } });
  document.getElementById('tutorial-skip').addEventListener('click', () => finish('dispensado'));
  tour.addEventListener('cancel', ev => { ev.preventDefault(); finish('dispensado'); });
  tour.addEventListener('keydown', ev => {
    if (ev.target.matches('input,textarea,select,[contenteditable]')) { return; }
    if (ev.key === 'ArrowRight') { ev.preventDefault(); document.getElementById('tutorial-next').click(); }
    if (ev.key === 'ArrowLeft' && index > 0) { ev.preventDefault(); index--; showStep(); }
  });
  window.addEventListener('resize', position); document.addEventListener('scroll', position, true);
  const resize = new ResizeObserver(position); resize.observe(balloon); const main = document.querySelector('main'); if (main) { resize.observe(main); }
  // Não sobrepõe diálogos da operação; ao surgir outro diálogo, encerra mantendo o passo.
  new MutationObserver(() => {
    if (document.querySelector('dialog[open]:not(#tutorial-help):not(#tutorial-tour)')) { invite.hidden = true; if (tour.open) { finish('em_andamento'); } if (help.open) { help.close(); } }
  }).observe(document.body, { attributes: true, attributeFilter: ['open'], subtree: true });
  window.addEventListener('pagehide', () => { if (tour.open) { finish('em_andamento'); } ++epoch; resize.disconnect(); });
  const search = document.getElementById('tutorial-search');
  if (search) { search.addEventListener('input', () => { let count = 0; document.querySelectorAll('[data-tutorial-card]').forEach(card => { card.hidden = !card.textContent.toLocaleLowerCase('pt-BR').includes(search.value.trim().toLocaleLowerCase('pt-BR')); if (!card.hidden) { count++; } }); document.getElementById('tutorial-empty').hidden = count !== 0; }); }
  const m = catalog[config.current];
  document.getElementById('tutorial-invite-tour').addEventListener('click', () => start(config.current, false));
  document.getElementById('tutorial-invite-help').addEventListener('click', ev => openHelp(config.current, ev.currentTarget));
  document.getElementById('tutorial-invite-dismiss').addEventListener('click', ev => { invoker = ev.currentTarget; invite.hidden = true; if (m) { save(m, { state: 'dispensado', suggested: true }); } restore(); });
  refreshStatuses();
  const query = new URLSearchParams(location.search);
  if (m && query.get('tutorial') === m.id && query.get('tour') === '1' && !document.body.classList.contains('modo-tv')) {
    const clean = new URL(location.href); clean.searchParams.delete('tutorial'); clean.searchParams.delete('tour'); history.replaceState(null, '', clean.href); start(m.id, false);
  } else if (m && storage && !progress(m).suggested && (read('preferencias') || {}).suggest !== false && !document.querySelector('dialog[open]') && !document.body.classList.contains('modo-tv')) {
    save(m, { suggested: true }); invite.hidden = false;
  }
}());
