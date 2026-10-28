/* Traduções locais da interface. Nunca traduz valores de formulários ou dados do usuário. */
(function () {
  'use strict';
  const node = document.getElementById('i18n-config');
  let config = {};
  try { config = node ? JSON.parse(node.textContent) : {}; } catch (_) { /* Português como fallback. */ }
  const locale = ['pt-BR', 'en', 'es'].includes(config.locale) ? config.locale : 'pt-BR';
  const messages = config.messages && typeof config.messages === 'object' ? config.messages : {};
  function t(source, params) {
    const key = String(source == null ? '' : source);
    const translated = Object.prototype.hasOwnProperty.call(messages, key) && typeof messages[key] === 'string' ? messages[key] : key;
    return translated.replace(/\{([A-Za-z0-9_]+)\}/g, (token, name) => params && Object.prototype.hasOwnProperty.call(params, name) ? String(params[name]) : token);
  }
  function plural(count, one, other, params) {
    return t(new Intl.PluralRules(locale).select(Number(count)) === 'one' ? one : other, { ...(params || {}), count });
  }
  function escape(value) {
    return String(value == null ? '' : value).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }
  window.SAGI18n = Object.freeze({ locale, t, plural, escape });
}());
