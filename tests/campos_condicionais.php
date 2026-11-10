<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Services\FormularioDinamico;

function expectCampo(bool $ok, string $mensagem): void { if (!$ok) { throw new RuntimeException($mensagem); } }

$campos = [
    ['id' => 'tipo', 'rotulo' => 'Tipo', 'tipo' => 'lista', 'opcoes' => ['Risco', 'Outro'], 'obrigatorio' => 1],
    ['id' => 'detalhe', 'rotulo' => 'Detalhe', 'tipo' => 'texto', 'depende_campo_id' => 'tipo', 'depende_valor' => 'Risco', 'depende_obrigatorio' => 1],
    ['id' => 'justificativa', 'rotulo' => 'Justificativa', 'tipo' => 'texto', 'depende_urgencia_max' => 2, 'depende_obrigatorio' => 1],
];
$inativo = FormularioDinamico::validar($campos, ['campos' => ['tipo' => 'Outro']], [], ['urgencia' => 4]);
expectCampo($inativo['erros'] === [] && !isset($inativo['valores']['detalhe']), 'Campo fora da condição não é gravado nem exigido.');
$ativo = FormularioDinamico::validar($campos, ['campos' => ['tipo' => 'Risco']], [], ['urgencia' => 2]);
expectCampo(count($ativo['erros']) === 2, 'Condições ativas exigem os dois campos.');
$ok = FormularioDinamico::validar($campos, ['campos' => ['tipo' => 'Risco', 'detalhe' => 'Queda', 'justificativa' => 'Risco imediato']], [], ['urgencia' => 1]);
expectCampo($ok['erros'] === [] && $ok['valores']['detalhe'] === 'Queda', 'Valores condicionais válidos são aceitos.');
echo "Campos condicionais: ocultação, obrigatoriedade e gravação OK.\n";
