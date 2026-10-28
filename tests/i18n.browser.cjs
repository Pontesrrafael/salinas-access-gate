/* Requer o roteador isolado de tests/fixtures/tutorial-router.php e Playwright instalado. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const base = process.env.TUTORIAL_TEST_URL || 'http://127.0.0.1:8238';
(async () => {
  const browser = await chromium.launch({ headless: true, channel: 'chrome' });
  try {
    const context = await browser.newContext({ viewport: { width: 1366, height: 900 } });
    const page = await context.newPage();
    const errors = [], writes = [];
    page.on('pageerror', e => errors.push(e.message));
    page.on('request', r => { if (r.method() !== 'GET') { writes.push(r.url()); } });
    const dict = locale => locale === 'pt-BR' ? {} : JSON.parse(fs.readFileSync(path.join(__dirname, '../app/Lang', locale + '.json'), 'utf8'));
    await page.goto(base + '/login');
    assert.equal(await page.locator('html').getAttribute('lang'), 'pt-BR');
    for (const locale of ['en', 'es', 'pt-BR']) {
      const d = dict(locale), tr = source => d[source] || source;
      await page.locator('.seletor-idioma > summary').click();
      await Promise.all([page.waitForURL(url => url.searchParams.get('lang') === locale), page.locator('.idioma-opcoes a[hreflang="' + locale + '"]').click()]);
      assert.equal(await page.locator('html').getAttribute('lang'), locale);
      assert.equal(await page.locator('button[type="submit"]').last().textContent(), tr('Entrar'));
      assert.equal(await page.locator('input[name="email"]').getAttribute('type'), 'email');
      assert.equal(await page.locator('input[name="senha"]').getAttribute('type'), 'password');
      await page.goto(base + '/pessoas?q=Entrada&role=porteiro');
      assert.equal(await page.locator('html').getAttribute('lang'), locale, 'Preferência não persistiu.');
      assert.equal(await page.locator('h1').textContent(), tr('Pessoas'));
      assert.equal(await page.locator('tbody strong').textContent(), 'Pessoas', 'Nome foi traduzido.');
      assert.equal(await page.locator('tbody td').nth(2).textContent(), 'Entrada', 'Empresa foi traduzida.');
      assert.equal(await page.locator('main input[name="q"]').inputValue(), 'Entrada');
      assert.equal(await page.locator('select[name="categoria"] option').nth(2).getAttribute('value'), 'visitante');
      await page.goto(base + '/tutoriais?role=porteiro');
      assert.equal(await page.locator('a[href$="/configuracoes"]').count(), 0);
      await page.locator('[data-tutorial-open="portaria"]').click();
      assert.equal(await page.locator('#tutorial-help-title').textContent(), tr('Portaria') + ' — ' + tr('Como usar'));
      assert.equal(await page.locator('#tutorial-help details summary').textContent(), tr('Registrar uma movimentação'));
      await page.keyboard.press('Escape');
      if (process.env.TUTORIAL_SCREENSHOTS && locale !== 'pt-BR') { await page.screenshot({ path: process.env.TUTORIAL_SCREENSHOTS + '/tutoriais-' + locale + '.png', fullPage: true }); }
      await page.goto(base + '/login');
    }
    await page.goto(base + '/login?lang=invalid');
    assert.equal(await page.locator('html').getAttribute('lang'), 'pt-BR');
    await page.goto(base + '/login?lang=en&ldap=1');
    assert.equal(await page.locator('input[name="email"]').getAttribute('type'), 'text');
    assert.equal(await page.locator('input[name="email"]').getAttribute('placeholder'), dict('en')['Ex.: usuário de rede']);
    await page.setViewportSize({ width: 375, height: 667 });
    await page.goto(base + '/login?lang=es');
    assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
    const controls = await page.locator('.seletor-tema').boundingBox();
    const loginCard = await page.locator('main').boundingBox();
    assert.ok(controls.y + controls.height <= loginCard.y, 'Controles de idioma/tema sobrepõem o login no celular.');
    if (process.env.TUTORIAL_SCREENSHOTS) { await page.screenshot({ path: process.env.TUTORIAL_SCREENSHOTS + '/login-es-mobile.png' }); }

    // O seletor deve continuar operável por teclado quando scripts estão desativados.
    const nativeContext = await browser.newContext({ javaScriptEnabled: false, viewport: { width: 1366, height: 900 } });
    const native = await nativeContext.newPage();
    native.on('request', r => { if (r.method() !== 'GET') { writes.push(r.url()); } });
    await native.goto(base + '/pessoas?q=Entrada&role=porteiro&pagina=2&lang=es');
    assert.equal(await native.locator('.sidebar .seletor-idioma').count(), 0, 'Seletor permaneceu no menu lateral.');
    assert.equal(await native.locator('.topo-acoes > :last-child').getAttribute('class'), 'seletor-idioma');
    await native.locator('.seletor-idioma > summary').focus();
    await native.keyboard.press('Enter');
    assert.notEqual(await native.locator('.seletor-idioma').getAttribute('open'), null);
    assert.equal(await native.locator('.idioma-opcoes a').count(), 3);
    assert.equal(await native.locator('.idioma-opcoes a[aria-current="true"]').getAttribute('hreflang'), 'es');
    assert.deepEqual(await native.locator('.idioma-opcoes a span').allTextContents(), ['Português (Brasil)', 'Español (España)', 'English (United States)']);
    await native.keyboard.press('Tab');
    assert.equal(await native.locator('.idioma-opcoes a:focus').getAttribute('hreflang'), 'pt-BR');
    await native.keyboard.press('Tab');
    await native.keyboard.press('Tab');
    assert.equal(await native.locator('.idioma-opcoes a:focus').getAttribute('hreflang'), 'en');
    await Promise.all([native.waitForURL(url => url.searchParams.get('lang') === 'en'), native.keyboard.press('Enter')]);
    assert.equal(await native.locator('html').getAttribute('lang'), 'en');
    const selectedUrl = new URL(native.url());
    assert.equal(selectedUrl.searchParams.get('q'), 'Entrada');
    assert.equal(selectedUrl.searchParams.get('pagina'), '2');
    assert.equal(selectedUrl.searchParams.get('role'), 'porteiro');
    assert.equal(selectedUrl.searchParams.getAll('lang').length, 1);
    await native.goto(base + '/login');
    assert.equal(await native.locator('html').getAttribute('lang'), 'en', 'Cookie deve persistir sem JavaScript.');

    // Os dois layouts devem manter o menu dentro da tela e acima do conteúdo em ambos os temas.
    for (const theme of ['claro', 'escuro']) {
      await nativeContext.addCookies([{ name: 'sag_tema', value: theme, url: base }]);
      for (const width of [1366, 599, 375, 320]) {
        await native.setViewportSize({ width, height: 900 });
        for (const route of ['/login', '/pessoas?q=Entrada&role=porteiro', '/pessoas/nova?role=admin']) {
          await native.goto(base + route);
          await native.locator('.seletor-idioma > summary').focus();
          await native.keyboard.press('Space');
          const menu = await native.locator('.idioma-opcoes').boundingBox();
          const summary = await native.locator('.seletor-idioma > summary').boundingBox();
          assert.ok(menu && menu.x >= 0 && menu.x + menu.width <= width, 'Menu ultrapassa a largura da tela: ' + width + ' ' + route);
          assert.ok(menu.y >= summary.y + summary.height, 'Menu deve abrir abaixo do resumo.');
          assert.ok(await native.locator('.seletor-idioma > summary').evaluate(el => getComputedStyle(el).outlineStyle !== 'none'), 'Foco deve ser visível.');
          assert.equal(await native.locator('.idioma-codigo').isVisible(), width >= 600, 'Celular deve mostrar só a bandeira no resumo.');
          assert.ok(await native.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'Página ultrapassa a tela.');
          const colors = await native.locator('.idioma-opcoes').evaluate(el => ({ background: getComputedStyle(el).backgroundColor, surface: getComputedStyle(document.querySelector('main')).backgroundColor }));
          if (route === '/login') { assert.equal(colors.background, colors.surface, 'Menu deve seguir a superfície do tema.'); }
          if (process.env.TUTORIAL_SCREENSHOTS && [1366, 375].includes(width)) {
            await native.screenshot({ path: process.env.TUTORIAL_SCREENSHOTS + '/seletor-' + (route === '/login' ? 'login' : route.startsWith('/pessoas/nova') ? 'form' : 'app') + '-' + theme + '-' + width + '.png' });
          }
        }
      }
    }
    await nativeContext.close();
    assert.deepEqual(errors, []);
    assert.deepEqual(writes, [], 'Alterar idioma fez POST de negócio.');
    console.log('Idiomas UI: PT/EN/ES, dados, cookie, links GET, teclado sem JS, layouts, temas e telas de 320 a 1366 px OK.');
  } finally { await browser.close(); }
})().catch(e => { console.error(e); process.exitCode = 1; });
