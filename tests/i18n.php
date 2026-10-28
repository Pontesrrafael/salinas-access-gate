<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Auth;
use App\Core\I18n;
use App\Core\View;

$checks = 0;
function i18nCheck(bool $ok, string $label): void
{
    global $checks;
    if (!$ok) { throw new RuntimeException($label); }
    $checks++;
}
function language(array $query = [], array $cookie = [], string $method = 'GET'): string
{
    (new ReflectionProperty(I18n::class, 'locale'))->setValue(null, null);
    $_GET = $query;
    $_COOKIE = $cookie;
    $_SERVER['REQUEST_METHOD'] = $method;
    I18n::init();
    return I18n::current();
}
(new ReflectionProperty(I18n::class, 'catalogs'))->setValue(null, [
    'en' => ['Idioma' => 'Language', 'Alterar idioma' => 'Change language', 'Olá, {nome}' => 'Hello, {nome}', 'Pessoas' => 'People', 'Entrada' => 'Entry', 'Visitante' => 'Visitor'],
    'es' => ['Idioma' => 'Idioma', 'Pessoas' => 'Personas', 'Olá, {nome}' => 'Hola, {nome}'],
]);
i18nCheck(language() === 'pt-BR', 'Português deve ser o padrão.');
i18nCheck(language(['lang' => 'en']) === 'en', 'GET deve selecionar inglês.');
i18nCheck(language([], ['sag_idioma' => 'es']) === 'es', 'Cookie deve preservar espanhol.');
i18nCheck(language(['lang' => '../../config'], ['sag_idioma' => 'en']) === 'en', 'Idioma não pode ser caminho de arquivo.');
i18nCheck(language(['lang' => ['es']], ['sag_idioma' => ['en']]) === 'pt-BR', 'Entradas em array devem ser rejeitadas.');
i18nCheck(language(['lang' => 'es'], ['sag_idioma' => 'en'], 'POST') === 'en', 'POST de negócio não altera preferência.');
i18nCheck(language(['lang' => 'pt-BR'], ['sag_idioma' => 'es']) === 'pt-BR', 'Seleção explícita deve permitir voltar ao português.');
language(['lang' => 'en']);
i18nCheck(t('Idioma') === 'Language', 'Tradução conhecida não encontrada.');
i18nCheck(t('Texto não traduzido') === 'Texto não traduzido', 'Fallback deve manter o português.');
i18nCheck(t('Olá, {nome}', ['nome' => 'Nome original']) === 'Hello, Nome original', 'Parâmetros não devem ser traduzidos.');
i18nCheck(label_t(['visitante' => 'Visitante'], 'visitante') === 'Visitor', 'Rótulo de enum deve traduzir.');
i18nCheck(label_t(['visitante' => 'Visitante'], 'outro', 'Pessoas') === 'Pessoas', 'Fallback de dados não deve ser traduzido.');
$payload = I18n::response([
    'pessoa' => ['id' => 'Entrada', 'nome' => 'Pessoas', 'categoria' => 'visitante', 'categoria_label' => 'Visitante'],
    'documentos' => [['rotulo' => 'Entrada', 'numero' => 'Pessoas']],
    'avisos' => ['Entrada'], 'bloqueios' => ['Texto não traduzido'],
]);
i18nCheck($payload['pessoa']['nome'] === 'Pessoas' && $payload['pessoa']['id'] === 'Entrada' && $payload['pessoa']['categoria'] === 'visitante', 'Dados e enums da API foram traduzidos.');
i18nCheck($payload['pessoa']['categoria_label'] === 'Visitor' && $payload['documentos'][0]['rotulo'] === 'Entry' && $payload['documentos'][0]['numero'] === 'Pessoas', 'API deve traduzir somente rótulos.');
i18nCheck($payload['avisos'] === ['Entry'] && $payload['bloqueios'] === ['Texto não traduzido'], 'Avisos ou fallback alterados.');
$_SERVER['REQUEST_URI'] = '/pessoas?q=Entrada&lang=en';
$_GET = ['q' => 'Entrada', 'lang' => 'en', 'pagina' => '2', 'filtros' => ['ativos', 'visitantes'], 'especial' => '"><script>alert(1)</script>&'];
$_POST = ['senha' => 'NAO_REENVIAR_POST'];
$selector = seletor_idioma();
i18nCheck(str_starts_with($selector, '<details class="seletor-idioma"><summary') && !str_contains($selector, '<form') && !str_contains($selector, '<select') && !str_contains($selector, 'NAO_REENVIAR_POST'), 'Seletor deve funcionar com links GET, sem formulário ou POST.');
i18nCheck(!str_contains($selector, '<script') && !str_contains($selector, ' style=') && !str_contains($selector, ' onclick='), 'Seletor não pode acrescentar scripts ou estilos inline.');
preg_match_all('/<a href="([^"]+)" lang="([^"]+)" hreflang="([^"]+)"([^>]*)>(.*?)<\/a>/', $selector, $options, PREG_SET_ORDER);
i18nCheck(count($options) === 3 && substr_count($selector, 'aria-current="true"') === 1, 'Seletor deve ter três opções e somente um idioma atual.');
$names = ['pt-BR' => 'Português (Brasil)', 'es' => 'Español (España)', 'en' => 'English (United States)'];
$flags = ['pt-BR' => 'br', 'es' => 'es', 'en' => 'us'];
foreach ($options as [, $href, $locale, $hreflang, $attrs, $body]) {
    $href = html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    parse_str((string) parse_url($href, PHP_URL_QUERY), $params);
    i18nCheck(parse_url($href, PHP_URL_PATH) === url('/pessoas') && $params === array_replace($_GET, ['lang' => $locale]), 'Link deve preservar rota e parâmetros, inclusive filtros e caracteres especiais.');
    i18nCheck(substr_count($href, 'lang=') === 1 && $hreflang === $locale, 'Link deve ter lang único e identificar o idioma de destino.');
    i18nCheck(str_contains($body, 'img/bandeiras/' . $flags[$locale] . '.svg?') && str_contains($body, 'alt="" width="20" height="15"'), 'Opção deve ter bandeira SVG decorativa de 20x15.');
    i18nCheck(str_contains($body, $names[$locale]) && str_contains($attrs, 'aria-current="true"') === ($locale === 'en'), 'Nome no próprio idioma e marcação do idioma atual incorretos.');
}
foreach (['pt-BR' => 'PT-BR', 'es' => 'ES', 'en' => 'EN'] as $locale => $code) {
    language(['lang' => $locale]);
    $currentSelector = seletor_idioma();
    i18nCheck(str_contains($currentSelector, '<summary aria-label="' . e(t('Alterar idioma') . ': ' . $names[$locale]) . '"><img src="' . e(asset('img/bandeiras/' . $flags[$locale] . '.svg')) . '" alt="" width="20" height="15"><span class="idioma-codigo">' . $code . '</span></summary>'), 'Resumo deve identificar o idioma atual mesmo quando o código fica oculto no celular.');
    foreach ($names as $name) { i18nCheck(str_contains($currentSelector, $name), 'Nomes dos idiomas não podem ser traduzidos.'); }
}
language(['lang' => 'en']);
(new ReflectionProperty(Auth::class, 'loaded'))->setValue(null, true);
(new ReflectionProperty(Auth::class, 'user'))->setValue(null, ['id' => 'test-i18n', 'papel' => 'porteiro']);
(new ReflectionProperty(Auth::class, 'membroArea'))->setValue(null, false); // sem banco: porteiro fora das áreas de serviço
$filter = ['q' => 'Entrada', 'categoria' => '', 'situacao' => 'ativos'];
$row = ['id' => 'pessoa-id-original', 'nome' => 'Pessoas', 'empresa' => 'Entrada', 'categoria' => 'visitante', 'usuario_id' => null, 'ativo' => 1, 'cpf_exibicao' => '123.***.***-00', 'matricula' => '', 'telefone_principal' => '', 'email_principal' => '', 'documentos' => []];
$html = View::partial('pessoas/index', ['filtro' => $filter, 'rows' => [$row], 'total' => 1, 'pagina' => 1, 'paginas' => 1]);
i18nCheck(str_contains($html, '<strong>Pessoas</strong>') && str_contains($html, '>Entrada</td>'), 'Template traduziu nomes pessoais ou empresa.');
i18nCheck(str_contains($html, 'value="visitante"') && str_contains($html, 'Visitor'), 'Template deve manter valores de opções e traduzir seus rótulos.');
$layout = View::partial('layout/app', ['titulo' => 'Pessoas', 'content' => '', 'acoes' => '<a href="/pessoas/nova">Nova pessoa</a>']);
i18nCheck(substr_count($layout, '<details class="seletor-idioma">') === 1 && strpos($layout, '<details class="seletor-idioma">') > strpos($layout, 'class="topo-acoes"') && strpos($layout, '<details class="seletor-idioma">') < strpos($layout, '</header>'), 'Layout autenticado deve ter apenas um seletor, no topo.');
i18nCheck(strpos($layout, '<details class="seletor-idioma">') > strpos($layout, 'Nova pessoa</a>'), 'Seletor deve ser o último item das ações do topo.');
$simple = View::partial('layout/simples', ['content' => '']);
i18nCheck(substr_count($simple, '<details class="seletor-idioma">') === 1 && strpos($simple, 'data-tema') < strpos($simple, '<details class="seletor-idioma">') && strpos($simple, '<details class="seletor-idioma">') < strpos($simple, '<main'), 'Layout simples deve ter seletor à direita do botão de tema, antes do conteúdo.');

echo "I18n: {$checks} verificações passaram (idiomas, fallback, parâmetros, dados, enums, seletor GET e layouts).\n";
