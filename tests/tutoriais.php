<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
use App\Core\Auth;
use App\Services\Tutoriais;

function expect(bool $ok, string $label): void { if (!$ok) { throw new RuntimeException($label); } }
function role(?string $role, bool $membroArea = false): void {
    (new ReflectionProperty(Auth::class, 'loaded'))->setValue(null, true);
    (new ReflectionProperty(Auth::class, 'user'))->setValue(null, $role === null ? null : ['id' => 'test-' . $role, 'papel' => $role]);
    (new ReflectionProperty(Auth::class, 'membroArea'))->setValue(null, $role === null ? false : $membroArea);
}
role(null); expect(Tutoriais::permitidos() === [], 'Visitante não pode receber conteúdo autenticado.');
foreach (array_keys(Auth::PAPEIS) as $role) {
    role($role);
    foreach (Tutoriais::todos() as $id => $m) {
        expect(isset(Tutoriais::permitidos()[$id]) === ($m['permissao'] === null || Auth::can($m['permissao'])), 'Permissão incorreta: ' . $role . '/' . $id);
        expect(count($m['procedures'][0]['steps']) > 1 && $m['procedures'][0]['expectedResult'] !== '' && $m['procedures'][0]['recovery'] !== '', 'Procedimento incompleto.');
        expect(count(array_unique(array_column($m['tour'], 'id'))) === count($m['tour']), 'Etapa sem ID único.');
    }
}
role('porteiro');
expect(Tutoriais::daTela('/portaria') !== null, 'Porteiro precisa do tutorial da operação.');
expect(Tutoriais::daTela('/registros/registro-teste')['id'] === 'registros', 'Detalhe precisa de registros.ver.');
expect(Tutoriais::daTela('/configuracoes') === null, 'Ajuda não deve expor administração ao porteiro.');
expect(Tutoriais::daTela('/pessoas/nova')['id'] === 'pessoas', 'Subrota deve encontrar módulo.');
expect(Tutoriais::daTela('/pessoas-outra') === null, 'Prefixo parcial não é módulo.');
expect(Tutoriais::daTela('/chamados/abrir')['id'] === 'chamados-abrir', 'Todos os perfis autenticados abrem chamados.');
expect(Tutoriais::daTela('/chamados/meus')['id'] === 'chamados-meus', 'Todos os perfis autenticados consultam seus chamados.');
expect(Tutoriais::daTela('/chamados/fila') === null, 'Fila de atendimento exige vínculo na área ou Admin/TI.');
expect(Tutoriais::daTela('/chamados/chamado-teste')['id'] === 'chamados-meus', 'Detalhe do chamado usa tutorial de solicitante quando sem fila.');
expect(Tutoriais::daTela('/servicos/config') === null, 'Áreas e formulários é restrito ao Admin/TI.');
role('porteiro', true);
expect(Tutoriais::daTela('/chamados/fila')['id'] === 'chamados-fila', 'Membro de área recebe tutorial da fila.');
expect(Tutoriais::daTela('/chamados/chamado-teste')['id'] === 'chamados-fila', 'Membro de área recebe tutorial de atendimento no detalhe do chamado.');
role('ssma'); expect(Tutoriais::daTela('/portaria') === null, 'SSMA não opera portaria.');
role('supervisor'); expect(Tutoriais::daTela('/infleet/percursos')['id'] === 'infleet', 'Subrota de integração precisa de ajuda.');
role('admin'); expect(Tutoriais::daTela('/servicos/config')['id'] === 'servicos-config', 'Admin/TI recebe tutorial de Áreas e formulários.');
echo "Tutoriais: permissões dos quatro perfis, conteúdo e subrotas OK.\n";
