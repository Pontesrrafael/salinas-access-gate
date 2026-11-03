/* Salinas Access Gate — JavaScript da interface (sem dependências externas). */
(function () {
  'use strict';

  /* ---------------- Utilitários ---------------- */
  const $ = (sel, ctx) => (ctx || document).querySelector(sel);
  const $$ = (sel, ctx) => Array.from((ctx || document).querySelectorAll(sel));
  const meta = (n) => (document.querySelector('meta[name="' + n + '"]') || {}).content || '';
  const BASE = meta('base-url').replace(/\/$/, '');
  const CSRF = meta('csrf-token');
  const locale = window.SAGI18n?.locale || 'pt-BR';
  const t = window.SAGI18n?.t || ((source, params) => String(source).replace(/\{([A-Za-z0-9_]+)\}/g, (token, key) => params && Object.prototype.hasOwnProperty.call(params, key) ? String(params[key]) : token));

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
    const r = await fetch(path.startsWith('http') ? path : BASE + path, {
      method: opts.method || 'GET', body: opts.body, headers: headers, credentials: 'same-origin',
    });
    let data = null;
    try { data = await r.json(); } catch (e) { data = null; }
    if (r.status === 401) { window.location.href = BASE + '/login'; }
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
        try { await navigator.clipboard.writeText(r.data.url); toast(t("Link copiado."), 'sucesso'); }
        catch (e) { window.prompt(t("Copie o link:"), r.data.url); }
      } else { toast((r.data && r.data.erro) || t("Sem permissão para copiar o link."), 'erro'); }
      return;
    }
    const copiarTexto = ev.target.closest('[data-copiar]');
    if (copiarTexto) {
      ev.preventDefault();
      try { await navigator.clipboard.writeText(copiarTexto.dataset.copiar); toast(t("Copiado."), 'sucesso'); }
      catch (e) { window.prompt(t("Copie:"), copiarTexto.dataset.copiar); }
      return;
    }

    const revelar = ev.target.closest('[data-revelar-cpf]');
    if (revelar) {
      ev.preventDefault();
      const motivo = window.prompt(t("Informe o motivo para revelar o CPF (registrado na auditoria):"), '');
      if (motivo === null) { return; }
      const fd = new FormData(); fd.append('motivo', motivo);
      const r = await api(revelar.dataset.revelarCpf, { method: 'POST', body: fd });
      if (r.ok && r.data) {
        const alvo = document.getElementById(revelar.dataset.alvo);
        if (alvo) { alvo.textContent = r.data.cpf || '—'; }
        revelar.remove();
      } else { toast((r.data && r.data.erro) || t("Não foi possível revelar o CPF."), 'erro'); }
    }
  });

  document.addEventListener('change', (ev) => {
    const el = ev.target;
    if (el.matches && el.matches('[data-autosubmit]') && el.form) { el.form.submit(); }
  });

  document.addEventListener('submit', (ev) => {
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
    setTimeout(() => { if (!document.querySelector('dialog[open]')) { window.location.reload(); } }, s * 1000);
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
      if (!r.ok) { toast((r.data && r.data.erro) || t("Falha ao consultar o AD."), 'erro'); return; }
      resAd.innerHTML = '';
      (r.data || []).forEach((e) => {
        const li = document.createElement('li'); const b = document.createElement('button'); b.type = 'button';
        b.innerHTML = '<strong>' + esc(e.nome || e.login) + '</strong> <span class="badge badge-neutro mono">' + esc(e.login) + '</span>' +
          (e.ativo ? '' : ' <span class="badge badge-perigo">' + esc(t("desativada")) + '</span>') +
          (e.vinculado_a ? ' <span class="badge badge-aviso">' + esc(t('já vinculada: {name}', { name: e.vinculado_a })) + '</span>' : '') +
          '<small>' + esc([e.email, e.matricula ? t('Matr. {number}', { number: e.matricula }) : '', e.departamento, e.cargo].filter(Boolean).join(' · ')) + '</small>';
        b.addEventListener('mousedown', (ev) => ev.preventDefault());
        b.addEventListener('click', () => {
          if (!e.ativo) { toast(t("Conta desativada no AD."), 'erro'); return; }
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
      if (!(r.data || []).length) { resAd.innerHTML = '<li class="dica">' + esc(t("Nenhuma conta encontrada no AD.")) + '</li>'; }
      resAd.hidden = false;
    }, 300));
    buscaAd.addEventListener('blur', () => setTimeout(() => { resAd.hidden = true; }, 150));
    formUsuario.addEventListener('submit', (ev) => {
      const r = formUsuario.querySelector('input[name="auth_origem"]:checked');
      if (r && r.value === 'ldap' && !guid.value) { ev.preventDefault(); toast(t("Selecione a conta do AD na busca."), 'erro'); buscaAd.focus(); }
    });
  }

  /* ---------------- Tema (automático / escuro / claro) ---------------- */
  (function tema() {
    const botoes = $$('[data-tema]');
    if (!botoes.length) { return; }
    const ordem = ['', 'escuro', 'claro'];
    const rotulos = { '': t("Automático"), escuro: t("Escuro"), claro: t("Claro") };
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
      botoes.forEach((b) => { const s = b.querySelector('span'); if (s) { s.textContent = window.SAGI18n?.t('Tema: {theme}', { theme: rotulos[t] }) || ('Tema: ' + rotulos[t]); } });
    };
    botoes.forEach((b) => b.addEventListener('click', () => {
      const t = ordem[(ordem.indexOf(atual()) + 1) % ordem.length];
      aplicar(t);
      toast((window.SAGI18n?.t('Tema: {theme}', { theme: rotulos[t] }) || ('Tema: ' + rotulos[t])) + (t === '' ? (window.SAGI18n?.t(' (segue o sistema)') || ' (segue o sistema)') : ''));
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

    const hora = () => new Date().toLocaleTimeString(locale, { timeZone: 'America/Cuiaba' });
    const setStatus = (txt, cls) => {
      if (!status) { return; }
      status.classList.remove('erro', 'carregando');
      if (cls) { status.classList.add(cls); }
      const span = status.querySelector('span');
      if (span) { span.textContent = txt; }
    };
    const descrever = () => (intervalo === '0' ? t(' · atualização automática desligada') : t(' · próxima em {interval}', { interval: selAtualizar ? selAtualizar.options[selAtualizar.selectedIndex].text : intervalo + ' s' }));

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
      setStatus(t("Atualizando…"), 'carregando');
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
        setStatus(t('Atualizado às {time}', { time: hora() }) + descrever());
      } catch (e) {
        setStatus(t('Falha ao atualizar às {time} — nova tentativa no próximo ciclo', { time: hora() }), 'erro');
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
        setStatus(t('Atualizado às {time}', { time: hora() }) + descrever());
        agendar();
      });
    }
    setStatus(t('Atualizado às {time}', { time: hora() }) + descrever());
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
      const tic = () => { relogio.textContent = new Date().toLocaleTimeString(locale, { hour: '2-digit', minute: '2-digit', timeZone: 'America/Cuiaba' }); };
      tic();
      setInterval(tic, 1000);
    }
    $$('[data-tela-cheia]').forEach((b) => b.addEventListener('click', () => {
      if (document.fullscreenElement) { document.exitFullscreen(); } else if (document.documentElement.requestFullscreen) { document.documentElement.requestFullscreen(); }
    }));
  })();

  /* ---------------- Portaria ---------------- */
  const form = $('#form-portaria');
  if (!form) { return; }

  const LOCAL = form.dataset.local;
  const MAX_FOTOS = parseInt(form.dataset.maxFotos, 10) || 8;
  const MAX_MB = parseInt(form.dataset.maxMb, 10) || 8;
  const MIMES = ['image/jpeg', 'image/png', 'image/webp'];

  const st = { pessoaId: '', veiculoId: '', solicitacaoId: '', analise: null, padraoAplicadoPara: '', fotos: [] };

  const el = {
    buscaPessoa: $('#busca-pessoa'), resPessoa: $('#res-pessoa'), dicaPessoa: $('#dica-pessoa'),
    pessoaId: $('#pessoa_id'), pessoaCard: $('#pessoa-card'), solBox: $('#solicitacao-box'),
    buscaVeic: $('#busca-veiculo'), resVeic: $('#res-veiculo'), dicaVeic: $('#dica-veiculo'),
    veiculoId: $('#veiculo_id'), veiculoCard: $('#veiculo-card'),
    destino: $('#destino'), resp: $('#responsavel'), respId: $('#responsavel_interno_id'),
    resResp: $('#res-responsavel'), respInfo: $('#responsavel-info'), obs: $('#observacoes'),
    padraoStatus: $('#padrao-status'), padraoPendente: $('#padrao-pendente'),
    bloqueios: $('#bloqueios'), avisos: $('#avisos'), btn: $('#btn-registrar'), btnTexto: $('#btn-registrar-texto'),
    fotosEnvio: $('#fotos-envio'), fotosGrade: $('#fotos-grade'), fotosVazio: $('#fotos-vazio'), fotosContador: $('#fotos-contador'),
  };

  const sentido = () => { const r = document.querySelector('input[name="sentido"]:checked'); return r ? r.value : 'entrada'; };

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

  /* --- busca de pessoa --- */
  el.buscaPessoa.addEventListener('input', debounce(async () => {
    const q = el.buscaPessoa.value.trim();
    if (q.length < 2) { el.resPessoa.hidden = true; el.dicaPessoa.textContent = t("Digite pelo menos 2 caracteres para buscar."); return; }
    const r = await api('/api/pessoas/busca?q=' + encodeURIComponent(q));
    const itens = (r.ok && Array.isArray(r.data)) ? r.data : [];
    const tem = lista(el.resPessoa, itens, (p) =>
      '<strong>' + esc(p.nome) + '</strong>' + (p.usuario ? ' <span class="badge badge-neutro">' + esc(t("usuário")) + '</span>' : '') +
      '<small>' + esc([p.cpf_exibicao || p.matricula || '—', p.categoria_label, p.empresa].filter(Boolean).join(' · ')) + '</small>',
    (p) => selecionarPessoa(p.id));
    el.dicaPessoa.textContent = tem ? '' : t("Nenhuma pessoa encontrada. Use “Cadastro rápido”.");
  }, 250));
  el.buscaPessoa.addEventListener('blur', () => setTimeout(() => { el.resPessoa.hidden = true; }, 150));

  function selecionarPessoa(id) {
    st.pessoaId = id; st.solicitacaoId = '';
    el.pessoaId.value = id;
    el.buscaPessoa.value = '';
    el.dicaPessoa.textContent = '';
    $('#busca-pessoa-box').hidden = true;
    analisar();
  }

  function trocarPessoa() {
    st.pessoaId = ''; st.solicitacaoId = ''; st.analise = null;
    el.pessoaId.value = '';
    el.pessoaCard.hidden = true; el.solBox.hidden = true;
    $('#busca-pessoa-box').hidden = false;
    el.dicaPessoa.textContent = t("Digite pelo menos 2 caracteres para buscar.");
    el.padraoStatus.textContent = ''; el.padraoPendente.hidden = true;
    atualizarEstado();
    el.buscaPessoa.focus();
  }

  /* --- busca de veículo --- */
  el.buscaVeic.addEventListener('input', debounce(async () => {
    const q = el.buscaVeic.value.trim();
    if (q.length < 2) { el.resVeic.hidden = true; el.dicaVeic.textContent = t("Pessoa a pé? Deixe em branco."); return; }
    const r = await api('/api/veiculos/busca?q=' + encodeURIComponent(q));
    const itens = (r.ok && Array.isArray(r.data)) ? r.data : [];
    const tem = lista(el.resVeic, itens, (v) =>
      '<strong class="mono">' + esc(v.placa) + '</strong><small>' + esc([v.tipo, v.marca_modelo, v.cor, v.empresa, v.tag ? 'TAG ' + v.tag : ''].filter(Boolean).join(' · ')) + '</small>',
    (v) => selecionarVeiculo(v));
    el.dicaVeic.textContent = tem ? '' : t("Nenhum veículo encontrado.");
  }, 250));
  el.buscaVeic.addEventListener('blur', () => setTimeout(() => { el.resVeic.hidden = true; }, 150));

  function selecionarVeiculo(v) {
    st.veiculoId = v.id; el.veiculoId.value = v.id;
    el.buscaVeic.value = ''; el.dicaVeic.textContent = '';
    $('#busca-veiculo-box').hidden = true;
    renderVeiculo(v);
    if (st.pessoaId) { analisar(); }
  }
  function renderVeiculo(v) {
    el.veiculoCard.innerHTML =
      '<div class="selecionado-topo"><dl class="dados">' +
      '<div><dt>' + esc(t("Placa")) + '</dt><dd class="mono">' + esc(v.placa) + '</dd></div>' +
      '<div><dt>' + esc(t("Modelo / Cor")) + '</dt><dd>' + esc([v.marca_modelo, v.cor].filter(Boolean).join(' · ') || '—') + '</dd></div>' +
      '<div><dt>' + esc(t("Empresa")) + '</dt><dd>' + esc(v.empresa || '—') + '</dd></div></dl>' +
      '<button type="button" class="btn btn-fantasma btn-sm" id="btn-trocar-veiculo">' + esc(t("Trocar")) + '</button></div>';
    el.veiculoCard.hidden = false;
    $('#btn-trocar-veiculo').addEventListener('click', () => {
      st.veiculoId = ''; el.veiculoId.value = ''; el.veiculoCard.hidden = true;
      $('#busca-veiculo-box').hidden = false; el.dicaVeic.textContent = t("Pessoa a pé? Deixe em branco.");
      if (st.pessoaId) { analisar(); }
    });
  }

  /* --- responsável interno --- */
  el.resp.addEventListener('input', debounce(async () => {
    el.respId.value = ''; el.respInfo.hidden = false;
    el.respInfo.textContent = el.resp.value.trim() ? t("Texto livre — não vinculado a uma pessoa cadastrada.") : '';
    const q = el.resp.value.trim();
    if (q.length < 2) { el.resResp.hidden = true; return; }
    const r = await api('/api/responsaveis/busca?q=' + encodeURIComponent(q));
    const itens = (r.ok && Array.isArray(r.data)) ? r.data : [];
    lista(el.resResp, itens, (p) =>
      '<strong>' + esc(p.nome) + '</strong>' + (Number(p.usuario) ? ' <span class="badge badge-neutro">' + esc(t("usuário")) + '</span>' : '') +
      '<small>' + esc([p.empresa, p.matricula, p.telefone_principal, p.email_principal].filter(Boolean).join(' · ') || t("Sem contato")) + '</small>',
    (p) => definirResponsavel(p));
  }, 250));
  el.resp.addEventListener('blur', () => setTimeout(() => { el.resResp.hidden = true; }, 150));

  function definirResponsavel(p) {
    if (!p) { return; }
    el.resp.value = p.nome; el.respId.value = p.id;
    const contato = [p.empresa, p.telefone_principal || t("Sem telefone"), p.email_principal].filter(Boolean).join(' · ');
    el.respInfo.textContent = contato + ((!p.telefone_principal && !p.email_principal) ? t(' — responsável sem contato cadastrado') : '');
    el.respInfo.hidden = false;
  }

  /* --- análise no servidor --- */
  async function analisar() {
    if (!st.pessoaId) { atualizarEstado(); return; }
    el.btn.disabled = true;
    const q = new URLSearchParams({
      pessoa_id: st.pessoaId, local_id: LOCAL, sentido: sentido(),
      veiculo_id: st.veiculoId, solicitacao_id: st.solicitacaoId,
    });
    const r = await api('/api/portaria/analise?' + q.toString());
    if (!r.ok || !r.data) { toast((r.data && r.data.erro) || t("Falha ao consultar a pessoa."), 'erro'); return; }
    st.analise = r.data;
    renderPessoa(r.data);
    renderSolicitacoes(r.data);
    if (r.data.veiculo) { renderVeiculo(r.data.veiculo); }
    aplicarPadrao(r.data, false);
    atualizarEstado();
  }

  function renderPessoa(a) {
    const p = a.pessoa;
    const docs = (a.documentos || []).map((d) => {
      const cls = d.status === 'vencido' ? 'perigo' : (d.status === 'proximo_vencimento' ? 'aviso' : 'sucesso');
      return '<span class="badge badge-' + cls + '" title="' + esc(t('Validade {date}', { date: d.validade_fmt })) + '">' + esc(d.rotulo) + ' · ' + esc(d.validade_fmt) + '</span>';
    }).join(' ');
    el.pessoaCard.innerHTML =
      '<div class="selecionado-topo"><dl class="dados">' +
      '<div><dt>' + esc(t("Nome")) + '</dt><dd><strong>' + esc(p.nome) + '</strong></dd></div>' +
      '<div><dt>' + esc(t("Tipo")) + '</dt><dd><span class="badge badge-neutro">' + esc(p.categoria_label) + '</span></dd></div>' +
      '<div><dt>' + esc(t("Empresa")) + '</dt><dd>' + esc(p.empresa || '—') + '</dd></div>' +
      '<div><dt>' + esc(t("Telefone")) + '</dt><dd>' + esc(p.telefone_principal || p.telefone || '—') + '</dd></div>' +
      '<div class="largo"><dt>' + esc(t("Documentos")) + '</dt><dd>' + (docs || '<span class="mudo">' + esc(t("Nenhum documento cadastrado")) + '</span>') + '</dd></div>' +
      (a.presente_desde ? '<div class="largo"><dt>' + esc(t("Situação")) + '</dt><dd><span class="badge badge-info">' + esc(t('Presente neste local desde {date}', { date: a.presente_desde })) + '</span></dd></div>' : '') +
      '</dl><button type="button" class="btn btn-fantasma btn-sm" id="btn-trocar-pessoa">' + esc(t("Trocar")) + '</button></div>';
    el.pessoaCard.hidden = false;
    $('#btn-trocar-pessoa').addEventListener('click', trocarPessoa);
  }

  function itemSolicitacao(s, marcada) {
    const aprovada = s.status === 'aprovada';
    return '<label class="sol-item' + (aprovada ? '' : ' desabilitada') + '">' +
      '<input type="radio" name="solicitacao_id" value="' + esc(s.id) + '"' + (marcada ? ' checked' : '') + (aprovada ? '' : ' disabled') + '>' +
      '<span><strong>' + esc(s.visitante_nome) + '</strong> <span class="badge badge-' + (aprovada ? 'sucesso' : 'aviso') + '">' + esc(s.status_label) + '</span>' +
      (s.risco ? ' <span class="badge badge-perigo">' + esc(t("Risco")) + '</span>' : '') +
      '<small>' + esc([s.area ? t('Área: {area}', { area: s.area }) : '', s.responsavel ? t('Resp.: {name}', { name: s.responsavel }) : '', s.empresa].filter(Boolean).join(' · ')) + '</small></span></label>';
  }

  function renderSolicitacoes(a) {
    if (!a.exige_solicitacao || sentido() !== 'entrada') { el.solBox.hidden = true; el.solBox.innerHTML = ''; return; }
    const s = a.solicitacoes;
    const marcada = st.solicitacaoId || a.solicitacao_sugerida || '';
    const principais = s.vinculadas.concat(s.candidatas);
    let html = '<h3 class="rotulo-caps">' + esc(t("Solicitação de acesso de hoje")) + '</h3>';
    if (principais.length) {
      html += principais.map((x) => itemSolicitacao(x, x.id === marcada)).join('');
    } else {
      html += '<p class="dica">' + esc(t("Nenhuma solicitação aprovada para hoje foi encontrada para esta pessoa (por CPF ou nome).")) + '</p>';
    }
    if (s.outras.length) {
      html += '<details' + (s.outras.some((x) => x.id === marcada) ? ' open' : '') + '><summary>' + esc(t('Outras solicitações aprovadas hoje sem vínculo ({count})', { count: s.outras.length })) + '</summary>' +
        s.outras.map((x) => itemSolicitacao(x, x.id === marcada)).join('') + '</details>';
    }
    if (a.solicitacao_manual) {
      html += '<label class="checkbox"><input type="checkbox" name="confirmar_solicitacao_manual" value="1" form="form-portaria" id="confirmar-sol-manual"> ' +
        '<span>' + esc(t("Confirmo que esta solicitação é desta pessoa, mesmo sem CPF ou nome conferindo (fica registrado na auditoria).")) + '</span></label>';
    }
    html += '<p class="dica"><a href="' + esc(BASE) + '/solicitacoes" target="_blank" rel="noopener">' + esc(t("Abrir solicitações")) + '</a></p>';
    el.solBox.innerHTML = html;
    const conf = $('#confirmar-sol-manual');
    if (conf) { conf.addEventListener('change', atualizarEstado); }
    el.solBox.hidden = false;
    $$('input[name="solicitacao_id"]', el.solBox).forEach((r) => r.addEventListener('change', () => {
      st.solicitacaoId = r.value; analisar();
    }));
  }

  /* --- dados padrão do cadastro --- */
  function aplicarPadrao(a, forcar) {
    const p = a.pessoa;
    const d = (p.destino_padrao || '').trim(), r = (p.responsavel_interno_padrao || '').trim(), o = (p.observacoes_portaria_padrao || '').trim();
    if (!d && !r && !o) { el.padraoStatus.textContent = t("Sem dados padrão cadastrados para esta pessoa."); el.padraoPendente.hidden = true; return; }
    if (!forcar && st.padraoAplicadoPara === p.id) { return; }
    // Responsável pré-preenchido com o próprio usuário logado não conta como "digitado"
    const respVazio = !el.resp.value.trim() || (!!window.__respPadraoUsuario && el.respId.value === window.__respPadraoUsuario);
    const vazios = !el.destino.value.trim() && !el.obs.value.trim() && respVazio;
    if (forcar || vazios) {
      el.destino.value = d; el.obs.value = o;
      if (r) {
        el.resp.value = r; el.respId.value = '';
        if (a.responsavel_padrao) { definirResponsavel(a.responsavel_padrao); }
        else { el.respInfo.hidden = false; el.respInfo.textContent = t("Texto livre — não vinculado a uma pessoa cadastrada."); }
      }
      el.padraoStatus.textContent = t("Dados preenchidos a partir do cadastro da pessoa.");
      el.padraoPendente.hidden = true;
    } else {
      el.padraoPendente.hidden = false;
    }
    st.padraoAplicadoPara = p.id;
  }
  $('#btn-usar-padrao').addEventListener('click', () => { if (st.analise) { aplicarPadrao(st.analise, true); } });
  [el.destino, el.obs].forEach((i) => i.addEventListener('input', () => { el.padraoStatus.textContent = ''; }));

  /* --- estado do botão e alertas --- */
  function atualizarEstado() {
    const s = sentido();
    el.btn.classList.toggle('entrada', s === 'entrada');
    el.btn.classList.toggle('saida', s === 'saida');
    el.btnTexto.textContent = s === 'entrada' ? t("CONFIRMAR ENTRADA") : t("CONFIRMAR SAÍDA");
    const a = st.analise;
    const bloqueios = (a && st.pessoaId) ? a.bloqueios : [];
    const avisos = (a && st.pessoaId) ? a.avisos : [];
    el.bloqueios.hidden = !bloqueios.length;
    el.bloqueios.innerHTML = bloqueios.length ? '<strong>' + (s === 'entrada' ? t("Entrada bloqueada") : t("Saída não permitida")) + '</strong><ul>' + bloqueios.map((b) => '<li>' + esc(b) + '</li>').join('') + '</ul>' : '';
    el.avisos.hidden = !avisos.length;
    el.avisos.innerHTML = avisos.length ? '<strong>' + esc(t("Atenção")) + '</strong><ul>' + avisos.map((b) => '<li>' + esc(b) + '</li>').join('') + '</ul>' : '';
    const confManual = $('#confirmar-sol-manual');
    el.btn.disabled = !st.pessoaId || !a || bloqueios.length > 0 || (!!a.solicitacao_manual && s === 'entrada' && !(confManual && confManual.checked));
  }
  $$('input[name="sentido"]').forEach((r) => r.addEventListener('change', () => { st.solicitacaoId = ''; analisar(); atualizarEstado(); }));

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
      fig.innerHTML = '<img alt="' + esc(t('Prévia {number}', { number: i + 1 })) + '"><button type="button" class="foto-remover" aria-label="' + esc(t('Remover foto')) + '">&times;</button>' +
        '<select name="fotos_tipo[]" form="form-portaria">' + opcoes + '</select><figcaption>' + Math.round(f.file.size / 1024) + ' KB</figcaption>';
      fig.querySelector('img').src = f.url;
      const sel = fig.querySelector('select'); sel.value = f.tipo;
      sel.addEventListener('change', () => { f.tipo = sel.value; });
      fig.querySelector('.foto-remover').addEventListener('click', () => {
        URL.revokeObjectURL(f.url); st.fotos.splice(i, 1); sincronizarFotos();
      });
      el.fotosGrade.appendChild(fig);
    });
    el.fotosVazio.hidden = st.fotos.length > 0;
    el.fotosContador.textContent = t('({count}/{max} · JPG/PNG/WEBP · até {mb} MB)', { count: st.fotos.length, max: MAX_FOTOS, mb: MAX_MB });
  }
  function adicionarFotos(files, tipo) {
    Array.from(files || []).forEach((file) => {
      if (st.fotos.length >= MAX_FOTOS) { toast(t('Máximo de {count} fotos por registro.', { count: MAX_FOTOS }), 'erro'); return; }
      if (MIMES.indexOf(file.type) === -1) { toast(t('Formato inválido: {name} (use JPG, PNG ou WEBP).', { name: file.name }), 'erro'); return; }
      if (file.size > MAX_MB * 1024 * 1024) { toast(t('{name} excede {mb} MB.', { name: file.name, mb: MAX_MB }), 'erro'); return; }
      st.fotos.push({ file: file, url: URL.createObjectURL(file), tipo: tipo });
    });
    sincronizarFotos();
  }
  $('#foto-camera').addEventListener('change', (e) => { adicionarFotos(e.target.files, 'pessoa'); e.target.value = ''; });
  $('#foto-galeria').addEventListener('change', (e) => { adicionarFotos(e.target.files, 'outra'); e.target.value = ''; });

  /* --- envio --- */
  form.addEventListener('submit', (ev) => {
    if (el.btn.disabled) { ev.preventDefault(); return; }
    if (form.dataset.exigirDestino === '1' && sentido() === 'entrada' && !el.destino.value.trim()) {
      ev.preventDefault(); toast(t("Informe o destino/setor."), 'erro'); el.destino.focus(); return;
    }
    el.btn.disabled = true;
    el.btnTexto.textContent = t("Registrando...");
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
      if (r.ok && r.data) {
        f.closest('dialog').close(); f.reset(); toast(t("Cadastrado com sucesso."), 'sucesso'); aoCriar(r.data);
      } else if (r.status === 409 && r.data && r.data.id) {
        f.closest('dialog').close(); toast(r.data.erro, 'aviso'); aoCriar(r.data);
      } else {
        erro.textContent = (r.data && r.data.erro) || t("Falha ao cadastrar."); erro.hidden = false;
      }
    });
  }
  formRapido('form-rapido-pessoa', (d) => selecionarPessoa(d.id));
  formRapido('form-rapido-veiculo', (d) => selecionarVeiculo({ id: d.id, placa: d.placa }));

  window.__respPadraoUsuario = el.respId.value || '';
  atualizarEstado();
})();

