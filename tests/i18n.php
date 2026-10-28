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
$_GET = ['q' => 'Entrada', 'lang' => 'en'];
$_POST = ['senha' => 'NAO_REENVIAR_POST'];
$selector = seletor_idioma();
i18nCheck(str_contains($selector, 'method="get"') && !str_contains($selector, 'NAO_REENVIAR_POST'), 'Seletor não deve enviar POST.');
i18nCheck(str_contains($selector, 'value="Entrada"') && !str_contains($selector, 'value="Entry"'), 'Filtros do usuário não podem ser traduzidos.');
(new ReflectionProperty(Auth::class, 'loaded'))->setValue(null, true);
(new ReflectionProperty(Auth::class, 'user'))->setValue(null, ['id' => 'test-i18n', 'papel' => 'porteiro']);
$filter = ['q' => 'Entrada', 'categoria' => '', 'situacao' => 'ativos'];
$row = ['id' => 'pessoa-id-original', 'nome' => 'Pessoas', 'empresa' => 'Entrada', 'categoria' => 'visitante', 'usuario_id' => null, 'ativo' => 1, 'cpf_exibicao' => '123.***.***-00', 'matricula' => '', 'telefone_principal' => '', 'email_principal' => '', 'documentos' => []];
$html = View::partial('pessoas/index', ['filtro' => $filter, 'rows' => [$row], 'total' => 1, 'pagina' => 1, 'paginas' => 1]);
i18nCheck(str_contains($html, '<strong>Pessoas</strong>') && str_contains($html, '>Entrada</td>'), 'Template traduziu nomes pessoais ou empresa.');
i18nCheck(str_contains($html, 'value="visitante"') && str_contains($html, 'Visitor'), 'Template deve manter valores de opções e traduzir seus rótulos.');
echo "I18n: {$checks} verificações passaram (idiomas, fallback, parâmetros, dados, enums e seletor GET).\n";
