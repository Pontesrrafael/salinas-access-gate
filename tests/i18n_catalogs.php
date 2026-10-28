<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
use App\Core\I18n;
use App\Core\App;
use App\Services\Tutoriais;

(new ReflectionProperty(I18n::class, 'locale'))->setValue(null, 'pt-BR');
$required = [];
foreach (['view-strings', 'js-strings'] as $name) {
    array_push($required, ...json_decode((string) file_get_contents(App::root('app/Lang/' . $name . '.json')), true, 512, JSON_THROW_ON_ERROR));
}
foreach (Tutoriais::todos() as $m) {
    foreach (['title', 'summary', 'review'] as $key) { $required[] = $m[$key]; }
    array_push($required, ...$m['capabilities'], ...$m['tips']);
    foreach ($m['procedures'] as $p) {
        foreach (['title', 'expectedResult', 'recovery'] as $key) { $required[] = $p[$key]; }
        array_push($required, ...$p['steps']);
    }
    foreach ($m['tour'] as $step) { array_push($required, $step['title'], $step['text']); }
}
$literalPattern = <<<'REGEX'
~(?<!\w)(?:t|T)\(\s*'((?:\\.|[^'\\])*)'~
REGEX;
$sources = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(App::root('app'), FilesystemIterator::SKIP_DOTS)) as $file) {
    if ($file->getExtension() === 'php') { $sources[] = $file->getPathname(); }
}
array_push($sources, ...glob(App::root('public/assets/js/*.js')));
foreach ($sources as $source) {
    preg_match_all($literalPattern, str_replace("\r\n", "\n", (string) file_get_contents($source)), $matches);
    foreach ($matches[1] as $key) { $required[] = str_replace(["\\'", "\\\\"], ["'", "\\"], $key); }
}
foreach (['en', 'es'] as $locale) {
    $catalog = json_decode((string) file_get_contents(App::root('app/Lang/' . $locale . '.json')), true, 512, JSON_THROW_ON_ERROR);
    foreach (array_unique($required) as $key) {
        if (!isset($catalog[$key]) || !is_string($catalog[$key]) || trim($catalog[$key]) === '') { throw new RuntimeException($locale . ': tradução ausente: ' . $key); }
    }
    foreach ($catalog as $key => $value) {
        preg_match_all('/\{[a-zA-Z_]\w*\}/', $key, $source);
        preg_match_all('/\{[a-zA-Z_]\w*\}/', $value, $translated);
        sort($source[0]); sort($translated[0]);
        if ($source[0] !== $translated[0]) { throw new RuntimeException($locale . ': parâmetros alterados: ' . $key); }
    }
    echo $locale . ': ' . count($catalog) . " traduções, cobertura dos inventários/tutoriais e placeholders OK.\n";
}