/* --- QR do cadastro da verificação em duas etapas (biblioteca local, sem enviar a chave a terceiros) --- */
(function () {
  const alvo = document.getElementById('qr-mfa');
  if (!alvo || typeof window.qrcode !== 'function') { return; }
  const t = window.SAGI18n?.t || ((s) => s);
  const qr = window.qrcode(0, 'M');
  qr.addData(alvo.dataset.otpauth || '');
  qr.make();
  alvo.innerHTML = qr.createSvgTag({ cellSize: 5, margin: 4, scalable: true, title: t('Código QR do Salinas Access Gate') });
})();


/* --- Chamados: autoseleção de área/categoria e campo condicional de beneficiário --- */
(function () {
  document.addEventListener('change', (ev) => {
    const el = ev.target;
    if (el && el.matches && el.matches('select[data-limpar-alvo]') && el.form) {
      const alvo = el.form.elements[el.dataset.limparAlvo];
      if (alvo) { alvo.value = ''; }
    }
  }, true);

  const selParaVoce = document.querySelector('select[name="para_voce"]');
  const campoBenef = document.getElementById('campo-beneficiario');
  if (selParaVoce && campoBenef) {
    const inputBenef = campoBenef.querySelector('input[name="beneficiario_nome"]');
    const sincronizarBeneficiario = () => {
      const outraPessoa = selParaVoce.value === '0';
      campoBenef.hidden = !outraPessoa;
      if (inputBenef) {
        inputBenef.required = outraPessoa;
        if (!outraPessoa) { inputBenef.value = ''; }
      }
    };
    selParaVoce.addEventListener('change', () => {
      sincronizarBeneficiario();
      if (selParaVoce.value === '0' && inputBenef) { inputBenef.focus(); }
    });
    sincronizarBeneficiario();
  }
})();
