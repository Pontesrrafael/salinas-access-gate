<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli-server' || !defined('VITRINE_LOCAL')) { http_response_code(404); exit; }
// Dados exclusivamente fict?cios, nunca consultados no banco.
$id = static fn (int $n): string => sprintf('00000000-0000-4000-8000-%012d', $n);
$areas = [
    ['id' => $id(1), 'sla_resposta_min' => 60, 'sla_solucao_min' => 1440, 'usar_horario_comercial' => 1, 'nome' => 'Facilities', 'slug' => 'facilities', 'descricao' => 'Manutenção predial, limpeza, copa e climatização', 'cor' => '#024B37', 'ativo' => 1, 'ordem' => 1, 'total_membros' => 4, 'total_categorias' => 6, 'total_campos' => 3],
    ['id' => $id(2), 'sla_resposta_min' => 240, 'sla_solucao_min' => 4320, 'usar_horario_comercial' => 1, 'nome' => 'Meio Ambiente', 'slug' => 'meio-ambiente', 'descricao' => 'Licenças, resíduos e monitoramento ambiental', 'cor' => '#3E7B27', 'ativo' => 1, 'ordem' => 2, 'total_membros' => 2, 'total_categorias' => 4, 'total_campos' => 2],
    ['id' => $id(3), 'sla_resposta_min' => 30, 'sla_solucao_min' => 480, 'usar_horario_comercial' => 1, 'nome' => 'SSMA', 'slug' => 'ssma', 'descricao' => 'Saúde, segurança e EPIs', 'cor' => '#B54708', 'ativo' => 1, 'ordem' => 3, 'total_membros' => 3, 'total_categorias' => 5, 'total_campos' => 4],
    ['id' => $id(4), 'sla_resposta_min' => 120, 'sla_solucao_min' => 2880, 'usar_horario_comercial' => 1, 'nome' => 'Suprimentos', 'slug' => 'suprimentos', 'descricao' => 'Compras, almoxarifado e contratos', 'cor' => '#1D4ED8', 'ativo' => 1, 'ordem' => 4, 'total_membros' => 3, 'total_categorias' => 4, 'total_campos' => 2],
];
$categorias = [
    ['id' => $id(10), 'area_id' => $id(1), 'parent_id' => null, 'nome' => 'Manutenção predial', 'caminho' => 'Manutenção predial', 'nivel' => 0, 'descricao' => 'Elétrica, hidráulica, portas e telhados', 'sla_resposta_min' => null, 'sla_solucao_min' => null, 'urgencia_padrao' => 3, 'usar_horario_comercial' => null, 'ativo' => 1, 'ordem' => 1],
    ['id' => $id(11), 'area_id' => $id(1), 'parent_id' => $id(10), 'nome' => 'Iluminação', 'caminho' => 'Manutenção predial › Iluminação', 'nivel' => 1, 'descricao' => 'Lâmpadas, refletores e iluminação externa', 'sla_resposta_min' => 60, 'sla_solucao_min' => 480, 'urgencia_padrao' => 3, 'usar_horario_comercial' => 1, 'ativo' => 1, 'ordem' => 2],
    ['id' => $id(12), 'area_id' => $id(1), 'parent_id' => null, 'nome' => 'Climatização', 'caminho' => 'Climatização', 'nivel' => 0, 'descricao' => 'Ar-condicionado e ventilação', 'sla_resposta_min' => 120, 'sla_solucao_min' => 2880, 'urgencia_padrao' => 4, 'usar_horario_comercial' => 1, 'ativo' => 1, 'ordem' => 3],
    ['id' => $id(13), 'area_id' => $id(1), 'parent_id' => null, 'nome' => 'Limpeza e copa', 'caminho' => 'Limpeza e copa', 'nivel' => 0, 'descricao' => 'Limpeza extra, reposição de copa', 'sla_resposta_min' => 30, 'sla_solucao_min' => 240, 'urgencia_padrao' => 4, 'usar_horario_comercial' => 0, 'ativo' => 1, 'ordem' => 4],
];
foreach ([[$id(40),$id(3),'EPIs'],[$id(41),$id(2),'Resíduos'],[$id(42),$id(4),'Compras']] as [$catId,$areaId,$nome]) {
    $categorias[]=array_replace($categorias[0],['id'=>$catId,'area_id'=>$areaId,'nome'=>$nome,'caminho'=>$nome,'descricao'=>'Serviço de demonstração.']);
}
$campos = [
    ['id' => $id(20), 'area_id' => $id(1), 'categoria_id' => null, 'categoria_nome' => null, 'rotulo' => 'Local exato da ocorrência', 'ajuda' => 'Prédio, sala ou ponto de referência', 'tipo' => 'texto', 'opcoes' => [], 'opcoes_lista' => [], 'obrigatorio' => 1, 'ordem' => 1, 'ativo' => 1],
    ['id' => $id(21), 'area_id' => $id(1), 'categoria_id' => $id(11), 'categoria_nome' => 'Iluminação', 'rotulo' => 'Ambiente afetado', 'ajuda' => null, 'tipo' => 'lista', 'opcoes' => ['Escritório', 'Área industrial', 'Pátio externo', 'Vestiário'], 'opcoes_lista' => ['Escritório', 'Área industrial', 'Pátio externo', 'Vestiário'], 'obrigatorio' => 1, 'ordem' => 2, 'ativo' => 1],
    ['id' => $id(22), 'area_id' => $id(1), 'categoria_id' => null, 'categoria_nome' => null, 'rotulo' => 'Foto do problema', 'ajuda' => 'Opcional: JPG, PNG ou PDF até 4 MB', 'tipo' => 'anexo', 'opcoes' => [], 'opcoes_lista' => [], 'obrigatorio' => 0, 'ordem' => 3, 'ativo' => 1],
];
$membros = [
    ['usuario_id' => 'u-caio', 'id' => 'u-caio', 'nome' => 'Caio Modelo', 'email' => 'caio@example.invalid', 'papel' => 'gestor', 'chamados_abertos' => 3, 'chamados_lista' => [['numero' => 118], ['numero' => 121], ['numero' => 124]]],
    ['usuario_id' => 'u-bia', 'id' => 'u-bia', 'nome' => 'Bia Modelo', 'email' => 'bia@example.invalid', 'papel' => 'atendente', 'chamados_abertos' => 2, 'chamados_lista' => [['numero' => 119], ['numero' => 125]]],
    ['usuario_id' => 'u-alex', 'id' => 'u-alex', 'nome' => 'Alex Exemplo', 'email' => 'alex@example.invalid', 'papel' => 'gestor', 'chamados_abertos' => 0, 'chamados_lista' => []],
];
$hoje = '2026-10-28';
$chamado = static fn (array $x): array => $x + [
    'area_id' => $id(1), 'categoria_id' => $id(11), 'descricao' => null, 'beneficiario_nome' => null, 'recorrente' => 0,
    'urgencia' => 3, 'impacto' => 2, 'prioridade' => 3, 'atendente_id' => null, 'atendente_nome' => null,
    'respondido_em' => null, 'solucionado_em' => null, 'fechado_em' => null, 'pendente_desde' => null, 'segundos_pendente' => 0,
    'fechado_automaticamente' => 0, 'solicitante_id' => 'u-lia', 'solicitante_nome' => 'Lia Exemplo', 'area_nome' => 'Facilities', 'area_cor' => '#024B37', 'categoria_nome' => 'Iluminação',
];
$lista = [
    $chamado(['id' => $id(101), 'numero' => 125, 'titulo' => 'Refletor apagado no pátio de carregamento', 'status' => 'novo', 'prioridade' => 2, 'urgencia' => 2, 'impacto' => 3, 'created_at' => "$hoje 07:42:00", 'prazo_resposta' => "$hoje 08:42:00", 'prazo_solucao' => "$hoje 15:42:00", 'solicitante_nome' => 'Davi Exemplo']),
    $chamado(['id' => $id(102), 'numero' => 124, 'titulo' => 'Ar-condicionado da sala de reunião pingando', 'status' => 'atribuido', 'atendente_nome' => 'Caio Modelo', 'categoria_nome' => 'Climatização', 'created_at' => "$hoje 07:10:00", 'prazo_resposta' => "$hoje 09:10:00", 'prazo_solucao' => '2026-10-30 07:10:00', 'respondido_em' => "$hoje 07:35:00", 'solicitante_nome' => 'Lia Exemplo']),
    $chamado(['id' => $id(103), 'numero' => 121, 'titulo' => 'Troca de EPIs do turno B', 'status' => 'pendente', 'area_nome' => 'SSMA', 'area_cor' => '#B54708', 'categoria_nome' => 'EPIs', 'atendente_nome' => 'Bia Modelo', 'prioridade' => 3, 'created_at' => '2026-10-27 14:05:00', 'prazo_resposta' => '2026-10-27 15:05:00', 'prazo_solucao' => "$hoje 10:05:00", 'respondido_em' => '2026-10-27 14:20:00', 'pendente_desde' => "$hoje 08:00:00", 'solicitante_nome' => 'Noel Exemplo']),
    $chamado(['id' => $id(104), 'numero' => 119, 'titulo' => 'Coleta de resíduos classe I no almoxarifado', 'status' => 'planejado', 'area_nome' => 'Meio Ambiente', 'area_cor' => '#3E7B27', 'categoria_nome' => 'Resíduos', 'atendente_nome' => 'Bia Modelo', 'prioridade' => 4, 'created_at' => '2026-10-26 09:30:00', 'prazo_resposta' => '2026-10-26 11:30:00', 'prazo_solucao' => '2026-10-31 09:30:00', 'respondido_em' => '2026-10-26 10:00:00', 'solicitante_nome' => 'Eva Exemplo']),
    $chamado(['id' => $id(105), 'numero' => 117, 'titulo' => 'Compra de cadeiras para a portaria', 'status' => 'solucionado', 'area_nome' => 'Suprimentos', 'area_cor' => '#1D4ED8', 'categoria_nome' => 'Compras', 'atendente_nome' => 'Caio Modelo', 'prioridade' => 5, 'urgencia' => 5, 'impacto' => 1, 'created_at' => '2026-10-21 11:00:00', 'prazo_resposta' => '2026-10-21 13:00:00', 'prazo_solucao' => '2026-10-24 11:00:00', 'respondido_em' => '2026-10-21 11:40:00', 'solucionado_em' => '2026-10-23 16:20:00', 'solicitante_nome' => 'Lia Exemplo']),
];
$filtros = array_fill_keys(['area_id', 'categoria_id', 'status', 'prioridade', 'atendente_id', 'prazo', 'q', 'ordem', 'visao'], '');
$catAbrir = array_map(static fn ($c) => ['id' => $c['id'], 'nome' => $c['nome'], 'caminho' => $c['caminho']] + $c, $categorias);
$lista[1]['categoria_id']=$id(12);
foreach ([2=>[3,40],3=>[2,41],4=>[4,42]] as $i=>[$a,$cat]) {
    $lista[$i]['area_id']=$id($a); $lista[$i]['categoria_id']=$id($cat);
}
$lista[]=array_replace($lista[1],['id'=>$id(106),'numero'=>116,'titulo'=>'Limpeza da sala de treinamento','status'=>'fechado',
    'solucionado_em'=>'2026-10-27 15:00:00','fechado_em'=>'2026-10-27 16:00:00']);

