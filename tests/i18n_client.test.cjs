const test = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const path = require('node:path');

const client = fs.readFileSync(path.join(__dirname, '../public/assets/js/i18n.js'), 'utf8');
const app = fs.readFileSync(path.join(__dirname, '../public/assets/js/app.js'), 'utf8');
function fixture(config, raw = false) {
  const span = { textContent: '' }, listeners = {};
  const theme = { querySelector: () => span, addEventListener: (name, fn) => { listeners[name] = fn; } };
  const attributes = {};
  const document = {
    cookie: '',
    getElementById: id => id === 'i18n-config' ? { textContent: raw ? config : JSON.stringify(config) } : null,
    querySelector: () => null,
    querySelectorAll: selector => selector === '[data-tema]' ? [theme] : [],
    addEventListener() {},
    documentElement: { setAttribute(name, value) { attributes[name] = value; }, removeAttribute(name) { delete attributes[name]; } },
  };
  const window = {};
  const context = vm.createContext({ window, document, Intl, Object, String, JSON, Array, setTimeout, clearTimeout });
  vm.runInContext(client, context);
  return { i18n: window.SAGI18n, context, document, span, listeners, attributes };
}

test('Client: locale permitido, catálogo exato e fallback preservam dados', () => {
  const f = fixture({ locale: 'en', messages: { 'Portaria': 'Gatehouse', 'Entrada': 'Entry' } });
  assert.equal(f.i18n.locale, 'en');
  assert.equal(f.i18n.t('Portaria'), 'Gatehouse');
  assert.equal(f.i18n.t('Nome de uma pessoa'), 'Nome de uma pessoa');
  assert.equal(f.i18n.t('Portaria ABC-1234'), 'Portaria ABC-1234');
  assert.equal(f.i18n.t('toString'), 'toString');
  assert.equal(f.i18n.t('__proto__'), '__proto__');
});

test('Client: parâmetros preservam nomes e são escapados antes de HTML', () => {
  const f = fixture({ locale: 'es', messages: { 'Olá {name}': 'Hola {name}' } });
  const name = '<img src=x onerror=alert(1)> & "Nome"';
  const text = f.i18n.t('Olá {name}', { name });
  assert.equal(text, 'Hola ' + name);
  assert.equal(f.i18n.escape(text), 'Hola &lt;img src=x onerror=alert(1)&gt; &amp; &quot;Nome&quot;');
  assert.equal(f.i18n.t('Olá {name}'), 'Hola {name}');
  assert.equal(f.i18n.t('Olá {name}', Object.create({ name: 'inherited' })), 'Hola {name}');
});

test('Client: singular e plural usam chaves distintas e contador', () => {
  const f = fixture({ locale: 'en', messages: { '{count} viagem': '{count} trip', '{count} viagens': '{count} trips' } });
  assert.equal(f.i18n.plural(1, '{count} viagem', '{count} viagens'), '1 trip');
  assert.equal(f.i18n.plural(0, '{count} viagem', '{count} viagens'), '0 trips');
  assert.equal(f.i18n.plural(2, '{count} viagem', '{count} viagens'), '2 trips');
});

test('Client: configuração inválida ou incompleta retorna português', () => {
  assert.equal(fixture({ locale: '../../etc/passwd', messages: {} }).i18n.locale, 'pt-BR');
  assert.equal(fixture('not-json', true).i18n.t('Portaria'), 'Portaria');
  assert.equal(fixture({ locale: 'en', messages: { 'Portaria': null } }).i18n.t('Portaria'), 'Portaria');
});

test('Client: alternar tema traduz só o rótulo e preserva o cookie operacional', () => {
  const f = fixture({ locale: 'en', messages: { 'Escuro': 'Dark', 'Claro': 'Light', 'Automático': 'Automatic', 'Tema: {theme}': 'Theme: {theme}' } });
  vm.runInContext(app, f.context);
  f.listeners.click();
  assert.equal(f.span.textContent, 'Theme: Dark');
  assert.match(f.document.cookie, /^sag_tema=escuro;/);
  assert.equal(f.attributes['data-theme'], 'dark');
});

test('Client: dados da portaria continuam fora da função de tradução', () => {
  assert.doesNotMatch(app, /\bt\(\s*(?:p\.(?:nome|empresa|cpf)|v\.(?:placa|cor|marca_modelo)|file\.name)\s*\)/);
  assert.match(app, /esc\(p\.nome\)/);
  assert.match(app, /esc\(v\.placa\)/);
  assert.match(app, /toLocaleTimeString\(locale,.*timeZone: 'America\/Cuiaba'/);
});
