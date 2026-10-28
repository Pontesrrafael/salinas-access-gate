<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\App;

function expect(bool $ok, string $label): void
{
    if (!$ok) {
        throw new RuntimeException($label);
    }
}

/** @var array<string, string> $en */
$en = json_decode((string) file_get_contents(App::root('app/Lang/en.json')), true, 512, JSON_THROW_ON_ERROR);
/** @var array<string, string> $es */
$es = json_decode((string) file_get_contents(App::root('app/Lang/es.json')), true, 512, JSON_THROW_ON_ERROR);

// 1. Glossário fixo PT → EN/ES de termos de domínio (chaves exatas)
$glossarioFixo = [
    'Portaria' => ['en' => 'Gatehouse', 'es' => 'Portería'],
    'Terceiro' => ['en' => 'Contractor', 'es' => 'Contratista'],
    'Placa' => ['en' => 'License plate', 'es' => 'Matrícula'],
    'Matrícula' => ['en' => 'Employee ID', 'es' => 'Legajo'],
    'Solicitações' => ['en' => 'Access requests', 'es' => 'Solicitudes de acceso'],
];

foreach ($glossarioFixo as $chavePt => $esperado) {
    expect(
        isset($en[$chavePt]) && $en[$chavePt] === $esperado['en'],
        sprintf('en: glossário inválido para "%s" (esperado "%s", obtido "%s").', $chavePt, $esperado['en'], $en[$chavePt] ?? '')
    );
    expect(
        isset($es[$chavePt]) && $es[$chavePt] === $esperado['es'],
        sprintf('es: glossário inválido para "%s" (esperado "%s", obtido "%s").', $chavePt, $esperado['es'], $es[$chavePt] ?? '')
    );
}

// 2. Termos que nunca são traduzidos (siglas regulatórias e de negócio)
$siglasInvariaveis = ['SSMA', 'LGPD', 'ANPD', 'CPF', 'ASO', 'NR-22', 'DPO'];

foreach (['en' => $en, 'es' => $es] as $locale => $catalogo) {
    foreach ($catalogo as $chavePt => $traducao) {
        foreach ($siglasInvariaveis as $sigla) {
            if (str_contains($chavePt, $sigla)) {
                expect(
                    str_contains($traducao, $sigla),
                    sprintf('%s: a sigla "%s" deve ser preservada na tradução da chave: %s', $locale, $sigla, $chavePt)
                );
            }
        }
    }
}

// 3. Termos proibidos (consistência terminológica)
foreach ($en as $chavePt => $traducaoEn) {
    if (stripos($chavePt, 'portaria') !== false) {
        $usaGateSemGatehouse = stripos($traducaoEn, 'gate ') !== false && stripos($traducaoEn, 'gatehouse') === false;
        expect(
            !$usaGateSemGatehouse && stripos($traducaoEn, 'gatehouse') !== false,
            sprintf('en: chave com "portaria" não pode usar "gate " sem "gatehouse": %s', $chavePt)
        );
    }

    if (str_contains($chavePt, 'SSMA')) {
        expect(
            preg_match('/\bHSE\b/i', $traducaoEn) === 0 && !str_contains($traducaoEn, 'HSE'),
            sprintf('en: termo proibido "HSE" encontrado onde a chave PT contém "SSMA": %s', $chavePt)
        );
    }
}

foreach ($es as $chavePt => $traducaoEs) {
    expect(
        stripos($traducaoEs, 'Autoridad Nacional de Protección de Datos') === false,
        sprintf('es: termo proibido "Autoridad Nacional de Protección de Datos" encontrado (usar Agencia): %s', $chavePt)
    );
}

echo "Glossário i18n: termos de domínio, siglas invariáveis e consistência (en/es) OK.\n";
