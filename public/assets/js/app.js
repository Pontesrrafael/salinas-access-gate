/* Salinas Access Gate — JavaScript da interface (sem dependências externas). */
(function () {
  'use strict';

  /* ---------------- Utilitários ---------------- */
  const $ = (sel, ctx) => (ctx || document).querySelector(sel);
  const $$ = (sel, ctx) => Array.from((ctx || document).querySelectorAll(sel));
  const meta = (n) => (document.querySelector('meta[name="' + n + '"]') || {}).content || '';
  const BASE = meta('base-url').replace(/\/$/, '');
  const CSRF = meta('csrf-token');

  const esc = (v) => String(v == null ? '' : v)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;').replace(/'/g, '&#39;');

  const debounce = (fn, ms) => { let t; return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); }; };

  function toast(msg, tipo) {
    const el = $('#toast');
    if (!el) { return; }
    el.textContent = msg;
    el.className = 'toast visivel ' + (tipo || '');
    clearTimeout(el._t);
    el._t = setTimeout(() => { el.className = 'toast'; }, 4000);
  }

  async function api(path, opts) {
    opts = opts || {};
    const headers = { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
    if (opts.method && opts.method !== 'GET') { headers['X-CSRF-Token'] = CSRF; }
    let r;
    try {
      r = await fetch(path.startsWith('http') ? path : BASE + path, {
        method: opts.method || 'GET', body: opts.body, headers: headers, credentials: 'same-origin',
      });
    } catch (e) {
      return { ok: false, status: 0, rede: true, data: null }; // sem conexão com o servidor
    }
    let data = null;
    try { data = await r.json(); } catch (e) { data = null; }
    if (r.status === 401 && !opts.semRedirecionar) { window.location.href = BASE + '/login'; }
    return { ok: r.ok, status: r.status, data: data };
  }

  /* ---------------- Máscaras ---------------- */
  function mascaraCpf(v) {
    const d = v.replace(/\D/g, '').slice(0, 11);
    return d.replace(/(\d{3})(\d)/, '$1.$2').replace(/(\d{3})(\d)/, '$1.$2').replace(/(\d{3})(\d{1,2})$/, '$1-$2');
  }
  function mascaraTelefone(v) {
    const d = v.replace(/\D/g, '').slice(0, 11);
    if (d.length <= 2) { return d; }
    if (d.length <= 6) { return '(' + d.slice(0, 2) + ') ' + d.slice(2); }
    if (d.length <= 10) { return '(' + d.slice(0, 2) + ') ' + d.slice(2, 6) + '-' + d.slice(6); }
    return '(' + d.slice(0, 2) + ') ' + d.slice(2, 7) + '-' + d.slice(7);
  }
  document.addEventListener('input', (ev) => {
    const el = ev.target;
    if (!(el instanceof HTMLInputElement)) { return; }
    if (el.dataset.mascara === 'cpf') { el.value = mascaraCpf(el.value); }
    if (el.dataset.mascara === 'telefone') { el.value = mascaraTelefone(el.value); }
    if (el.classList.contains('maiusculo')) {
      const p = el.selectionStart; el.value = el.value.toUpperCase(); try { el.setSelectionRange(p, p); } catch (e) { /* noop */ }
    }
  });

  /* ---------------- Comportamentos genéricos ---------------- */
  document.addEventListener('click', async (ev) => {
    const abrir = ev.target.closest('[data-abrir]');
    if (abrir) {
      const dlg = document.getElementById(abrir.dataset.abrir);
      if (dlg && dlg.showModal) { dlg.showModal(); const f = dlg.querySelector('input,select,textarea'); if (f) { f.focus(); } }
      return;
    }
    const fechar = ev.target.closest('[data-fechar]');
    if (fechar) { const dlg = fechar.closest('dialog'); if (dlg) { dlg.close(); } return; }

    const voltar = ev.target.closest('[data-voltar]');
    if (voltar && window.history.length > 1) { ev.preventDefault(); window.history.back(); return; }

    const imprimir = ev.target.closest('[data-imprimir]');
    if (imprimir) { ev.preventDefault(); window.print(); return; }

    const copiar = ev.target.closest('[data-copiar-link]');
    if (copiar) {
      ev.preventDefault();
      const r = await api(copiar.dataset.copiarLink);
      if (r.ok && r.data && r.data.url) {
        try { await navigator.clipboard.writeText(r.data.url); toast('Link copiado.', 'sucesso'); }
        catch (e) { window.prompt('Copie o link:', r.data.url); }
      } else { toast((r.data && r.data.erro) || 'Sem permissão para copiar o link.', 'erro'); }
      return;
    }
    const copiarTexto = ev.target.closest('[data-copiar]');
    if (copiarTexto) {
      ev.preventDefault();
      try { await navigator.clipboard.writeText(copiarTexto.dataset.copiar); toast('Copiado.', 'sucesso'); }
      catch (e) { window.prompt('Copie:', copiarTexto.dataset.copiar); }
      return;
    }

    const revelar = ev.target.closest('[data-revelar-cpf]');
    if (revelar) {
      ev.preventDefault();
      const motivo = window.prompt('Informe o motivo para revelar o CPF (registrado na auditoria):', '');
      if (motivo === null) { return; }
      const fd = new FormData(); fd.append('motivo', motivo);
      const r = await api(revelar.dataset.revelarCpf, { method: 'POST', body: fd });
      if (r.ok && r.data) {
        const alvo = document.getElementById(revelar.dataset.alvo);
        if (alvo) { alvo.textContent = r.data.cpf || '—'; }
        revelar.remove();
      } else { toast((r.data && r.data.erro) || 'Não foi possível revelar o CPF.', 'erro'); }
    }
  });

  document.addEventListener('change', (ev) => {
    const el = ev.target;
    if (el.matches && el.matches('[data-autosubmit]') && el.form) { el.form.submit(); }
  });

  document.addEventListener('submit', (ev) => {
    if (ev.defaultPrevented) { return; }
    const f = ev.target;
    const msg = f.dataset.confirmar;
    if (msg && !window.confirm(msg)) { ev.preventDefault(); return; }
    if (!f.dataset.endpoint) {
      const b = f.querySelector('button[type="submit"]:not([data-livre])');
      if (b) { setTimeout(() => { b.disabled = true; }, 0); }
    }
  });

  // Reabre diálogo após erro de validação (dados preservados)
  $$('dialog[data-auto-abrir]').forEach((d) => { if (d.showModal) { d.showModal(); } });

  // Atualização automática (ex.: presença)
  const auto = document.querySelector('[data-autorefresh]');
  if (auto) {
    const s = parseInt(auto.dataset.autorefresh, 10) || 60;
    const recarregar = () => {
      const selecionando = document.querySelector('[data-confirmar-lote] input[type="checkbox"]:checked');
      if (document.querySelector('dialog[open]') || selecionando) { setTimeout(recarregar, 15000); return; }
      window.location.reload();
    };
    setTimeout(recarregar, s * 1000);
  }

  // Busca com Enter não deve submeter o formulário da portaria
  document.addEventListener('keydown', (ev) => {
    if (ev.key === 'Enter' && ev.target.matches && ev.target.matches('#busca-pessoa, #busca-veiculo, #responsavel')) {
      ev.preventDefault();
      const lista = ev.target.parentElement.querySelector('.resultados button');
      if (lista) { lista.click(); }
    }
  });

  /* ---------------- Usuários: vínculo com Active Directory ---------------- */
  const formUsuario = $('#form-usuario');
  if (formUsuario && $('#bloco-ad')) {
    const blocoAd = $('#bloco-ad'), blocoSenha = $('#bloco-senha'), buscaAd = $('#busca-ad'), resAd = $('#res-ad');
    const guid = $('#ldap_guid'), sel = $('#ad-selecionado');
    const alternar = () => {
      const r = formUsuario.querySelector('input[name="auth_origem"]:checked');
      const ad = r && r.value === 'ldap';
      blocoAd.hidden = !ad;
      if (blocoSenha) { blocoSenha.hidden = ad; }
    };
    $$('input[name="auth_origem"]', formUsuario).forEach((r) => r.addEventListener('change', alternar));
    buscaAd.addEventListener('input', debounce(async () => {
      const q = buscaAd.value.trim();
      if (q.length < 2) { resAd.hidden = true; return; }
      const r = await api('/api/ldap/busca?q=' + encodeURIComponent(q));
      if (!r.ok) { toast((r.data && r.data.erro) || 'Falha ao consultar o AD.', 'erro'); return; }
      resAd.innerHTML = '';
      (r.data || []).forEach((e) => {
        const li = document.createElement('li'); const b = document.createElement('button'); b.type = 'button';
        b.innerHTML = '<strong>' + esc(e.nome || e.login) + '</strong> <span class="badge badge-neutro mono">' + esc(e.login) + '</span>' +
          (e.ativo ? '' : ' <span class="badge badge-perigo">desativada</span>') +
          (e.vinculado_a ? ' <span class="badge badge-aviso">já vinculada: ' + esc(e.vinculado_a) + '</span>' : '') +
          '<small>' + esc([e.email, e.matricula ? 'Matr. ' + e.matricula : '', e.departamento, e.cargo].filter(Boolean).join(' · ')) + '</small>';
        b.addEventListener('mousedown', (ev) => ev.preventDefault());
        b.addEventListener('click', () => {
          if (!e.ativo) { toast('Conta desativada no AD.', 'erro'); return; }
          guid.value = e.guid; resAd.hidden = true; buscaAd.value = '';
          sel.innerHTML = '<strong>' + esc(e.login) + '</strong> — ' + esc(e.nome) + '<span class="sub mono">' + esc(e.dn) + '</span>';
          sel.hidden = false;
          [['nome', e.nome], ['email', e.email], ['matricula', e.matricula]].forEach(([n, v]) => {
            const campo = formUsuario.querySelector('[name="' + n + '"]');
            if (campo && v && !campo.value.trim()) { campo.value = v; }
          });
        });
        li.appendChild(b); resAd.appendChild(li);
      });
      if (!(r.data || []).length) { resAd.innerHTML = '<li class="dica">Nenhuma conta encontrada no AD.</li>'; }
      resAd.hidden = false;
    }, 300));
    buscaAd.addEventListener('blur', () => setTimeout(() => { resAd.hidden = true; }, 150));
    formUsuario.addEventListener('submit', (ev) => {
      const r = formUsuario.querySelector('input[name="auth_origem"]:checked');
      if (r && r.value === 'ldap' && !guid.value) { ev.preventDefault(); toast('Selecione a conta do AD na busca.', 'erro'); buscaAd.focus(); }
    });
  }

  /* ---------------- Tema (automático / escuro / claro) ---------------- */
  (function tema() {
    const botoes = $$('[data-tema]');
    if (!botoes.length) { return; }
    const ordem = ['', 'escuro', 'claro'];
    const rotulos = { '': 'Automático', escuro: 'Escuro', claro: 'Claro' };
    const atual = () => {
      const m = document.cookie.match(/(?:^|;\s*)sag_tema=(escuro|claro)/);
      return m ? m[1] : '';
    };
    const aplicar = (t) => {
      const raiz = document.documentElement;
      if (t === 'escuro') { raiz.setAttribute('data-theme', 'dark'); } else if (t === 'claro') { raiz.setAttribute('data-theme', 'light'); } else { raiz.removeAttribute('data-theme'); }
      document.cookie = t
        ? 'sag_tema=' + t + '; path=/; max-age=31536000; SameSite=Lax'
        : 'sag_tema=; path=/; max-age=0; SameSite=Lax';
      botoes.forEach((b) => { const s = b.querySelector('span'); if (s) { s.textContent = 'Tema: ' + rotulos[t]; } });
    };
    botoes.forEach((b) => b.addEventListener('click', () => {
      const t = ordem[(ordem.indexOf(atual()) + 1) % ordem.length];
      aplicar(t);
      toast('Tema: ' + rotulos[t] + (t === '' ? ' (segue o sistema)' : ''));
    }));
  })();

  /* ---------------- Indicadores (modo dashboard / Modo TV) ---------------- */
  (function indicadores() {
    const caixa = $('[data-indicadores]');
    if (!caixa) { return; }
    const form = $('#form-indicadores');
    const selPeriodo = $('#ind-periodo');
    const inDesde = $('#ind-desde');
    const inAte = $('#ind-ate');
    const selAtualizar = $('#ind-atualizar');
    const status = $('#ind-status');
    const CHAVE = 'sag_ind_atualizar';
    // Gráficos largos (rolagem horizontal no celular) começam mostrando o período mais recente
    const rolarParaFim = () => $$('.viz-rolagem', caixa).forEach((el) => { el.scrollLeft = el.scrollWidth; });
    rolarParaFim();
    const permitidos = Array.from(selAtualizar ? selAtualizar.options : []).map((o) => o.value);

    // Período: atalho limpa as datas e aplica; mexer nas datas vira "Personalizado"
    if (selPeriodo) {
      selPeriodo.addEventListener('change', () => {
        if (selPeriodo.value !== 'personalizado') {
          if (inDesde) { inDesde.value = ''; }
          if (inAte) { inAte.value = ''; }
          form.submit();
        } else if (inDesde) { inDesde.focus(); }
      });
    }
    [inDesde, inAte].forEach((el) => el && el.addEventListener('change', () => { if (selPeriodo) { selPeriodo.value = 'personalizado'; } }));

    // Intervalo: parâmetro da URL > preferência salva neste navegador > padrão
    const lerPref = () => { try { return localStorage.getItem(CHAVE); } catch (e) { return null; } };
    const salvarPref = (v) => { try { localStorage.setItem(CHAVE, v); } catch (e) { /* navegador sem storage */ } };
    let intervalo = caixa.dataset.atualizar;
    if (intervalo === '' || permitidos.indexOf(intervalo) < 0) {
      const pref = lerPref();
      intervalo = pref !== null && permitidos.indexOf(pref) >= 0 ? pref : caixa.dataset.padrao;
    }
    if (selAtualizar) { selAtualizar.value = intervalo; }

    const hora = () => new Date().toLocaleTimeString('pt-BR', { timeZone: 'America/Cuiaba' });
    const setStatus = (txt, cls) => {
      if (!status) { return; }
      status.classList.remove('erro', 'carregando');
      if (cls) { status.classList.add(cls); }
      const span = status.querySelector('span');
      if (span) { span.textContent = txt; }
    };
    const descrever = () => (intervalo === '0' ? ' · atualização automática desligada' : ' · próxima em ' + (selAtualizar ? selAtualizar.options[selAtualizar.selectedIndex].text : intervalo + ' s'));

    let timer = null;
    let emCurso = false;
    let pendenteAoVoltar = false;

    function agendar() {
      clearTimeout(timer);
      const s = parseInt(intervalo, 10) || 0;
      if (s > 0) { timer = setTimeout(atualizar, s * 1000); }
    }

    async function atualizar() {
      if (document.hidden) { pendenteAoVoltar = true; return; }   // economiza servidor com a aba oculta
      if (emCurso) { agendar(); return; }
      emCurso = true;
      setStatus('Atualizando…', 'carregando');
      const params = new URLSearchParams(window.location.search);
      params.set('parcial', '1');
      try {
        const r = await fetch(window.location.pathname + '?' + params.toString(), {
          headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'text/html' }, credentials: 'same-origin', cache: 'no-store',
        });
        if (r.status === 401 || r.redirected) { window.location.href = BASE + '/login'; return; }
        if (!r.ok) { throw new Error('HTTP ' + r.status); }
        const html = await r.text();
        const abertos = $$('details', caixa).map((d, i) => (d.open ? i : -1)).filter((i) => i >= 0);
        caixa.innerHTML = html;
        const novos = $$('details', caixa);
        abertos.forEach((i) => { if (novos[i]) { novos[i].open = true; } });
        rolarParaFim();
        setStatus('Atualizado às ' + hora() + descrever());
      } catch (e) {
        setStatus('Falha ao atualizar às ' + hora() + ' — nova tentativa no próximo ciclo', 'erro');
      } finally {
        emCurso = false;
        agendar();
      }
    }

    document.addEventListener('visibilitychange', () => {
      if (!document.hidden && pendenteAoVoltar) { pendenteAoVoltar = false; atualizar(); }
    });

    if (selAtualizar) {
      selAtualizar.addEventListener('change', () => {
        intervalo = selAtualizar.value;
        salvarPref(intervalo);
        const url = new URL(window.location.href);
        url.searchParams.set('atualizar', intervalo);
        window.history.replaceState(null, '', url.toString());
        setStatus('Atualizado às ' + hora() + descrever());
        agendar();
      });
    }
    setStatus('Atualizado às ' + hora() + descrever());
    agendar();

    // Dica flutuante dos gráficos (substitui o title nativo)
    const dica = document.createElement('div');
    dica.className = 'viz-dica';
    dica.setAttribute('role', 'tooltip');
    document.body.appendChild(dica);
    caixa.addEventListener('mouseover', (ev) => {
      const alvo = ev.target.closest && ev.target.closest('[data-tip]');
      if (!alvo) { return; }
      alvo.removeAttribute('title');
      dica.textContent = alvo.dataset.tip;
      dica.classList.add('visivel');
    });
    caixa.addEventListener('mousemove', (ev) => {
      if (!dica.classList.contains('visivel')) { return; }
      const w = dica.offsetWidth;
      const h = dica.offsetHeight;
      let x = ev.clientX + 14;
      let y = ev.clientY + 14;
      if (x + w > window.innerWidth - 8) { x = ev.clientX - w - 14; }
      if (y + h > window.innerHeight - 8) { y = ev.clientY - h - 14; }
      dica.style.left = Math.max(8, x) + 'px';
      dica.style.top = Math.max(8, y) + 'px';
    });
    caixa.addEventListener('mouseout', (ev) => {
      const alvo = ev.target.closest && ev.target.closest('[data-tip]');
      if (alvo && !alvo.contains(ev.relatedTarget)) { dica.classList.remove('visivel'); }
    });

    // Modo TV: relógio e tela cheia
    const relogio = $('#tv-relogio');
    if (relogio) {
      const tic = () => { relogio.textContent = new Date().toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit', timeZone: 'America/Cuiaba' }); };
      tic();
      setInterval(tic, 1000);
    }
    $$('[data-tela-cheia]').forEach((b) => b.addEventListener('click', () => {
      if (document.fullscreenElement) { document.exitFullscreen(); } else if (document.documentElement.requestFullscreen) { document.documentElement.requestFullscreen(); }
    }));
  })();

  /* ---------------- Configurações: LDAP liga/desliga ---------------- */
  (() => {
    const liga = $('[data-ldap-liga]');
    const campos = $('[data-ldap-campos]');
    if (!liga || !campos) return;
    const sync = () => { campos.hidden = !liga.checked; };
    liga.addEventListener('change', sync);
    sync();
  })();

  /* ---------------- Scripts sob demanda (bibliotecas locais em assets/js/vendor) ---------------- */
  const scriptsCarregados = {};
  function carregarScript(arquivo) {
    if (!scriptsCarregados[arquivo]) {
      scriptsCarregados[arquivo] = new Promise((ok, erro) => {
        const sc = document.createElement('script');
        sc.src = BASE + '/assets/js/vendor/' + arquivo;
        sc.onload = ok; sc.onerror = () => { delete scriptsCarregados[arquivo]; erro(new Error('Falha ao carregar ' + arquivo)); };
        document.head.appendChild(sc);
      });
    }
    return scriptsCarregados[arquivo];
  }

  /* ---------------- QR do convite (desenho em canvas, preto no branco para leitura) ---------------- */
  (function qrConvite() {
    const telas = $$('canvas[data-qr]');
    if (!telas.length) { return; }
    carregarScript('qrcode-generator-1.4.4.js').then(() => {
      telas.forEach((cv) => {
        const qr = window.qrcode(0, 'M');
        qr.addData(cv.dataset.qr);
        qr.make();
        const n = qr.getModuleCount(), margem = 4, total = n + margem * 2;
        const escala = Math.max(2, Math.floor(cv.width / total));
        cv.width = cv.height = total * escala;
        const ctx = cv.getContext('2d');
        ctx.fillStyle = '#ffffff'; ctx.fillRect(0, 0, cv.width, cv.height);
        ctx.fillStyle = '#000000';
        for (let r = 0; r < n; r++) {
          for (let c = 0; c < n; c++) {
            if (qr.isDark(r, c)) { ctx.fillRect((c + margem) * escala, (r + margem) * escala, escala, escala); }
          }
        }
      });
    }).catch(() => toast('Não foi possível gerar o QR.', 'erro'));
  })();

  /* ---------------- Saída rápida / em lote (portaria e "Quem está no site") ---------------- */
  $$('form[data-confirmar-lote]').forEach((f) => {
    const caixas = () => $$('input[name="itens[]"][type="checkbox"]', f);
    const visiveis = () => caixas().filter((c) => !c.closest('li, tr').hidden);
    const todos = $('#presentes-todos', f), btnLote = $('[data-lote]', f), qtd = $('#lote-qtd', f);
    const atualizar = () => {
      const n = caixas().filter((c) => c.checked).length;
      if (qtd) { qtd.textContent = String(n); }
      if (btnLote) { btnLote.disabled = n === 0; }
      if (todos) { const v = visiveis(); todos.checked = v.length > 0 && v.every((c) => c.checked); }
    };
    f.addEventListener('change', (ev) => {
      if (ev.target === todos) { visiveis().forEach((c) => { c.checked = todos.checked; }); }
      atualizar();
    });
    let unica = null;
    f.addEventListener('click', (ev) => {
      const b = ev.target.closest('[data-saida-unica]');
      if (b) { unica = b; caixas().forEach((c) => { c.checked = false; }); }
      else if (ev.target.closest('[data-lote]')) { unica = null; }
    });
    f.addEventListener('submit', (ev) => {
      const n = caixas().filter((c) => c.checked).length;
      if (!unica && n > 1 && !window.confirm('Registrar a SAÍDA de ' + n + ' pessoas agora?')) { ev.preventDefault(); return; }
      unica = null;
    });
    const filtro = $('#filtro-presentes', f);
    if (filtro) {
      filtro.addEventListener('input', () => {
        const q = filtro.value.trim().toLowerCase();
        $$('li[data-busca]', f).forEach((li) => { li.hidden = q !== '' && li.dataset.busca.indexOf(q) === -1; });
        atualizar();
      });
      filtro.addEventListener('keydown', (ev) => { if (ev.key === 'Enter') { ev.preventDefault(); } });
    }
    atualizar();
  });

  /* ---------------- Armazenamento local da portaria (IndexedDB): fila offline + cache ---------------- */
  const BancoLocal = (function () {
    let db = null;
    function abrir() {
      if (db) { return Promise.resolve(db); }
      return new Promise((ok, erro) => {
        if (!window.indexedDB) { erro(new Error('IndexedDB indisponível')); return; }
        const req = indexedDB.open('sag-portaria', 2);
        req.onupgradeneeded = () => {
          const d = req.result;
          if (!d.objectStoreNames.contains('fila')) { d.createObjectStore('fila', { keyPath: 'registro_id' }); }
          if (!d.objectStoreNames.contains('cache')) { d.createObjectStore('cache'); }
          if (!d.objectStoreNames.contains('rascunho')) { d.createObjectStore('rascunho'); } // fotos anexadas antes de registrar
        };
        req.onsuccess = () => { db = req.result; ok(db); };
        req.onerror = () => erro(req.error);
      });
    }
    function op(loja, modo, fn) {
      return abrir().then((d) => new Promise((ok, erro) => {
        const tx = d.transaction(loja, modo);
        const r = fn(tx.objectStore(loja));
        tx.oncomplete = () => ok(r && 'result' in r ? r.result : undefined);
        tx.onerror = () => erro(tx.error);
      }));
    }
    return {
      fila: () => op('fila', 'readonly', (o) => o.getAll()).then((l) => (l || []).sort((a, b) => (a.criado_em < b.criado_em ? -1 : 1))),
      enfileirar: (item) => op('fila', 'readwrite', (o) => o.put(item)),
      remover: (id) => op('fila', 'readwrite', (o) => o.delete(id)),
      lerCache: () => op('cache', 'readonly', (o) => o.get('pacote')),
      gravarCache: (v) => op('cache', 'readwrite', (o) => o.put(v, 'pacote')),
      limparCache: () => op('cache', 'readwrite', (o) => o.delete('pacote')),
      lerRascunho: () => op('rascunho', 'readonly', (o) => o.get('fotos')),
      gravarRascunho: (v) => op('rascunho', 'readwrite', (o) => (v && v.length ? o.put(v, 'fotos') : o.delete('fotos'))),
    };
  })();

  // Ao sair do sistema: apaga o cache de cadastros do posto e avisa se há registros não sincronizados.
  $$('form[action$="/logout"]').forEach((f) => f.addEventListener('submit', (ev) => {
    if (f.dataset.ok) { return; }
    ev.preventDefault();
    BancoLocal.fila().then((fila) => {
      if (fila.length && !window.confirm('Há ' + fila.length + ' registro(s) da portaria ainda NÃO sincronizado(s) neste computador.\nEles continuam guardados e serão enviados no próximo acesso. Sair mesmo assim?')) { return 'cancelar'; }
      if (window.caches) { caches.delete('sag-paginas').catch(() => null); }
      return BancoLocal.limparCache().then(() => 'sair');
    }).catch(() => 'sair').then((r) => { if (r === 'sair') { f.dataset.ok = '1'; f.submit(); } });
  }));

  /* ---------------- Portaria ---------------- */
  const form = $('#form-portaria');
  if (!form) { return; }

  const LOCAL = form.dataset.local;
  const MAX_FOTOS = parseInt(form.dataset.maxFotos, 10) || 8;
  const MAX_MB = parseInt(form.dataset.maxMb, 10) || 8;
  const MAX_TOTAL_MB = parseInt(form.dataset.maxTotalMb, 10) || 100;
  // Formatos: JPG/PNG/WEBP (exibidos), HEIC (padrão Samsung "alta eficiência") e RAW/DNG (modo Pro).
  // O navegador nem sempre informa o tipo de HEIC/DNG; a extensão desempata e o servidor confere o conteúdo.
  const FORMATOS = { jpg: 'web', jpeg: 'web', png: 'web', webp: 'web', heic: 'HEIC', heif: 'HEIC', dng: 'RAW' };
  const formatoDe = (file) => {
    const t = (file.type || '').toLowerCase();
    if (t === 'image/jpeg' || t === 'image/png' || t === 'image/webp') { return 'web'; }
    if (t === 'image/heic' || t === 'image/heif') { return 'HEIC'; }
    if (t === 'image/x-adobe-dng' || t === 'image/dng') { return 'RAW'; }
    const ext = ((file.name || '').split('.').pop() || '').toLowerCase();
    return FORMATOS[ext] || null;
  };
  const mb = (b) => (b < 1048576 ? Math.max(1, Math.round(b / 1024)) + ' KB' : (b / 1048576).toFixed(1).replace('.', ',') + ' MB');
  const QR_PADRAO = /SAG1\.([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})\.[A-Za-z0-9_-]{16}$/;

  const st = {
    pessoaId: '', veiculoId: '', solicitacaoId: '', analise: null, padraoAplicadoPara: '', fotos: [],
    offline: false, cache: null, alertasVistos: '', solicitacaoQr: '',
  };

  const el = {
    buscaPessoa: $('#busca-pessoa'), resPessoa: $('#res-pessoa'), dicaPessoa: $('#dica-pessoa'),
    pessoaId: $('#pessoa_id'), pessoaCard: $('#pessoa-card'), solBox: $('#solicitacao-box'),
    buscaVeic: $('#busca-veiculo'), resVeic: $('#res-veiculo'), dicaVeic: $('#dica-veiculo'),
    veiculoId: $('#veiculo_id'), veiculoCard: $('#veiculo-card'), registroId: $('#registro_id'),
    destino: $('#destino'), resp: $('#responsavel'), respId: $('#responsavel_interno_id'),
    resResp: $('#res-responsavel'), respInfo: $('#responsavel-info'), obs: $('#observacoes'),
    padraoStatus: $('#padrao-status'), padraoPendente: $('#padrao-pendente'),
    bloqueios: $('#bloqueios'), avisos: $('#avisos'), btn: $('#btn-registrar'), btnTexto: $('#btn-registrar-texto'),
    criticos: $('#alertas-criticos'), criticosLista: $('#alertas-criticos-lista'), ciente: $('#ciente-alertas'),
    fotosEnvio: $('#fotos-envio'), fotosGrade: $('#fotos-grade'), fotosVazio: $('#fotos-vazio'), fotosContador: $('#fotos-contador'),
    faixa: $('#faixa-conexao'), faixaTexto: $('#faixa-conexao-texto'), btnSinc: $('#btn-sincronizar'),
  };

  const sentido = () => { const r = document.querySelector('input[name="sentido"]:checked'); return r ? r.value : 'entrada'; };
  const definirSentido = (v) => {
    const r = document.querySelector('input[name="sentido"][value="' + v + '"]');
    if (r && !r.checked) { r.checked = true; r.dispatchEvent(new Event('change', { bubbles: true })); }
  };

  // UUID v4 com crypto.getRandomValues (crypto.randomUUID exige HTTPS)
  function uuid() {
    const b = new Uint8Array(16);
    crypto.getRandomValues(b);
    b[6] = (b[6] & 0x0f) | 0x40; b[8] = (b[8] & 0x3f) | 0x80;
    const h = Array.from(b, (x) => x.toString(16).padStart(2, '0')).join('');
    return h.slice(0, 8) + '-' + h.slice(8, 12) + '-' + h.slice(12, 16) + '-' + h.slice(16, 20) + '-' + h.slice(20);
  }
  el.registroId.value = uuid();

  // Hora oficial do posto: Horário de Mato Grosso – UTC-4, corrigida pela diferença do relógio do servidor
  let defasagem = 0;
  function agoraMT() {
    const d = new Date(Date.now() + defasagem - 4 * 3600 * 1000);
    const z = (n) => String(n).padStart(2, '0');
    return d.getUTCFullYear() + '-' + z(d.getUTCMonth() + 1) + '-' + z(d.getUTCDate()) + ' ' + z(d.getUTCHours()) + ':' + z(d.getUTCMinutes()) + ':' + z(d.getUTCSeconds());
  }

  function bip(vezes) {
    try {
      const Ctx = window.AudioContext || window.webkitAudioContext;
      if (!Ctx) { return; }
      const ctx = new Ctx();
      for (let i = 0; i < (vezes || 2); i++) {
        const o = ctx.createOscillator(), g = ctx.createGain();
        o.type = 'square'; o.frequency.value = 880;
        g.gain.value = 0.08;
        o.connect(g); g.connect(ctx.destination);
        o.start(ctx.currentTime + i * 0.25); o.stop(ctx.currentTime + i * 0.25 + 0.15);
      }
      setTimeout(() => ctx.close(), 1200);
    } catch (e) { /* som é opcional */ }
  }

  const normalizar = (t) => String(t || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim();

  function lista(ul, itens, render, onPick) {
    ul.innerHTML = '';
    if (!itens.length) { ul.hidden = true; return false; }
    itens.forEach((it) => {
      const li = document.createElement('li');
      const b = document.createElement('button');
      b.type = 'button'; b.innerHTML = render(it);
      b.addEventListener('mousedown', (e) => e.preventDefault());
      b.addEventListener('click', () => { ul.hidden = true; onPick(it); });
      li.appendChild(b); ul.appendChild(li);
    });
    ul.hidden = false;
    return true;
  }

  /* --- conexão: modo offline --- */
  function marcarOffline(sim) {
    if (st.offline === sim) { return; }
    st.offline = sim;
    document.body.classList.toggle('portaria-offline', sim);
    atualizarFaixa();
    if (!sim) { sincronizar(); }
  }
  window.addEventListener('offline', () => marcarOffline(true));
  window.addEventListener('online', () => { verificarConexao(); });
  async function verificarConexao() {
    const r = await api('/saude');
    marcarOffline(!(r.ok && r.data && r.data.status === 'ok'));
  }
  setInterval(() => { if (st.offline || !navigator.onLine) { verificarConexao(); } }, 15000);

  let filaTamanho = 0, filaErros = [], sincronizando = false, precisaLogin = false;
  function atualizarFaixa() {
    if (!el.faixa) { return; }
    let txt = '';
    if (st.offline) {
      txt = 'SEM CONEXÃO com o servidor. Busque pelo nome e registre normalmente: os registros ficam guardados neste computador'
        + (filaTamanho ? ' (' + filaTamanho + ' na fila)' : '') + '. Não feche nem recarregue esta página.';
    } else if (precisaLogin && filaTamanho) {
      txt = filaTamanho + ' registro(s) aguardando sincronização. A sessão expirou: entre novamente para enviar.';
    } else if (sincronizando) {
      txt = 'Sincronizando ' + filaTamanho + ' registro(s) feitos sem conexão…';
    } else if (filaTamanho) {
      txt = filaTamanho + ' registro(s) feitos sem conexão aguardando envio.';
    }
    if (filaErros.length) {
      txt += (txt ? ' ' : '') + filaErros.length + ' registro(s) recusado(s) na sincronização: ' + filaErros.map((x) => x.resumo + ' — ' + x.erro).join(' | ');
    }
    el.faixa.hidden = !txt;
    el.faixa.classList.toggle('offline', st.offline);
    el.faixaTexto.textContent = txt;
    el.btnSinc.hidden = st.offline || !filaTamanho || sincronizando;
  }
  el.btnSinc.addEventListener('click', () => sincronizar());

  async function contarFila() {
    try {
      const f = await BancoLocal.fila();
      filaTamanho = f.filter((x) => !x.erro).length;
      filaErros = f.filter((x) => x.erro);
    } catch (e) { filaTamanho = 0; }
    atualizarFaixa();
  }

  /* --- cache local para operar sem conexão --- */
  async function carregarCache() {
    try { st.cache = await BancoLocal.lerCache(); } catch (e) { st.cache = null; }
    if (st.cache && typeof st.cache.defasagem === 'number') { defasagem = st.cache.defasagem; }
    const velho = !st.cache || st.cache.local_id !== LOCAL || (Date.now() - (st.cache.salvo_em || 0)) > 10 * 60 * 1000;
    if (!velho || st.offline) { return; }
    const t0 = Date.now();
    const r = await api('/api/portaria/cache?local_id=' + encodeURIComponent(LOCAL), { semRedirecionar: true });
    if (r.ok && r.data && Array.isArray(r.data.pessoas)) {
      const servidor = Date.parse(r.data.gerado_em.replace(' ', 'T') + '-04:00');
      if (!isNaN(servidor)) { defasagem = servidor - (t0 + Date.now()) / 2; }
      st.cache = Object.assign(r.data, { salvo_em: Date.now(), defasagem: defasagem });
      try { await BancoLocal.gravarCache(st.cache); } catch (e) { /* navegador sem IndexedDB: segue só online */ }
    } else if (r.rede) {
      marcarOffline(true);
    }
  }
  function cachePessoa(id) { return st.cache ? st.cache.pessoas.find((p) => p.id === id) : null; }
  function presenteNoCache(id) { return !!(st.cache && st.cache.presentes.indexOf(id) !== -1); }
  function buscarNoCache(lista, q, campos) {
    const n = normalizar(q);
    return (lista || []).filter((x) => campos.some((c) => normalizar(x[c]).indexOf(n) !== -1)).slice(0, 20);
  }

  /* --- busca de pessoa --- */
  el.buscaPessoa.addEventListener('input', debounce(async () => {
    const q = el.buscaPessoa.value.trim();
    if (QR_PADRAO.test(q)) { el.buscaPessoa.value = ''; lerQr(q); return; }
    if (q.length < 2) { el.resPessoa.hidden = true; el.dicaPessoa.textContent = 'Digite pelo menos 2 caracteres para buscar.'; return; }
    let itens = [];
    let offline = st.offline;
    if (!offline) {
      const r = await api('/api/pessoas/busca?q=' + encodeURIComponent(q));
      if (r.rede) { marcarOffline(true); offline = true; }
      itens = (r.ok && Array.isArray(r.data)) ? r.data : [];
    }
    if (offline) {
      itens = buscarNoCache(st.cache && st.cache.pessoas, q, ['nome', 'matricula', 'empresa'])
        .map((p) => Object.assign({ cpf_exibicao: '' }, p));
    }
    const tem = lista(el.resPessoa, itens, (p) =>
      '<strong>' + esc(p.nome) + '</strong>' + (p.usuario ? ' <span class="badge badge-neutro">usuário</span>' : '') +
      (offline && presenteNoCache(p.id) ? ' <span class="badge badge-info">dentro</span>' : '') +
      '<small>' + esc([p.cpf_exibicao || p.matricula || '—', p.categoria_label, p.empresa].filter(Boolean).join(' · ')) + '</small>',
    (p) => selecionarPessoa(p.id));
    el.dicaPessoa.textContent = tem ? '' : (offline ? (st.cache ? 'Sem conexão: nenhuma pessoa no cadastro local (CPF só funciona online).' : 'Sem conexão e sem cadastro local neste computador.') : 'Nenhuma pessoa encontrada. Use “Cadastro rápido”.');
  }, 250));
  el.buscaPessoa.addEventListener('blur', () => setTimeout(() => { el.resPessoa.hidden = true; }, 150));

  function selecionarPessoa(id, solicitacaoId) {
    st.pessoaId = id; st.solicitacaoId = solicitacaoId || '';
    el.pessoaId.value = id;
    el.buscaPessoa.value = '';
    el.dicaPessoa.textContent = '';
    el.ciente.checked = false;
    $('#busca-pessoa-box').hidden = true;
    analisar();
  }

  function trocarPessoa(foco) {
    st.pessoaId = ''; st.solicitacaoId = ''; st.analise = null; st.solicitacaoQr = '';
    el.pessoaId.value = '';
    el.ciente.checked = false;
    el.pessoaCard.hidden = true; el.solBox.hidden = true;
    $('#busca-pessoa-box').hidden = false;
    el.dicaPessoa.textContent = 'Digite pelo menos 2 caracteres para buscar.';
    el.padraoStatus.textContent = ''; el.padraoPendente.hidden = true;
    atualizarEstado();
    if (foco !== false) { el.buscaPessoa.focus(); }
  }

  /* --- busca de veículo --- */
  el.buscaVeic.addEventListener('input', debounce(async () => {
    const q = el.buscaVeic.value.trim();
    if (q.length < 2) { el.resVeic.hidden = true; el.dicaVeic.textContent = 'Pessoa a pé? Deixe em branco.'; return; }
    let itens = [];
    if (!st.offline) {
      const r = await api('/api/veiculos/busca?q=' + encodeURIComponent(q));
      if (r.rede) { marcarOffline(true); }
      itens = (r.ok && Array.isArray(r.data)) ? r.data : [];
    }
    if (st.offline) {
      const placa = q.replace(/[^A-Za-z0-9]/g, '');
      itens = buscarNoCache(st.cache && st.cache.veiculos, placa || q, ['placa', 'marca_modelo', 'empresa', 'tag']);
    }
    const tem = lista(el.resVeic, itens, (v) =>
      '<strong class="mono">' + esc(v.placa) + '</strong><small>' + esc([v.tipo, v.marca_modelo, v.cor, v.empresa, v.tag ? 'TAG ' + v.tag : ''].filter(Boolean).join(' · ')) + '</small>',
    (v) => selecionarVeiculo(v));
    el.dicaVeic.textContent = tem ? '' : 'Nenhum veículo encontrado.';
  }, 250));
  el.buscaVeic.addEventListener('blur', () => setTimeout(() => { el.resVeic.hidden = true; }, 150));

  function selecionarVeiculo(v) {
    st.veiculoId = v.id; el.veiculoId.value = v.id;
    el.buscaVeic.value = ''; el.dicaVeic.textContent = '';
    $('#busca-veiculo-box').hidden = true;
    renderVeiculo(v);
    if (st.pessoaId) { analisar(); }
  }
  function trocarVeiculo(foco) {
    st.veiculoId = ''; el.veiculoId.value = ''; el.veiculoCard.hidden = true;
    $('#busca-veiculo-box').hidden = false; el.dicaVeic.textContent = 'Pessoa a pé? Deixe em branco.';
    if (st.pessoaId) { analisar(); }
    if (foco) { el.buscaVeic.focus(); }
  }
  function renderVeiculo(v) {
    el.veiculoCard.innerHTML =
      '<div class="selecionado-topo"><dl class="dados">' +
      '<div><dt>Placa</dt><dd class="mono">' + esc(v.placa) + '</dd></div>' +
      '<div><dt>Modelo / Cor</dt><dd>' + esc([v.marca_modelo, v.cor].filter(Boolean).join(' · ') || '—') + '</dd></div>' +
      '<div><dt>Empresa</dt><dd>' + esc(v.empresa || '—') + '</dd></div></dl>' +
      '<button type="button" class="btn btn-fantasma btn-sm" id="btn-trocar-veiculo">Trocar</button></div>';
    el.veiculoCard.hidden = false;
    $('#btn-trocar-veiculo').addEventListener('click', () => trocarVeiculo(true));
  }

  /* --- responsável interno --- */
  el.resp.addEventListener('input', debounce(async () => {
    el.respId.value = ''; el.respInfo.hidden = false;
    el.respInfo.textContent = el.resp.value.trim() ? 'Texto livre — não vinculado a uma pessoa cadastrada.' : '';
    const q = el.resp.value.trim();
    if (q.length < 2 || st.offline) { el.resResp.hidden = true; return; }
    const r = await api('/api/responsaveis/busca?q=' + encodeURIComponent(q));
    if (r.rede) { marcarOffline(true); return; }
    const itens = (r.ok && Array.isArray(r.data)) ? r.data : [];
    lista(el.resResp, itens, (p) =>
      '<strong>' + esc(p.nome) + '</strong>' + (Number(p.usuario) ? ' <span class="badge badge-neutro">usuário</span>' : '') +
      '<small>' + esc([p.empresa, p.matricula, p.telefone_principal, p.email_principal].filter(Boolean).join(' · ') || 'Sem contato') + '</small>',
    (p) => definirResponsavel(p));
  }, 250));
  el.resp.addEventListener('blur', () => setTimeout(() => { el.resResp.hidden = true; }, 150));

  function definirResponsavel(p) {
    if (!p) { return; }
    el.resp.value = p.nome; el.respId.value = p.id;
    const contato = [p.empresa, p.telefone_principal || 'Sem telefone', p.email_principal].filter(Boolean).join(' · ');
    el.respInfo.textContent = contato + ((!p.telefone_principal && !p.email_principal) ? ' — responsável sem contato cadastrado' : '');
    el.respInfo.hidden = false;
  }

  /* --- análise no servidor (ou pelo cadastro local, sem conexão) --- */
  async function analisar() {
    if (!st.pessoaId) { atualizarEstado(); return; }
    el.btn.disabled = true;
    if (!st.offline) {
      const q = new URLSearchParams({
        pessoa_id: st.pessoaId, local_id: LOCAL, sentido: sentido(),
        veiculo_id: st.veiculoId, solicitacao_id: st.solicitacaoId,
      });
      const r = await api('/api/portaria/analise?' + q.toString());
      if (r.rede) { marcarOffline(true); }
      else if (!r.ok || !r.data) { toast((r.data && r.data.erro) || 'Falha ao consultar a pessoa.', 'erro'); atualizarEstado(); return; }
      else {
        st.analise = r.data;
        renderPessoa(r.data);
        renderSolicitacoes(r.data);
        if (r.data.veiculo) { renderVeiculo(r.data.veiculo); }
        aplicarPadrao(r.data, false);
        atualizarEstado();
        return;
      }
    }
    st.analise = analiseOffline();
    if (!st.analise) { toast('Sem conexão e pessoa fora do cadastro local.', 'erro'); trocarPessoa(); return; }
    renderPessoa(st.analise);
    el.solBox.hidden = true;
    atualizarEstado();
  }

  function analiseOffline() {
    const p = cachePessoa(st.pessoaId);
    if (!p) { return null; }
    const s = sentido(), dentro = presenteNoCache(p.id);
    const avisos = ['Sem conexão: solicitação, SSMA e documentos serão conferidos quando o registro for sincronizado.'];
    const alertas = [];
    if (s === 'entrada' && p.doc_vencido) { alertas.push('Documento(s) VENCIDO(S) no último cadastro conhecido.'); }
    if (s === 'entrada' && dentro) { avisos.push('Consta como PRESENTE neste local (saída não registrada).'); }
    if (s === 'saida' && !dentro) { avisos.push('Não consta como presente no cadastro local — confira antes de registrar a saída.'); }
    if (s === 'entrada' && p.categoria !== 'colaborador') {
      const sol = (st.cache.solicitacoes || []).find((x) => x.pessoa_id === p.id || (st.solicitacaoQr && x.id === st.solicitacaoQr) || normalizar(x.visitante_nome) === normalizar(p.nome));
      if (sol) { st.solicitacaoId = sol.id; } else { alertas.push('Sem solicitação aprovada para hoje no cadastro local.'); }
    }
    return {
      offline: true, pessoa: Object.assign({ categoria_label: p.categoria_label }, p), documentos: [], bloqueios: [],
      alertas: alertas, avisos: avisos, presente_desde: dentro ? 'antes da queda de conexão' : null, exige_solicitacao: false,
    };
  }

  function renderPessoa(a) {
    const p = a.pessoa;
    const docs = (a.documentos || []).map((d) => {
      const cls = d.status === 'vencido' ? 'perigo' : (d.status === 'proximo_vencimento' ? 'aviso' : 'sucesso');
      return '<span class="badge badge-' + cls + '" title="Validade ' + esc(d.validade_fmt) + '">' + esc(d.rotulo) + ' · ' + esc(d.validade_fmt) + '</span>';
    }).join(' ');
    el.pessoaCard.innerHTML =
      '<div class="selecionado-topo"><dl class="dados">' +
      '<div><dt>Nome</dt><dd><strong>' + esc(p.nome) + '</strong></dd></div>' +
      '<div><dt>Tipo</dt><dd><span class="badge badge-neutro">' + esc(p.categoria_label) + '</span></dd></div>' +
      '<div><dt>Empresa</dt><dd>' + esc(p.empresa || '—') + '</dd></div>' +
      (a.offline ? '<div><dt>Origem</dt><dd><span class="badge badge-aviso">cadastro local (sem conexão)</span></dd></div>'
        : '<div><dt>Telefone</dt><dd>' + esc(p.telefone_principal || p.telefone || '—') + '</dd></div>' +
          '<div class="largo"><dt>Documentos</dt><dd>' + (docs || '<span class="mudo">Nenhum documento cadastrado</span>') + '</dd></div>') +
      (a.presente_desde ? '<div class="largo"><dt>Situação</dt><dd><span class="badge badge-info">Presente neste local desde ' + esc(a.presente_desde) + '</span></dd></div>' : '') +
      '</dl><button type="button" class="btn btn-fantasma btn-sm" id="btn-trocar-pessoa">Trocar</button></div>';
    el.pessoaCard.hidden = false;
    $('#btn-trocar-pessoa').addEventListener('click', () => trocarPessoa());
  }

  function itemSolicitacao(s, marcada) {
    const aprovada = s.status === 'aprovada';
    return '<label class="sol-item' + (aprovada ? '' : ' desabilitada') + '">' +
      '<input type="radio" name="solicitacao_id" value="' + esc(s.id) + '"' + (marcada ? ' checked' : '') + (aprovada ? '' : ' disabled') + '>' +
      '<span><strong>' + esc(s.visitante_nome) + '</strong> <span class="badge badge-' + (aprovada ? 'sucesso' : 'aviso') + '">' + esc(s.status_label) + '</span>' +
      (s.risco ? ' <span class="badge badge-perigo">Risco</span>' : '') +
      (s.id === st.solicitacaoQr ? ' <span class="badge badge-info">QR</span>' : '') +
      '<small>' + esc([s.area ? 'Área: ' + s.area : '', s.responsavel ? 'Resp.: ' + s.responsavel : '', s.empresa].filter(Boolean).join(' · ')) + '</small></span></label>';
  }

  function renderSolicitacoes(a) {
    if (!a.exige_solicitacao || sentido() !== 'entrada') { el.solBox.hidden = true; el.solBox.innerHTML = ''; return; }
    const s = a.solicitacoes;
    const marcada = st.solicitacaoId || a.solicitacao_sugerida || '';
    const principais = s.vinculadas.concat(s.candidatas);
    let html = '<h3 class="rotulo-caps">Solicitação de acesso de hoje</h3>';
    if (principais.length) {
      html += principais.map((x) => itemSolicitacao(x, x.id === marcada)).join('');
    } else {
      html += '<p class="dica">Nenhuma solicitação aprovada para hoje foi encontrada para esta pessoa (por CPF ou nome).</p>';
    }
    if (s.outras.length) {
      html += '<details' + (s.outras.some((x) => x.id === marcada) ? ' open' : '') + '><summary>Outras solicitações aprovadas hoje sem vínculo (' + s.outras.length + ')</summary>' +
        s.outras.map((x) => itemSolicitacao(x, x.id === marcada)).join('') + '</details>';
    }
    html += '<p class="dica"><a href="' + esc(BASE) + '/solicitacoes" target="_blank" rel="noopener">Abrir solicitações</a></p>';
    el.solBox.innerHTML = html;
    el.solBox.hidden = false;
    $$('input[name="solicitacao_id"]', el.solBox).forEach((r) => r.addEventListener('change', () => {
      st.solicitacaoId = r.value; analisar();
    }));
  }

  /* --- dados padrão do cadastro --- */
  function aplicarPadrao(a, forcar) {
    const p = a.pessoa;
    const d = (p.destino_padrao || '').trim(), r = (p.responsavel_interno_padrao || '').trim(), o = (p.observacoes_portaria_padrao || '').trim();
    if (!d && !r && !o) { el.padraoStatus.textContent = 'Sem dados padrão cadastrados para esta pessoa.'; el.padraoPendente.hidden = true; return; }
    if (!forcar && st.padraoAplicadoPara === p.id) { return; }
    // Responsável pré-preenchido com o próprio usuário logado não conta como "digitado"
    const respVazio = !el.resp.value.trim() || (!!window.__respPadraoUsuario && el.respId.value === window.__respPadraoUsuario);
    const vazios = !el.destino.value.trim() && !el.obs.value.trim() && respVazio;
    if (forcar || vazios) {
      el.destino.value = d; el.obs.value = o;
      if (r) {
        el.resp.value = r; el.respId.value = '';
        if (a.responsavel_padrao) { definirResponsavel(a.responsavel_padrao); }
        else { el.respInfo.hidden = false; el.respInfo.textContent = 'Texto livre — não vinculado a uma pessoa cadastrada.'; }
      }
      el.padraoStatus.textContent = 'Dados preenchidos a partir do cadastro da pessoa.';
      el.padraoPendente.hidden = true;
    } else {
      el.padraoPendente.hidden = false;
    }
    st.padraoAplicadoPara = p.id;
  }
  $('#btn-usar-padrao').addEventListener('click', () => { if (st.analise) { aplicarPadrao(st.analise, true); } });
  [el.destino, el.obs].forEach((i) => i.addEventListener('input', () => { el.padraoStatus.textContent = ''; }));

  /* --- estado do botão, bloqueios e alertas críticos --- */
  function atualizarEstado() {
    const s = sentido();
    el.btn.classList.toggle('entrada', s === 'entrada');
    el.btn.classList.toggle('saida', s === 'saida');
    el.btnTexto.textContent = (st.offline ? 'GUARDAR ' : 'CONFIRMAR ') + (s === 'entrada' ? 'ENTRADA' : 'SAÍDA') + (st.offline ? ' (sem conexão)' : '');
    const a = st.analise;
    const ativo = !!(a && st.pessoaId);
    const bloqueios = ativo ? a.bloqueios : [];
    const alertas = ativo && s === 'entrada' ? (a.alertas || []) : [];
    const avisos = ativo ? a.avisos : [];
    el.bloqueios.hidden = !bloqueios.length;
    el.bloqueios.innerHTML = bloqueios.length ? '<strong>' + (s === 'entrada' ? 'Entrada bloqueada' : 'Saída não permitida') + '</strong><ul>' + bloqueios.map((b) => '<li>' + esc(b) + '</li>').join('') + '</ul>' : '';
    el.criticos.hidden = !alertas.length || bloqueios.length > 0;
    el.criticosLista.innerHTML = alertas.map((b) => '<li>' + esc(b) + '</li>').join('');
    if (!alertas.length) { el.ciente.checked = false; }
    const chave = alertas.join('|');
    if (alertas.length && !bloqueios.length && chave !== st.alertasVistos) { bip(2); }
    st.alertasVistos = chave;
    el.avisos.hidden = !avisos.length;
    el.avisos.innerHTML = avisos.length ? '<strong>Atenção</strong><ul>' + avisos.map((b) => '<li>' + esc(b) + '</li>').join('') + '</ul>' : '';
    const pendenteCiencia = alertas.length > 0 && !el.ciente.checked;
    el.btn.disabled = !st.pessoaId || !a || bloqueios.length > 0 || pendenteCiencia;
    el.btn.classList.toggle('aguardando-ciencia', pendenteCiencia && bloqueios.length === 0);
  }
  el.ciente.addEventListener('change', atualizarEstado);
  $$('input[name="sentido"]').forEach((r) => r.addEventListener('change', () => { st.solicitacaoId = st.solicitacaoQr || ''; el.ciente.checked = false; analisar(); atualizarEstado(); }));

  /* --- fotos (acumula câmera + galeria em um único input) --- */
  function sincronizarFotos() {
    const dt = new DataTransfer();
    st.fotos.forEach((f) => dt.items.add(f.file));
    el.fotosEnvio.files = dt.files;
    el.fotosGrade.innerHTML = '';
    const opcoes = $('#tpl-tipos-foto').innerHTML;
    st.fotos.forEach((f, i) => {
      const fig = document.createElement('figure');
      fig.className = 'foto-item';
      const fmt = formatoDe(f.file);
      fig.innerHTML = (fmt === 'web' ? '<img alt="Prévia ' + (i + 1) + '">' : '<div class="foto-arquivo"><strong>' + esc(fmt) + '</strong><span>' + esc(f.file.name) + '</span></div>') +
        '<button type="button" class="foto-remover" aria-label="Remover foto">&times;</button>' +
        '<select name="fotos_tipo[]" form="form-portaria">' + opcoes + '</select><figcaption>' + esc(mb(f.file.size)) + '</figcaption>';
      if (fmt === 'web') { fig.querySelector('img').src = f.url; }
      const sel = fig.querySelector('select'); sel.value = f.tipo;
      sel.addEventListener('change', () => { f.tipo = sel.value; });
      fig.querySelector('.foto-remover').addEventListener('click', () => {
        URL.revokeObjectURL(f.url); st.fotos.splice(i, 1); sincronizarFotos(); salvarRascunho();
      });
      el.fotosGrade.appendChild(fig);
    });
    el.fotosVazio.hidden = st.fotos.length > 0;
    const total = st.fotos.reduce((s, f) => s + f.file.size, 0);
    el.fotosContador.textContent = '(' + st.fotos.length + '/' + MAX_FOTOS + ' · ' + mb(total) + ' de ' + MAX_TOTAL_MB + ' MB · JPG/PNG/WEBP/HEIC/RAW até ' + MAX_MB + ' MB cada)';
  }
  function adicionarFotos(files, tipo) {
    Array.from(files || []).forEach((file) => {
      const total = st.fotos.reduce((s, f) => s + f.file.size, 0);
      if (st.fotos.length >= MAX_FOTOS) { toast('Máximo de ' + MAX_FOTOS + ' fotos por registro.', 'erro'); return; }
      if (!formatoDe(file)) { toast('Formato inválido: ' + file.name + ' (use JPG, PNG, WEBP, HEIC ou RAW/DNG).', 'erro'); return; }
      if (file.size > MAX_MB * 1024 * 1024) { toast(file.name + ' tem ' + mb(file.size) + '; o limite é ' + MAX_MB + ' MB por foto.', 'erro'); return; }
      if (total + file.size > MAX_TOTAL_MB * 1024 * 1024) { toast('As fotos passariam de ' + MAX_TOTAL_MB + ' MB nesta movimentação.', 'erro'); return; }
      st.fotos.push({ file: file, url: URL.createObjectURL(file), tipo: tipo });
    });
    sincronizarFotos();
    salvarRascunho();
  }

  // Rascunho: cada foto anexada já fica guardada neste computador (IndexedDB). Se a página fechar,
  // recarregar ou a conexão cair antes do registro, as fotos voltam ao abrir a portaria de novo.
  function salvarRascunho() {
    BancoLocal.gravarRascunho(st.fotos.map((f) => ({ file: f.file, tipo: f.tipo }))).catch(() => null);
  }
  async function restaurarRascunho() {
    let r = null;
    try { r = await BancoLocal.lerRascunho(); } catch (e) { r = null; }
    if (!r || !r.length || st.fotos.length) { return; }
    r.forEach((x) => { if (x.file) { st.fotos.push({ file: x.file, url: URL.createObjectURL(x.file), tipo: x.tipo || 'outra' }); } });
    sincronizarFotos();
    toast(r.length + ' foto(s) anexada(s) antes foram recuperadas deste computador.', 'aviso');
  }

  // Envio com barra de progresso (fotos grandes em LTE/Starlink). Status 0 = sem conexão ou tempo esgotado.
  function enviar(fd, aoProgredir) {
    return new Promise((ok) => {
      const x = new XMLHttpRequest();
      x.open('POST', BASE + '/portaria/registrar');
      x.withCredentials = true;
      x.timeout = 15 * 60 * 1000;
      x.setRequestHeader('Accept', 'application/json');
      x.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
      x.setRequestHeader('X-CSRF-Token', CSRF);
      if (aoProgredir && x.upload) { x.upload.onprogress = (e) => { if (e.lengthComputable) { aoProgredir(Math.round(e.loaded * 100 / e.total)); } }; }
      const fim = () => { let d = null; try { d = JSON.parse(x.responseText); } catch (e) { d = null; } ok({ status: x.status, data: d }); };
      x.onload = fim;
      x.onerror = x.ontimeout = x.onabort = () => ok({ status: 0, data: null });
      x.send(fd);
    });
  }
  $('#foto-camera').addEventListener('change', (e) => { adicionarFotos(e.target.files, 'pessoa'); e.target.value = ''; });
  $('#foto-galeria').addEventListener('change', (e) => { adicionarFotos(e.target.files, 'outra'); e.target.value = ''; });

  function limparFormulario() {
    st.fotos.forEach((f) => URL.revokeObjectURL(f.url));
    st.fotos = []; sincronizarFotos(); salvarRascunho();
    if (st.veiculoId) { trocarVeiculo(false); }
    el.destino.value = ''; el.obs.value = '';
    el.respId.value = window.__respPadraoUsuario || '';
    if (!window.__respPadraoUsuario) { el.resp.value = ''; el.respInfo.hidden = true; }
    st.padraoAplicadoPara = '';
    el.registroId.value = uuid();
    trocarPessoa();
  }

  /* --- envio: online por fetch; sem conexão vai para a fila local --- */
  form.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    if (el.btn.disabled) { return; }
    if (form.dataset.exigirDestino === '1' && sentido() === 'entrada' && !el.destino.value.trim() && !st.offline) {
      toast('Informe o destino/setor.', 'erro'); el.destino.focus(); return;
    }
    el.btn.disabled = true;
    el.btnTexto.textContent = st.offline ? 'Guardando...' : 'Registrando...';
    const fd = new FormData(form);
    if (!st.offline) {
      const temFotos = st.fotos.length > 0;
      const r = await enviar(fd, temFotos ? (pct) => { el.btnTexto.textContent = 'Enviando fotos… ' + pct + '%'; } : null);
      if (r.status >= 200 && r.status < 300) {
        try { await BancoLocal.gravarRascunho([]); } catch (e) { /* noop */ }
        window.location.href = BASE + '/portaria'; return;
      }
      if (r.status === 422 || r.status === 400 || r.status === 413) {
        const d = r.data;
        toast((d && d.erro) || 'Registro recusado.', 'erro');
        el.bloqueios.hidden = false;
        el.bloqueios.innerHTML = '<strong>Não registrado</strong><ul><li>' + esc((d && d.erro) || 'Registro recusado pelo servidor.') + '</li></ul>';
        analisar();
        return;
      }
      if (r.status === 401 || r.status === 403) { precisaLogin = true; }
      else { marcarOffline(true); toast('A conexão caiu durante o envio: o registro e as fotos ficam guardados e sobem quando a internet voltar.', 'aviso'); }
    }
    await guardarNaFila(fd);
  });

  async function guardarNaFila(fd) {
    const campos = {}, fotos = [];
    const tipos = fd.getAll('fotos_tipo[]');
    fd.forEach((v, k) => {
      if (k === 'fotos[]' || k === 'fotos_tipo[]' || k === '_csrf' || k === '_idem') { return; }
      campos[k] = v;
    });
    st.fotos.forEach((f, i) => fotos.push({ blob: f.file, nome: f.file.name, tipo: tipos[i] || f.tipo }));
    const p = st.analise && st.analise.pessoa;
    const item = {
      registro_id: campos.registro_id || uuid(), criado_em: agoraMT(), campos: campos, fotos: fotos,
      resumo: (sentido() === 'entrada' ? 'Entrada' : 'Saída') + ' de ' + (p ? p.nome : 'pessoa'),
    };
    item.campos.registro_id = item.registro_id;
    if (!item.campos.solicitacao_id && st.solicitacaoId) { item.campos.solicitacao_id = st.solicitacaoId; }
    try {
      await BancoLocal.enfileirar(item);
    } catch (e) {
      toast('Não foi possível guardar o registro neste navegador. Anote em papel.', 'erro');
      atualizarEstado();
      return;
    }
    if (st.cache) {
      const lst = st.cache.presentes;
      const i = lst.indexOf(st.pessoaId);
      if (sentido() === 'entrada' && i === -1) { lst.push(st.pessoaId); }
      if (sentido() === 'saida' && i !== -1) { lst.splice(i, 1); }
    }
    toast(item.resumo + ' guardada sem conexão. Será enviada automaticamente.', 'aviso');
    limparFormulario();
    await contarFila();
  }

  async function sincronizar() {
    if (sincronizando || st.offline) { return; }
    let fila = [];
    try { fila = (await BancoLocal.fila()).filter((x) => !x.erro); } catch (e) { return; }
    if (!fila.length) { contarFila(); return; }
    sincronizando = true; precisaLogin = false; atualizarFaixa();
    let enviados = 0, n = 0;
    for (const item of fila) {
      n++;
      const fd = new FormData();
      Object.keys(item.campos).forEach((k) => fd.append(k, item.campos[k]));
      fd.append('sincronizacao', '1');
      fd.append('offline_em', item.criado_em);
      (item.fotos || []).forEach((f) => { fd.append('fotos[]', f.blob, f.nome || 'foto.jpg'); fd.append('fotos_tipo[]', f.tipo || 'outra'); });
      const comFotos = (item.fotos || []).length;
      const r = await enviar(fd, (pct) => {
        el.faixaTexto.textContent = 'Enviando registro ' + n + ' de ' + fila.length + (comFotos ? ' (' + comFotos + ' foto(s)) — ' + pct + '%' : '') + '…';
        el.faixa.hidden = false;
      });
      if (r.status === 0) { marcarOffline(true); break; }
      if (r.status >= 200 && r.status < 300) { await BancoLocal.remover(item.registro_id); enviados++; continue; }
      if (r.status === 401 || r.status === 403) { precisaLogin = true; break; }
      if (r.status === 422 || r.status === 400 || r.status === 413) {
        const d = r.data;
        item.erro = (d && d.erro) || (r.status === 413 ? 'Fotos maiores que o limite do servidor' : 'Recusado pelo servidor');
        await BancoLocal.enfileirar(item);
        continue;
      }
      break; // erro 5xx: tenta de novo mais tarde
    }
    sincronizando = false;
    await contarFila();
    if (enviados) { toast(enviados + ' registro(s) feitos sem conexão foram sincronizados.', 'sucesso'); }
  }
  setInterval(() => { if (filaTamanho && !st.offline) { sincronizar(); } }, 20000);

  /* --- QR do convite: leitor USB, câmera ou foto --- */
  async function lerQr(token) {
    token = String(token || '').trim();
    const m = token.match(QR_PADRAO);
    if (!m) { toast('Código de convite inválido.', 'erro'); return; }
    const dlg = $('#dlg-qr'); if (dlg && dlg.open) { dlg.close(); }
    definirSentido('entrada');
    if (!st.offline) {
      const r = await api('/api/portaria/qr?t=' + encodeURIComponent(token));
      if (!r.rede) {
        if (!r.data || (!r.ok && r.status !== 409)) { bip(1); toast((r.data && r.data.erro) || 'QR inválido.', 'erro'); return; }
        const d = r.data;
        if (d.erro) { bip(1); toast(d.erro, 'erro'); }
        else { toast('Convite de ' + d.solicitacao.visitante_nome + ' lido.', 'sucesso'); }
        st.solicitacaoQr = d.solicitacao.id;
        if (d.pessoa_id) { selecionarPessoa(d.pessoa_id, d.solicitacao.id); return; }
        const f = $('#form-rapido-pessoa');
        if (f && d.cadastro) {
          f.nome.value = d.cadastro.nome || ''; f.cpf.value = d.cadastro.cpf || '';
          f.empresa.value = d.cadastro.empresa || ''; f.categoria.value = d.cadastro.categoria || 'visitante';
          f.dataset.solicitacao = d.solicitacao.id;
          f.solicitacao_id.value = d.solicitacao.id;
          $('#cpf-da-solicitacao').hidden = !(d.cadastro.cpf_na_solicitacao && !d.cadastro.cpf);
          $('#dlg-pessoa').showModal();
          toast('Primeira visita: confira os dados e conclua o cadastro rápido.', 'aviso');
        }
        return;
      }
      marcarOffline(true);
    }
    const sol = st.cache && (st.cache.solicitacoes || []).find((x) => x.id === m[1]);
    if (!sol) { toast('Sem conexão: convite não está no cadastro local. Busque a pessoa pelo nome.', 'erro'); return; }
    st.solicitacaoQr = sol.id;
    const p = sol.pessoa_id ? cachePessoa(sol.pessoa_id) : (st.cache.pessoas || []).find((x) => normalizar(x.nome) === normalizar(sol.visitante_nome));
    if (p) { selecionarPessoa(p.id, sol.id); return; }
    toast('Sem conexão: ' + sol.visitante_nome + ' ainda não tem cadastro. Aguarde a conexão para o cadastro rápido.', 'erro');
  }

  // Leitor USB funciona como teclado: captura sequências rápidas fora de campos de texto
  let bufferQr = '', ultimaTecla = 0;
  document.addEventListener('keydown', (ev) => {
    const alvo = ev.target;
    const editavel = alvo && (alvo.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(alvo.tagName)) && alvo.type !== 'checkbox' && alvo.type !== 'radio';
    if (editavel) { return; }
    const agora = Date.now();
    if (agora - ultimaTecla > 120) { bufferQr = ''; }
    ultimaTecla = agora;
    if (ev.key === 'Enter' && QR_PADRAO.test(bufferQr)) { ev.preventDefault(); const t = bufferQr; bufferQr = ''; lerQr(t); return; }
    if (ev.key && ev.key.length === 1 && !ev.ctrlKey && !ev.altKey && !ev.metaKey) { bufferQr = (bufferQr + ev.key).slice(-80); }
  }, true);

  // Câmera (HTTPS) com BarcodeDetector/jsQR; em HTTP, foto do QR decodificada no navegador
  let fluxo = null, laco = null;
  function pararCamera() {
    if (laco) { cancelAnimationFrame(laco); laco = null; }
    if (fluxo) { fluxo.getTracks().forEach((t) => t.stop()); fluxo = null; }
  }
  async function decodificar(fonte, w, h) {
    if ('BarcodeDetector' in window) {
      try {
        const det = new window.BarcodeDetector({ formats: ['qr_code'] });
        const r = await det.detect(fonte);
        if (r.length) { return r[0].rawValue; }
        if (!(fonte instanceof HTMLCanvasElement)) { return null; }
      } catch (e) { /* cai para o jsQR */ }
    }
    await carregarScript('jsqr-1.4.0.js');
    const cv = document.createElement('canvas');
    const escala = Math.min(1, 1024 / Math.max(w, h));
    cv.width = Math.round(w * escala); cv.height = Math.round(h * escala);
    const ctx = cv.getContext('2d', { willReadFrequently: true });
    ctx.drawImage(fonte, 0, 0, cv.width, cv.height);
    const img = ctx.getImageData(0, 0, cv.width, cv.height);
    const r = window.jsQR(img.data, cv.width, cv.height, { inversionAttempts: 'attemptBoth' });
    return r ? r.data : null;
  }
  async function abrirQr() {
    const dlg = $('#dlg-qr'), status = $('#qr-status'), caixa = $('#qr-video-box'), video = $('#qr-video');
    if (!dlg) { return; }
    $('#qr-manual').value = '';
    dlg.showModal();
    const podeVideo = window.isSecureContext && navigator.mediaDevices && navigator.mediaDevices.getUserMedia;
    caixa.hidden = !podeVideo;
    if (!podeVideo) { status.textContent = 'Tire uma foto do QR (a câmera ao vivo exige HTTPS) ou use o leitor USB.'; return; }
    try {
      fluxo = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' }, audio: false });
      video.srcObject = fluxo; await video.play();
      status.textContent = 'Aponte a câmera para o QR do convite.';
      let ultimo = 0;
      const passo = async (t) => {
        if (!fluxo) { return; }
        if (t - ultimo > 250 && video.videoWidth) {
          ultimo = t;
          const v = await decodificar(video, video.videoWidth, video.videoHeight);
          if (v && QR_PADRAO.test(v)) { pararCamera(); lerQr(v); return; }
        }
        laco = requestAnimationFrame(passo);
      };
      laco = requestAnimationFrame(passo);
    } catch (e) {
      caixa.hidden = true;
      status.textContent = 'Câmera indisponível ou não autorizada. Tire uma foto do QR ou digite o código.';
    }
  }
  $('#dlg-qr').addEventListener('close', pararCamera);
  $('#btn-ler-qr').addEventListener('click', abrirQr);
  $('#qr-foto').addEventListener('change', async (e) => {
    const f = e.target.files && e.target.files[0];
    e.target.value = '';
    if (!f) { return; }
    $('#qr-status').textContent = 'Lendo a foto…';
    try {
      const bmp = await createImageBitmap(f);
      const v = await decodificar(bmp, bmp.width, bmp.height);
      if (v && QR_PADRAO.test(v)) { lerQr(v); return; }
      $('#qr-status').textContent = 'QR não reconhecido. Aproxime, evite reflexo e tente de novo.';
    } catch (err) { $('#qr-status').textContent = 'Não foi possível ler a foto.'; }
  });
  $('#qr-manual-ok').addEventListener('click', () => lerQr($('#qr-manual').value));
  $('#qr-manual').addEventListener('keydown', (ev) => { if (ev.key === 'Enter') { ev.preventDefault(); lerQr($('#qr-manual').value); } });

  /* --- atalhos de teclado --- */
  document.addEventListener('keydown', (ev) => {
    if (document.querySelector('dialog[open]') && ev.key !== 'Escape') { return; }
    const alvo = ev.target;
    const digitando = alvo && (/^(INPUT|TEXTAREA|SELECT)$/.test(alvo.tagName) && alvo.type !== 'checkbox' && alvo.type !== 'radio');
    const k = ev.key;
    if (k === 'F2') {
      ev.preventDefault();
      if (st.pessoaId) { trocarPessoa(); } else { el.buscaPessoa.focus(); el.buscaPessoa.select(); }
    } else if (k === 'F3') {
      ev.preventDefault(); abrirQr();
    } else if (k === 'F4') {
      ev.preventDefault();
      if (st.veiculoId) { trocarVeiculo(true); } else { el.buscaVeic.focus(); }
    } else if (k === 'F8') {
      ev.preventDefault(); definirSentido('entrada');
    } else if (k === 'F9') {
      ev.preventDefault(); definirSentido('saida');
    } else if (k === 'Enter' && (ev.ctrlKey || ev.metaKey)) {
      ev.preventDefault();
      if (!el.criticos.hidden && !el.ciente.checked) { el.ciente.focus(); bip(1); toast('Confira os alertas em vermelho e marque a ciência (Espaço).', 'aviso'); return; }
      if (!el.btn.disabled) { form.requestSubmit ? form.requestSubmit() : el.btn.click(); }
    } else if (k === 'Escape') {
      if (document.querySelector('dialog[open]')) { return; }
      const abertas = $$('.resultados').some((u) => !u.hidden);
      if (abertas) { $$('.resultados').forEach((u) => { u.hidden = true; }); return; }
      ev.preventDefault(); limparFormulario();
    } else if (k === '?' && !digitando) {
      ev.preventDefault(); const d = $('#dlg-atalhos'); if (d) { d.showModal(); }
    } else if (!digitando && !ev.ctrlKey && !ev.altKey && !ev.metaKey && (k === 'e' || k === 'E' || k === 's' || k === 'S') && bufferQr.length <= 1) {
      definirSentido(k.toLowerCase() === 'e' ? 'entrada' : 'saida');
    }
  });

  /* --- cadastros rápidos --- */
  function formRapido(idForm, aoCriar) {
    const f = document.getElementById(idForm);
    if (!f) { return; }
    f.addEventListener('submit', async (ev) => {
      ev.preventDefault();
      const erro = f.querySelector('.erro-form');
      erro.hidden = true;
      const r = await api(f.dataset.endpoint, { method: 'POST', body: new FormData(f) });
      const sol = f.dataset.solicitacao || '';
      if (r.ok && r.data) {
        f.closest('dialog').close(); f.reset(); delete f.dataset.solicitacao; if (f.solicitacao_id) { f.solicitacao_id.value = ''; } toast('Cadastrado com sucesso.', 'sucesso'); aoCriar(r.data, sol);
      } else if (r.status === 409 && r.data && r.data.id) {
        f.closest('dialog').close(); delete f.dataset.solicitacao; toast(r.data.erro, 'aviso'); aoCriar(r.data, sol);
      } else {
        erro.textContent = r.rede ? 'Sem conexão: o cadastro rápido precisa do servidor.' : ((r.data && r.data.erro) || 'Falha ao cadastrar.'); erro.hidden = false;
      }
    });
  }
  formRapido('form-rapido-pessoa', (d, sol) => selecionarPessoa(d.id, sol));
  formRapido('form-rapido-veiculo', (d) => selecionarVeiculo({ id: d.id, placa: d.placa }));

  window.__respPadraoUsuario = el.respId.value || '';
  // No celular a lista "Dentro agora" começa recolhida para o formulário ficar no topo
  const detPresentes = $('#det-presentes');
  if (detPresentes && window.matchMedia('(max-width: 900px)').matches) { detPresentes.open = false; }
  atualizarEstado();
  // Com HTTPS, o service worker mantém esta tela abrível mesmo com o servidor fora do ar
  if (window.isSecureContext && 'serviceWorker' in navigator) {
    navigator.serviceWorker.register(BASE + '/sw.js', { scope: BASE + '/' }).catch(() => null);
  }
  if (!navigator.onLine) { marcarOffline(true); }
  contarFila().then(() => { if (filaTamanho) { sincronizar(); } });
  restaurarRascunho();
  // Pede ao navegador para não descartar a fila/rascunho quando faltar espaço (quando suportado)
  if (navigator.storage && navigator.storage.persist) { navigator.storage.persist().catch(() => null); }
  setTimeout(carregarCache, 1500);
})();
