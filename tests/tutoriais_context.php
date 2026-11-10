<?php
declare(strict_types=1);
// Registro estático e identidade fictícia; nenhuma conexão ao banco é necessária.
require dirname(__DIR__) . '/app/bootstrap.php';
use App\Core\Auth;
use App\Services\Tutoriais;

function contextExpect(bool $ok, string $message): void {
    if (!$ok) { throw new RuntimeException($message); }
}
function contextRole(string $role): void {
    (new ReflectionProperty(Auth::class, 'loaded'))->setValue(null, true);
    (new ReflectionProperty(Auth::class, 'user'))->setValue(null, ['id' => 'context-' . $role, 'papel' => $role]);
    (new ReflectionProperty(Auth::class, 'membroArea'))->setValue(null, false); // sem banco: fora das áreas de serviço
}
contextRole('admin');
// Rotas conferidas em app/routes.php; formulários não são listagens.
foreach ([
    ['/pessoas', '/pessoas/nova'],
    ['/veiculos', '/veiculos/novo'],
    ['/usuarios', '/usuarios/novo'],
    ['/ssma', '/ssma/solicitacao-ficticia'],
] as [$listPath, $formPath]) {
    $list = Tutoriais::daTela($listPath);
    $form = Tutoriais::daTela($formPath);
    contextExpect($list !== null && $form !== null, 'Ajuda ausente: ' . $formPath);
    contextExpect($list['id'] === $form['id'], 'Contexto deve preservar o módulo: ' . $formPath);
    contextExpect(($list['context'] ?? '') !== ($form['context'] ?? ''), 'Progresso precisa distinguir lista e formulário: ' . $formPath);
    contextExpect($list['procedures'] !== $form['procedures'], 'Procedimento deve explicar a tela atual: ' . $formPath);
    contextExpect($list['tour'] !== $form['tour'], 'Tour deve usar os alvos da tela atual: ' . $formPath);
    contextExpect(count($form['procedures'][0]['steps']) >= 2, 'Formulário precisa de instruções úteis: ' . $formPath);
    contextExpect($form['procedures'][0]['expectedResult'] !== '' && $form['procedures'][0]['recovery'] !== '', 'Ajuda precisa orientar conferência e recuperação: ' . $formPath);
    foreach ($form['tour'] as $step) {
        contextExpect(($step['target'] ?? '') !== '[data-tour="filtros"]', 'Formulário não deve ensinar filtro da listagem: ' . $formPath);
    }
}
contextRole('porteiro');
contextExpect(Tutoriais::daTela('/usuarios/novo') === null, 'Contexto não pode expor administração ao porteiro.');
contextExpect(Tutoriais::daTela('/ssma/solicitacao-ficticia') === null, 'Contexto deve respeitar ssma.verificar.');
contextExpect(Tutoriais::daTela('/pessoas/nova') !== null, 'Porteiro pode consultar ajuda de cadastro de pessoa.');
contextExpect(Tutoriais::daTela('/pessoas-outra') === null, 'Prefixo parcial não representa um módulo.');
echo "PASS: contextos de formulários, progresso separado e permissões.\n";