$data = static fn (string $delta): string => date('Y-m-d', strtotime($delta));
$hora = static fn (string $delta): string => date('Y-m-d H:i:s', strtotime($delta));
$locais = [
    ['id'=>$id(501),'nome'=>'Portaria principal — Unidade Exemplo','tipo'=>'mina','ativo'=>1,'endereco'=>'Via interna de demonstração, km 1','registros'=>8,'presentes'=>2,'n'=>2],
    ['id'=>$id(502),'nome'=>'Prédio administrativo','tipo'=>'escritorio','ativo'=>1,'endereco'=>'Via interna de demonstração, km 2','registros'=>4,'presentes'=>1,'n'=>1],
    ['id'=>$id(503),'nome'=>'Oficina de manutenção','tipo'=>'mina','ativo'=>1,'endereco'=>'Pátio de demonstração','registros'=>2,'presentes'=>0,'n'=>0],
];
$documentosDemo = [
    ['id'=>$id(601),'pessoa_id'=>$id(401),'nome'=>'Lia Exemplo','empresa'=>'Oficina Modelo','tipo'=>'aso','numero'=>'ASO-EXEMPLO','validade'=>$data('+20 days'),'emissao'=>$data('-11 months')],
    ['id'=>$id(602),'pessoa_id'=>$id(401),'nome'=>'Lia Exemplo','empresa'=>'Oficina Modelo','tipo'=>'nr35','numero'=>'NR-EXEMPLO','validade'=>$data('+120 days'),'emissao'=>$data('-8 months')],
];
$pessoasDemo = [];
foreach (['Lia Exemplo','Caio Modelo','Bia Modelo'] as $i=>$nome) {
    $pessoasDemo[] = ['id'=>$id(401+$i),'nome'=>$nome,'empresa'=>$i === 0 ? 'Oficina Modelo' : 'Empresa Exemplo',
        'categoria'=>['terceiro','colaborador','visitante'][$i], 'categoria_label'=>['Terceiro','Colaborador','Visitante'][$i],
        'ativo'=>1,'usuario_id'=>$i === 1 ? 'u-caio' : null,'usuario'=>null,'cpf_exibicao'=>'***.***.***-**',
        'matricula'=>'DEMO-'.($i+1),'telefone_principal'=>'(00) 00000-0000','email_principal'=>['lia','caio','bia'][$i].'@example.invalid',
        'documentos'=>$i === 0 ? $documentosDemo : [],'destino_padrao'=>'Oficina de manutenção','responsavel_interno_padrao'=>'Caio Modelo','observacoes_portaria_padrao'=>'Visita de demonstração.'];
}
$veiculosDemo = [
    ['id'=>$id(701),'placa'=>'DEM0A01','tipo'=>'caminhonete','marca_modelo'=>'Caminhonete de demonstração','cor'=>'Branca','empresa'=>'Oficina Modelo','ativo'=>1,'tag'=>'DEMO-01','comissionado_mina'=>1,'comissionamento_validade'=>$data('+90 days')],
    ['id'=>$id(702),'placa'=>'DEM0B02','tipo'=>'onibus','marca_modelo'=>'Van de demonstração','cor'=>'Prata','empresa'=>'Transporte Exemplo','ativo'=>1,'tag'=>'DEMO-02','comissionado_mina'=>0,'comissionamento_validade'=>null],
];
$registrosDemo = [];
foreach ($pessoasDemo as $i=>$p) {
    $registrosDemo[] = ['id'=>$id(801+$i),'registro_id'=>$id(801+$i),'data_hora'=>$hora('-'.(45+$i*30).' minutes'),
        'entrada_em'=>$hora('-'.(45+$i*30).' minutes'),'minutos'=>45+$i*30,'sentido'=>'entrada',
        'pessoa_nome'=>$p['nome'],'pessoa_empresa'=>$p['empresa'],'pessoa_categoria'=>$p['categoria'],'categoria'=>$p['categoria'],
        'local_nome'=>$locais[$i%2]['nome'],'placa'=>$i===0 ? 'DEM0A01' : null,'veiculo_placa'=>$i===0 ? 'DEM0A01' : null,
        'marca_modelo'=>$i===0 ? $veiculosDemo[0]['marca_modelo'] : null,'destino'=>'Oficina de manutenção',
        'porteiro_nome'=>'Alex Exemplo','fotos'=>0,'responsavel_interno_nome'=>'Caio Modelo','responsavel_interno_email'=>'caio@example.invalid',
        'sol_nome'=>$p['nome'],'sol_status'=>'aprovada','observacoes'=>'Registro fictício para o manual.'];
}
$solicitacoesDemo = [];
foreach (['pendente','aguardando_ssma','aprovada'] as $i=>$status) {
    $p=$pessoasDemo[$i===0 ? 2 : 0];
    $solicitacoesDemo[] = ['id'=>$id(901+$i),'visitante_nome'=>$p['nome'],'visitante_doc'=>'***.***.***-**',
        'empresa'=>$p['empresa'],'tipo'=>$p['categoria'],'contato_telefone'=>'(00) 00000-0000','contato_email'=>$p['email_principal'],
        'atividade_risco'=>$i===1 ? 1 : 0,'data_prevista'=>$data('today'),'area_nome'=>'Facilities','local_nome'=>$locais[0]['nome'],
        'origem'=>['interna','link','publica'][$i],'status'=>$status,'aguardando_preenchimento'=>0,'pessoa_nome'=>$p['nome'],
        'motivo'=>'Manutenção preventiva de equipamento — demonstração.','motivo_recusa'=>null,'status_observacao'=>null,
        'autorizador_nome'=>$i===2 ? 'Caio Modelo' : null,'autorizado_em'=>$i===2 ? $hora('-2 hours') : null,
        'criador_nome'=>'Alex Exemplo','pode_autorizar'=>true,'pode_link'=>false];
}
$usuariosDemo = [];
foreach (['Alex Exemplo','Caio Modelo','Lia Exemplo'] as $i=>$nome) {
    $usuariosDemo[] = ['id'=>['u-alex','u-caio','u-lia'][$i],'nome'=>$nome,'email'=>['alex','caio','lia'][$i].'@example.invalid',
        'papel'=>['admin','gestor_area','colaborador'][$i],'perfis'=>[['admin','supervisor','colaborador'],['gestor_area','colaborador'],['colaborador']][$i],
        'perfis_csv'=>['admin,supervisor,colaborador','gestor_area,colaborador','colaborador'][$i], 'ativo'=>1,'must_change_password'=>0,
        'auth_origem'=>$i===1 ? 'ldap' : 'local','ldap_usuario'=>$i===1 ? 'caio.exemplo' : '', 'ldap_sincronizado_em'=>$hora('-1 hour'),
        'seguranca_patrimonial'=>0,'areas'=>$i===1 ? 'Facilities' : null,'pessoa_id'=>null,'pessoa_nome'=>null,'ultimo_login_em'=>$hora('-30 minutes')];
}
$areasAcesso = array_map(static fn ($a)=>$a+['responsavel_user_id'=>'u-caio','resp_nome'=>'Caio Modelo','solicitacoes'=>3],$areas);
$indicadoresDemo = [
    'f'=>['preset'=>'hoje','ini'=>new DateTimeImmutable('today'),'fim'=>new DateTimeImmutable(),'localId'=>'','gran'=>'hora'],
    'modoTv'=>false,'atualizar'=>null,'periodoTxt'=>'Hoje — dados de demonstração','localNome'=>null,'geradoEm'=>date('Y-m-d H:i:s'),
    'resumo'=>['entradas'=>8,'saidas'=>5,'pessoas'=>8,'veiculos'=>2,'sem_solicitacao'=>0,'entradas_externos'=>4],
    'anterior'=>['entradas'=>10,'saidas'=>7,'pessoas'=>10], 'permanencia'=>75,
    'presenca'=>['total'=>3,'porCategoria'=>['colaborador'=>1,'visitante'=>1,'terceiro'=>1],'porLocal'=>$locais],
    'serie'=>['rotulos'=>['07h','08h','09h','10h'],'dicas'=>['07:00','08:00','09:00','10:00'],
        'series'=>['colaborador'=>[1,1,1,1],'visitante'=>[0,1,0,1],'terceiro'=>[1,0,1,0]]],
    'porHora'=>[0,0,0,0,0,0,0,2,2,2,2,0,0,0,0,0,0,0,0,0,0,0,0,0],
    'empresas'=>[['empresa'=>'Oficina Modelo','entradas'=>2,'pessoas'=>2],['empresa'=>'Transporte Exemplo','entradas'=>2,'pessoas'=>2]],
    'solicitacoes'=>['atual'=>['pendentes'=>1,'aguardando_ssma'=>1,'aprovadas_hoje'=>1],'taxaAprovacao'=>33,'total'=>3,'porStatus'=>['pendente'=>1,'aguardando_ssma'=>1,'aprovada'=>1]],
    'ssma'=>['apto'=>2,'nao_apto'=>0], 'documentos'=>['porTipo'=>['aso'=>['vencidos'=>0,'a_vencer'=>1,'validos'=>2]],'total'=>['vencidos'=>0,'a_vencer'=>1,'validos'=>2]],
    'alertas'=>['documentos'=>[],'permanencia'=>[],'horas'=>8], 'ultimos'=>$registrosDemo,
];

