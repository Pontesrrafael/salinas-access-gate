/* Teste de interface isolado, sem banco ou sessão de produção.
 * Inicie antes: php -S 127.0.0.1:8238 -t public tests/fixtures/tutorial-router.php
 * Requer Playwright já disponível. PLAYWRIGHT_MODULE pode apontar ao runtime local.
 */
const assert = require('node:assert/strict');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
(async () => {
  const browser = await chromium.launch({ headless: true, channel: 'chrome' });
  const context = await browser.newContext({ viewport: { width: 1366, height: 900 } });
  const page = await context.newPage();
  try {
  const errors = [], posts = [];
  page.on('pageerror', e => errors.push(e.message));
  page.on('request', r => { if (r.method() !== 'GET') { posts.push(r.url()); } });
  const open = async (route = '/painel', role = 'admin') => {
    await page.goto((process.env.TUTORIAL_TEST_URL || 'http://127.0.0.1:8238') + route + (route.includes('?') ? '&' : '?') + 'role=' + role);
    await page.locator('[data-tutorial-open]').first().waitFor();
  };
  await open('/painel', 'porteiro');
  assert.equal(await page.locator('a[href$="/tutoriais"]').count() >= 1, true);
  assert.equal(await page.evaluate(() => !!JSON.parse(document.getElementById('tutorial-config').textContent).catalog.configuracoes), false);
  await page.locator('[data-tutorial-open]').first().click();
  assert.equal(await page.locator('#tutorial-help').evaluate(el => el.open), true);
  assert.equal(await page.locator('#tutorial-help details').first().evaluate(el => el.open), true);
  await page.keyboard.press('Escape');
  assert.equal(await page.locator('[data-tutorial-open]').first().evaluate(el => el === document.activeElement), true);
  await page.locator('[data-tutorial-open]').first().click();
  await page.getByRole('button', { name: /^(Fazer o tour|Reiniciar tour)$/, exact: true }).click();
  await page.locator('#tutorial-tour[open]').waitFor();
  assert.match(await page.locator('#tutorial-counter').textContent(), /Passo 1 de 4/);
  await page.keyboard.press('ArrowRight');
  await page.keyboard.press('ArrowLeft');
  assert.match(await page.locator('#tutorial-counter').textContent(), /Passo 1/);
  for (let i = 0; i < 4; i++) { await page.locator('#tutorial-next').click(); }
  assert.equal(await page.locator('#tutorial-tour').evaluate(el => el.open), false);
  assert.equal(await page.evaluate(() => JSON.parse(localStorage.getItem('sag:tutoriais:fixture-porteiro:painel:v1')).state), 'concluido');
  await open('/painel', 'admin');
  assert.equal(await page.evaluate(() => JSON.parse(localStorage.getItem('sag:tutoriais:fixture-admin:painel:v1')).state), 'nao_iniciado');
  await page.locator('[data-tutorial-open]').first().click();
  await page.locator('#tutorial-suggestions').uncheck();
  await page.keyboard.press('Escape');
  await open('/veiculos', 'admin');
  assert.equal(await page.locator('#tutorial-invite').isVisible(), false);
  await page.locator('[data-tutorial-open]').first().click();
  await page.getByRole('button', { name: /^(Fazer o tour|Reiniciar tour)$/, exact: true }).click();
  await page.locator('#tutorial-tour[open]').waitFor();
  await page.locator('#tutorial-skip').click();
  assert.equal(await page.evaluate(() => JSON.parse(localStorage.getItem('sag:tutoriais:fixture-admin:veiculos:v1')).state), 'dispensado');
  await page.locator('[data-tutorial-open]').first().click();
  await page.getByRole('button', { name: /^(Fazer o tour|Reiniciar tour)$/, exact: true }).click();
  await page.locator('#tutorial-tour[open]').waitFor();
  await page.evaluate(() => document.querySelector('[data-tour="filtros"]').remove());
  await page.locator('#tutorial-next').click();
  assert.match(await page.locator('#tutorial-counter').textContent(), /de 3/);
  await page.keyboard.press('Escape');
  await open('/tutoriais', 'porteiro');
  if (process.env.TUTORIAL_SCREENSHOTS) { await page.screenshot({ path: process.env.TUTORIAL_SCREENSHOTS + '/catalogo.png', fullPage: true }); }
  await page.locator('#tutorial-search').fill('configurações');
  assert.equal(await page.locator('#tutorial-empty').isVisible(), true);
  await page.locator('#tutorial-search').fill('veículos');
  await page.locator('[data-tutorial-card]:visible [data-tutorial-open]').first().click();
  await page.getByRole('link', { name: 'Abrir módulo e fazer o tour' }).click();
  await page.locator('#tutorial-tour[open]').waitFor();
  assert.match(page.url(), /\/veiculos/);
  await page.keyboard.press('Escape');
  await page.setViewportSize({ width: 375, height: 667 });
  await page.locator('[data-tutorial-open]').first().click();
  const panel = await page.locator('#tutorial-help').boundingBox();
  if (process.env.TUTORIAL_SCREENSHOTS) { await page.screenshot({ path: process.env.TUTORIAL_SCREENSHOTS + '/ajuda-celular.png' }); }
  assert.ok(panel.x >= 0 && panel.width <= 375 && panel.height <= 667);
  await page.getByRole('button', { name: /^(Fazer o tour|Reiniciar tour)$/, exact: true }).click();
  await page.locator('#tutorial-tour[open]').waitFor();
  const balloon = await page.locator('#tutorial-balloon').boundingBox();
  if (process.env.TUTORIAL_SCREENSHOTS) { await page.screenshot({ path: process.env.TUTORIAL_SCREENSHOTS + '/tour-celular.png' }); }
  assert.ok(balloon.x >= 0 && balloon.y >= 0 && balloon.x + balloon.width <= 375 && balloon.y + balloon.height <= 667);
  await page.keyboard.press('Tab');
  assert.equal(await page.evaluate(() => !!document.activeElement.closest('#tutorial-tour')), true);
  await page.keyboard.press('Escape');
  assert.equal(posts.length, 0, 'Tour fez requisição com efeito de negócio.');
  assert.deepEqual(errors, []);
  console.log('Tutoriais UI: permissões, teclado/foco, conclusão, reinício, dispensa, alvos ausentes, navegação, preferência, usuários e celular OK. Nenhum POST.');
  } finally { await browser.close(); }
})().catch(e => { console.error(e); process.exitCode = 1; });
