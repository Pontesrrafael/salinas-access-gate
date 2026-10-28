<?php
declare(strict_types=1);

/**
 * Modelo em texto puro de notificação de chamado.
 * Por conformidade LGPD, não inclui notas internas, descrição livre, beneficiário nem respostas de formulário.
 *
 * @var string $tituloEvento
 * @var string $resumoEvento
 * @var string $destinatarioNome
 * @var string $numero
 * @var string $tituloChamado
 * @var string $areaNome
 * @var string $categoriaNome
 * @var string $statusRotulo
 * @var string $prioridadeRotulo
 * @var string $linkChamado
 */
$linhas = [
    'SALINAS GOLD MINERAÇÃO · SALINAS ACCESS GATE',
    $tituloEvento,
    str_repeat('-', 56),
];
if (!empty($destinatarioNome)) {
    $linhas[] = t('Olá, {nome}.', ['nome' => $destinatarioNome]);
    $linhas[] = '';
}
$linhas[] = $resumoEvento;
$linhas[] = '';
$linhas[] = t('Chamado') . ': ' . $numero;
$linhas[] = t('Título') . ': ' . $tituloChamado;
$linhas[] = t('Área') . ': ' . $areaNome;
if (!empty($categoriaNome) && $categoriaNome !== '—') {
    $linhas[] = t('Categoria do serviço') . ': ' . $categoriaNome;
}
$linhas[] = t('Status') . ': ' . $statusRotulo;
$linhas[] = t('Prioridade') . ': ' . $prioridadeRotulo;
$linhas[] = '';
$linhas[] = t('Acessar chamado no sistema') . ':';
$linhas[] = $linkChamado;
$linhas[] = '';
$linhas[] = str_repeat('-', 56);
$linhas[] = t('Mensagem automática do Salinas Access Gate. Por privacidade (LGPD), detalhes completos, anexos e histórico ficam restritos ao acesso autenticado no sistema.');

echo implode("\n", $linhas) . "\n";
