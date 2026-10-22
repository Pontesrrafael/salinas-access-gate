<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;

/** Conteúdo estático revisável. Nunca recebe dados de formulários ou código executável. */
final class Tutoriais
{
    public static function permitidos(): array
    {
        return array_filter(self::todos(), static fn (array $m): bool => Auth::check() && ($m['permissao'] === null || Auth::can($m['permissao'])));
    }

    public static function daTela(string $path): ?array
    {
        // Rotas específicas precedem seus módulos para respeitar a permissão real.
        if (preg_match('~^/registros/[^/]+$~', $path)) {
            return self::permitidos()['registros'] ?? null;
        }
        foreach (self::permitidos() as $m) {
            if ($path === $m['route'] || str_starts_with($path, $m['route'] . '/')) {
                return $m;
            }
        }
        return null;
    }

    public static function todos(): array
    {
        $defs = [
            'painel' => ['/painel', 'Painel', 'painel', null, 'Acompanhe a operação do dia e os alertas documentais.', ['Conferir presença por local', 'Consultar alertas e eventos recentes'], 'Consultar a operação', ['Abra o Painel e confira a data apresentada.', 'Leia os totais, a presença por local e os alertas documentais.', 'Consulte os eventos recentes e encaminhe divergências à supervisão.'], 'Os totais e eventos mostram a situação registrada pelo sistema.', 'Confira o local e os registros de entrada e saída antes de concluir que há divergência.'],
            'portaria' => ['/portaria', 'Portaria', 'portaria', 'portaria.operar', 'Registre entradas e saídas após conferir identidade, autorização e alertas.', ['Selecionar local e movimentação', 'Buscar pessoa e veículo', 'Conferir detalhes e anexos'], 'Registrar uma movimentação', ['Confira Portaria / Local e selecione ENTRADA ou SAÍDA.', 'Em Buscar pessoa, digite pelo menos 2 caracteres e selecione o cadastro correto.', 'Se houver veículo, pesquise e selecione a placa. Para acesso a pé, deixe em branco.', 'Confira solicitação aprovada para o dia, aptidão SSMA quando exigida, destino e responsável interno.', 'Revise os alertas e anexos. Somente após a conferência use CONFIRMAR ENTRADA ou CONFIRMAR SAÍDA.'], 'A mensagem de confirmação e os eventos recentes identificam a movimentação registrada.', 'Se houver bloqueio, interrompa a liberação e acione o responsável. Após falha, confira o histórico antes de repetir.'],
            'solicitacoes' => ['/solicitacoes', 'Solicitações', 'solicit', 'solicitacoes.ver', 'Prepare e acompanhe o acesso de visitantes e terceiros.', ['Criar uma solicitação', 'Gerar link externo', 'Acompanhar a decisão'], 'Solicitar acesso', ['Use Nova solicitação e informe nome, tipo, área responsável e data prevista.', 'Complete empresa, contatos, local e motivo da visita conforme necessário.', 'Marque Atividade de risco quando aplicável para encaminhar ao SSMA.', 'Revise e use Criar solicitação. Para preenchimento pelo visitante, use Gerar link externo.', 'Acompanhe Pendentes, Visitas de hoje, Criadas por mim ou Todas. A autorização cabe ao responsável da área, Segurança Patrimonial ou Admin/TI.'], 'A solicitação aparece na listagem com seu estado. Criar o pedido não autoriza a entrada.', 'Revise os filtros e a data. Para recusa ou pendência, procure a área responsável.'],
            'ssma' => ['/ssma', 'Verificação SSMA', 'escudo', 'ssma.verificar', 'Verifique a aptidão para a visita que envolve atividade de risco.', ['Consultar a fila', 'Conferir requisitos', 'Registrar aptidão'], 'Verificar uma solicitação', ['Na fila, abra Verificar na solicitação aguardando análise.', 'Confira a atividade, identificação, ASO e os requisitos exibidos no checklist.', 'Para Apto, atenda aos itens exigidos. Para Não Apto, informe a justificativa.', 'Revise e confirme a decisão. A autorização de acesso continua sob responsabilidade do autorizador.'], 'A decisão consta no histórico da verificação.', 'Se faltarem documentos, encaminhe a pendência ao responsável. Aptidão vale para a visita indicada.'],
            'pessoas' => ['/pessoas', 'Pessoas', 'pessoas', 'pessoas.ver', 'Mantenha identificação e documentação das pessoas utilizadas na portaria.', ['Pesquisar cadastros', 'Cadastrar conforme a permissão', 'Conferir documentação'], 'Consultar ou cadastrar uma pessoa', ['Pesquise antes de criar para evitar duplicidade. Para CPF, informe os 11 dígitos.', 'Se tiver permissão, use Nova pessoa e preencha Nome completo e Categoria.', 'Complete os dados disponíveis de documento, empresa e contatos. Revise antes de Cadastrar ou salvar.', 'No cadastro existente, os perfis autorizados mantêm documentos e validades.'], 'O cadastro aparece na busca e mostra os dados salvos.', 'Confira a pesquisa e a categoria. A ausência de botão pode indicar limitação do perfil.'],
            'veiculos' => ['/veiculos', 'Veículos', 'veiculo', 'veiculos.ver', 'Consulte a frota e mantenha os dados utilizados no acesso.', ['Pesquisar placa', 'Conferir modelo e cor', 'Manter comissionamento conforme o perfil'], 'Consultar ou cadastrar um veículo', ['Pesquise a placa antes de cadastrar.', 'Se disponível ao seu perfil, use Novo veículo e informe placa e tipo.', 'Complete marca/modelo, cor, empresa e TAG quando utilizada.', 'Informe comissionamento e validade conforme a condição real. Revise antes de Cadastrar ou salvar.'], 'O veículo aparece na consulta com os dados informados.', 'Não crie um cadastro duplicado para contornar um aviso. Procure o responsável pela frota.'],
            'presenca' => ['/presenca', 'Quem está no site', 'pessoas', 'registros.ver', 'Consulte quem possui entrada registrada em cada unidade.', ['Filtrar por local', 'Buscar pessoas', 'Consultar lista de evacuação'], 'Conferir presença', ['Confira o total e a distribuição por categoria.', 'Selecione o local e use a busca quando necessário.', 'Verifique os avisos e o histórico que originou a presença.', 'Use Lista de evacuação conforme o plano de emergência da unidade.'], 'A lista apresenta as pessoas cujo último movimento no local é uma entrada.', 'Se uma saída não foi registrada, a pessoa pode continuar presente. Confira fisicamente e comunique a supervisão.'],
            'indicadores' => ['/indicadores', 'Indicadores', 'grafico', 'indicadores.ver', 'Acompanhe presença, movimentações e solicitações por período e local.', ['Selecionar período', 'Ler indicadores', 'Exibir modo TV'], 'Consultar indicadores', ['Escolha período, datas e local e use Aplicar.', 'Leia os cartões, gráficos e alertas no contexto dos filtros.', 'Ajuste a atualização automática conforme a rotina.', 'Use Modo TV para ampliar e Sair do Modo TV para retornar.'], 'Os indicadores refletem o período e local escolhidos.', 'Presentes agora é a situação atual. Entradas e saídas seguem o período. Confira os filtros antes de comparar.'],
            'relatorios' => ['/relatorios', 'Relatórios', 'relatorio', 'relatorios.ver', 'Consulte o histórico das movimentações e exporte conferências autorizadas.', ['Filtrar registros', 'Abrir detalhes', 'Exportar CSV'], 'Consultar o histórico', ['Informe Início e Fim e restrinja Local, Sentido, Categoria ou Buscar.', 'Use Filtrar e confira os totais e as linhas exibidas.', 'Clique na data e hora para consultar os detalhes e anexos.', 'Use Exportar CSV quando necessário e guarde os dados conforme a orientação interna.'], 'A listagem corresponde aos filtros. O CSV fornece a extração completa do filtro.', 'Revise datas e local. Registros são imutáveis: comunique inconsistências à TI, sem repetir movimentos para corrigir o histórico.'],
            'registros' => ['/registros', 'Registro de acesso', 'relatorio', 'registros.ver', 'Confira os dados e anexos de uma movimentação já registrada.', ['Consultar pessoa e veículo', 'Conferir local e responsável', 'Consultar anexos'], 'Conferir uma movimentação', ['Confira data, horário, sentido e local.', 'Leia os dados da pessoa, do veículo, do responsável e as observações.', 'Abra apenas os anexos necessários à conferência autorizada.', 'Preserve o registro e comunique inconsistências à supervisão ou TI.'], 'Os detalhes correspondem à movimentação consultada.', 'Não repita entrada ou saída para alterar o histórico. Informe à TI a data e o contexto da ocorrência.'],
            'auditoria' => ['/auditoria', 'Auditoria', 'auditoria', 'auditoria.ver', 'Consulte a trilha de ações registradas pelo sistema.', ['Filtrar entidade e ação', 'Consultar usuário e datas'], 'Consultar a trilha', ['Selecione entidade, ação, usuário e período conforme a apuração.', 'Aplique os filtros e confira o contexto dos registros.', 'Encaminhe evidências somente às áreas autorizadas.'], 'A listagem apresenta os eventos que atendem aos filtros.', 'Revise os filtros. Não compartilhe dados pessoais ou técnicos fora da finalidade autorizada.'],
            'infleet' => ['/infleet', 'Infleet', 'veiculo', 'infleet.ver', 'Consulte frota, motoristas e percursos conforme a disponibilidade da integração.', ['Conferir o estado da integração', 'Consultar sincronização', 'Consultar percursos quando disponíveis'], 'Consultar a integração', ['Confira o aviso de disponibilidade e a última sincronização.', 'O Admin/TI configura token e organização no servidor e homologa o acesso antes de ativar.', 'Após liberação, confira cadastros importados antes de usá-los na portaria.', 'Consulte percursos apenas quando houver dados e mapas configurados.'], 'O estado e a última sincronização indicam o resultado disponível.', 'Se estiver desativada, procure a TI. Cor permanece no cadastro local. Odômetro depende da unidade fornecida pela API.'],
            'locais' => ['/locais', 'Locais', 'local', 'locais.gerir', 'Mantenha os pontos físicos utilizados no controle de acesso.', ['Consultar locais', 'Manter nome, tipo e endereço'], 'Manter um local', ['Confira se o ponto físico já existe.', 'Preencha nome, tipo Mina ou Escritório, endereço e situação.', 'Revise antes de salvar. Use o local físico correto na operação.'], 'O local salvo consta na listagem e, quando ativo, na seleção operacional.', 'Não duplique locais para contornar avisos. Confirme a identificação com a supervisão.'],
            'usuarios' => ['/usuarios', 'Usuários', 'usuarios', 'usuarios.gerir', 'Administre as contas e suas responsabilidades.', ['Consultar usuários', 'Definir perfil', 'Manter responsabilidades'], 'Manter uma conta', ['Confira se o usuário já possui conta.', 'Defina o perfil com as permissões necessárias.', 'Revise os vínculos por área e a marcação Segurança Patrimonial antes de salvar.', 'Oriente o usuário a utilizar sua conta individual.'], 'A conta mostra o perfil e os vínculos definidos.', 'Recursos do Active Directory dependem da configuração do servidor. Nunca compartilhe senhas.'],
            'configuracoes' => ['/configuracoes', 'Configurações', 'config', 'configuracoes.gerir', 'Mantenha parâmetros e responsáveis que orientam a operação.', ['Consultar parâmetros', 'Manter áreas responsáveis', 'Conferir integrações'], 'Revisar configurações', ['Confira organização, exigências e alertas operacionais.', 'Revise formulário público, validade dos links e áreas responsáveis.', 'Avalie o impacto antes de salvar. Não altere regras durante um atendimento para contornar bloqueios.', 'Valide a operação após uma alteração autorizada.'], 'Os parâmetros salvos aparecem na configuração.', 'Acione o responsável de TI se houver dúvida. A habilitação de integrações exige configuração e validação específicas.'],
        ];
        $all = [];
        foreach ($defs as $id => [$route, $title, $icon, $permission, $summary, $capabilities, $procedure, $steps, $expected, $recovery]) {
            $all[$id] = ['id' => $id, 'version' => 1, 'route' => $route, 'title' => $title, 'icon' => $icon, 'permissao' => $permission,
                'summary' => $summary, 'capabilities' => $capabilities,
                'procedures' => [['id' => 'principal', 'title' => $procedure, 'steps' => $steps, 'expectedResult' => $expected, 'recovery' => $recovery]],
                'tips' => ['Os botões disponíveis dependem do perfil e do estado do registro.', 'O tour apenas apresenta a tela. Ele não preenche nem confirma operações.'],
                'review' => 'Tecnologia da Informação · versão 1 · 22/10/2026',
                'tour' => [
                    ['id' => 'introducao', 'title' => $title, 'text' => $summary],
                    ['id' => 'acoes', 'target' => '[data-tour="acoes"]', 'title' => 'Ações da tela', 'text' => 'Confira as ações disponíveis ao seu perfil. O tour não aciona estes botões.', 'onMissing' => 'skip'],
                    ['id' => 'filtros', 'target' => '[data-tour="filtros"]', 'title' => 'Busca e filtros', 'text' => 'Use os campos para restringir a consulta antes de conferir os resultados.', 'onMissing' => 'skip'],
                    ['id' => 'conteudo', 'target' => '[data-tour="conteudo"]', 'title' => 'Conferência', 'text' => $expected . ' ' . $recovery, 'onMissing' => 'fallback-center'],
                    ['id' => 'ajuda', 'target' => '[data-tutorial-open]', 'title' => 'Ajuda sempre disponível', 'text' => 'Como usar reabre os procedimentos. Tutoriais, no menu, reúne os módulos do seu perfil.', 'onMissing' => 'fallback-center'],
                ],
            ];
        }
        array_splice($all['portaria']['tour'], 1, 0, [
            ['id' => 'local', 'target' => '[data-tour="portaria-local"]', 'title' => 'Local da operação', 'text' => 'Confira o local físico antes de registrar qualquer movimentação.', 'onMissing' => 'skip'],
            ['id' => 'pessoa', 'target' => '[data-tour="portaria-pessoa"]', 'title' => 'Identificação da pessoa', 'text' => 'Pesquise e selecione a pessoa correta. O tour não digita nem seleciona registros.', 'onMissing' => 'skip'],
            ['id' => 'veiculo', 'target' => '[data-tour="portaria-veiculo"]', 'title' => 'Veículo opcional', 'text' => 'Pesquise a placa quando houver veículo. Para acesso a pé, deixe em branco.', 'onMissing' => 'skip'],
        ]);
        return $all;
    }
}
