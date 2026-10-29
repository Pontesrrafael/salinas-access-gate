<?php
// Roteador isolado: nunca incluir na aplicação ou publicar em produção.
declare(strict_types=1);
if (PHP_SAPI !== 'cli-server' || !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(404); exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405); header('Allow: GET'); exit('A vitrine aceita somente GET.');
}
define('VITRINE_LOCAL', true);
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
$chamadoIdDemo=null;
if (preg_match('~^/chamados/([0-9a-f-]{36})$~', $path, $achado)) { $chamadoIdDemo=$achado[1]; $path='/chamados/demo'; }
if (str_starts_with($path, '/assets/') && !str_contains(rawurldecode($path), '..') && !str_contains($path, '\\')) {
    $asset = dirname(__DIR__, 2) . '/public' . $path;
    if (is_file($asset) && in_array(strtolower(pathinfo($asset, PATHINFO_EXTENSION)), ['css','js','png','jpg','jpeg','webp','svg','ico','woff','woff2'], true)) { return false; }
    http_response_code(404); exit;
}
// O PHP embutido pode calcular SCRIPT_NAME a partir da URL; fixa a raiz dos assets.
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';
require dirname(__DIR__, 2) . '/app/bootstrap.php';
use App\Core\{Auth, View, App, Http};
// A configuração de demonstração não usa conexões/segredos do .env.
(new ReflectionProperty(App::class, 'config'))->setValue(null, [
    'app' => ['env'=>'local','debug'=>true,'url'=>'http://127.0.0.1:8260','timezone'=>'America/Cuiaba'],
    'seguranca'=>['trusted_proxies'=>[]], 'upload'=>['max_files'=>5,'max_mb'=>4],
]);
Http::securityHeaders();
header('X-SAG-Vitrine: local-sem-banco');
set_error_handler(static function (int $n, string $m, string $f, int $l): never { throw new ErrorException($m, 0, $n, $f, $l); });
$perfil = $_GET['perfil'] ?? 'admin';
if (!array_key_exists($perfil, Auth::PAPEIS)) { http_response_code(400); exit('Perfil inválido.'); }
$usuario = ['id' => $perfil === 'colaborador' ? 'u-lia' : 'u-alex',
    'nome' => $perfil === 'colaborador' ? 'Lia Exemplo' : 'Alex Exemplo',
    'papel' => $perfil, 'email' => $perfil === 'colaborador' ? 'lia@example.invalid' : 'alex@example.invalid',
    'auth_origem'=>'local', 'mfa_ativo'=>1, 'seguranca_patrimonial'=>0,
    'perfis'=>$perfil === 'admin' ? array_keys(Auth::PAPEIS) : [$perfil,'colaborador']];
// Sessão só em memória do request: não abre sessão nem grava cookies de autenticação.
$_SESSION = ['perfil_ativo'=>$perfil, 'perfil_ativo_uid'=>$usuario['id'], '_csrf'=>str_repeat('d',64)];
(new ReflectionProperty(Auth::class,'loaded'))->setValue(null,true);
(new ReflectionProperty(Auth::class,'user'))->setValue(null,$usuario);
(new ReflectionProperty(Auth::class,'areasGeridas'))->setValue(null,['00000000-0000-4000-8000-000000000001']);
(new ReflectionProperty(Auth::class,'membroArea'))->setValue(null,in_array($perfil,['admin','gestor_area','ssma'],true));
$cfg = ['org_nome'=>'Salinas Gold Mineração LTDA — demonstração','encarregado_nome'=>'Equipe de privacidade (exemplo)',
    'encarregado_email'=>'privacidade@example.invalid','mfa_obrigatorio_admin'=>'1','chamados_ativo'=>'1',
    'horario_comercial_ativo'=>'1','horario_dias'=>'1,2,3,4,5','horario_inicio'=>'07:00','horario_fim'=>'17:00','feriados'=>'',
    'email_ativo'=>'1','chamados_fechar_dias'=>'7','retencao_chamados_meses'=>'24'];
(new ReflectionProperty(\App\Services\Settings::class,'cache'))->setValue(null,$cfg);
require __DIR__ . '/dados.php';
$v = match ($path) {
    '/chamados/abrir'=>'abrir', '/chamados/meus'=>'meus', '/chamados/fila'=>'fila',
    '/servicos/config'=>'config', '/conta/notificacoes'=>'notif', default=>'detalhe',
};
$dadosChamados = match ($v) {
    'abrir' => ['view' => 'chamados/abrir', 'titulo' => t('Abrir chamado'), 'areas' => $areas, 'areaId' => $id(1), 'categorias' => $catAbrir, 'categoriaId' => $id(11),
        'categoria' => $categorias[1], 'campos' => array_slice($campos, 0, 3), 'old' => [], 'textoSla' => null],
    'meus' => ['view' => 'chamados/index', 'titulo' => t('Meus chamados'), 'lista' => [$lista[1], $lista[4]], 'filtros' => $filtros, 'fila' => false, 'areas' => $areas, 'categorias' => $catAbrir,
        'membros' => [], 'total' => 2, 'pagina' => 1, 'limite' => 50, 'satisfacaoAreas' => []],
    'fila' => ['view' => 'chamados/index', 'titulo' => t('Fila de atendimento'), 'lista' => $lista, 'filtros' => $filtros, 'fila' => true, 'areas' => $areas, 'categorias' => $catAbrir,
        'membros' => array_map(static fn ($m) => ['id' => $m['id'], 'nome' => $m['nome']], $membros), 'total' => 5, 'pagina' => 1, 'limite' => 50,
        'satisfacaoAreas' => (($_GET['sat'] ?? '') === '0' ? [['nome' => 'Facilities', 'media' => null, 'avaliacoes' => 0], ['nome' => 'SSMA', 'media' => null, 'avaliacoes' => 0]] : [['nome' => 'Facilities', 'media' => 4.6, 'avaliacoes' => 38], ['nome' => 'SSMA', 'media' => 4.8, 'avaliacoes' => 24], ['nome' => 'Suprimentos', 'media' => null, 'avaliacoes' => 0]])],
    'notif' => ['view' => 'conta/notificacoes', 'titulo' => t('Notificações por e-mail'), 'descricao' => t('Escolha em quais ocasiões você deseja receber avisos dos chamados'),
        'usuario' => $usuario, 'emailUsuario' => $usuario['email'], 'origemConta' => $usuario['auth_origem'], 'emailAtivo' => true, 'atende' => $perfil !== 'colaborador',
        'preferencias' => array_merge(\App\Services\PreferenciasNotificacao::padrao(), ['solicitante.atribuido' => false]),
        'eventosSolicitante' => \App\Services\PreferenciasNotificacao::eventosSolicitante(), 'eventosAtendente' => \App\Services\PreferenciasNotificacao::eventosAtendente()],
    'config' => ['view' => 'servicos-config/index', 'titulo' => t('Áreas e formulários'), 'descricao' => t('Áreas de suporte, equipes, categorias com SLA e formulários dinâmicos'),
        'areas' => $areas, 'areaAtual' => $areas[0], 'usuarios' => [['id' => 'u-lia', 'nome' => 'Lia Exemplo', 'email' => 'lia@example.invalid', 'papel' => 'colaborador']],
        'membros' => $membros, 'categorias' => $categorias, 'campos' => $campos, 'editarArea' => '', 'editarCategoria' => '', 'editarCampo' => ''],
    default => (static function () use ($lista, $membros, $usuario, $perfil, $id, $categorias, $chamadoIdDemo): array {
        $solicitante = $perfil === 'colaborador';
        $c = $lista[1];
        foreach ($lista as $item) { if ($item['id']===$chamadoIdDemo) { $c=$item; } }
        if ($solicitante) { $c['status']='solucionado'; $c['solucionado_em']='2026-10-28 07:55:00'; }
        $c['descricao'] = 'O aparelho da sala 2 começou a pingar sobre a mesa durante a reunião das 7h.';
        $c['solicitante_id'] = $solicitante ? $usuario['id'] : 'u-lia';
        $eventos = $solicitante ? [
            ['id' => 'e1', 'tipo' => 'status', 'texto' => 'atribuido', 'usuario_nome' => 'Caio Modelo', 'created_at' => '2026-10-21 11:40:00'],
            ['id' => 'e2', 'tipo' => 'comentario', 'texto' => 'Equipe identificou o problema no dreno e iniciou a manutenção.', 'usuario_nome' => 'Caio Modelo', 'created_at' => '2026-10-28 07:40:00'],
            ['id' => 'e3', 'tipo' => 'solucao', 'texto' => 'Manutenção concluída e funcionamento conferido.', 'usuario_nome' => 'Caio Modelo', 'created_at' => '2026-10-28 07:55:00'],
        ] : [
            ['id' => 'e1', 'tipo' => 'atribuicao', 'texto' => 'Caio Modelo', 'usuario_nome' => 'Alex Exemplo', 'created_at' => '2026-10-28 07:35:00'],
            ['id' => 'e2', 'tipo' => 'comentario', 'texto' => 'Vamos verificar o dreno ainda hoje pela manhã.', 'usuario_nome' => 'Caio Modelo', 'created_at' => '2026-10-28 07:36:00'],
            ['id' => 'e3', 'tipo' => 'interno', 'texto' => 'Provável entupimento do dreno; separar kit de limpeza.', 'usuario_nome' => 'Caio Modelo', 'created_at' => '2026-10-28 07:40:00'],
        ];
        return ['view' => 'chamados/detalhe', 'titulo' => t('Chamado #{numero}', ['numero' => $c['numero']]), 'c' => $c, 'u' => $usuario, 'atende' => !$solicitante,
            'eventos' => $eventos, 'anexos' => $solicitante ? [] : [['id' => $id(301), 'nome_arquivo' => 'foto-ar-condicionado.jpg', 'tamanho_bytes' => 284000, 'acompanhamento_id' => null, 'campo_id' => $id(22)]],
            'respostas' => [['campo_id' => $id(20), 'rotulo' => 'Local exato da ocorrência', 'valor' => $solicitante ? 'Portaria principal' : 'Prédio administrativo, sala de reunião 2']],
            'membros' => array_map(static fn ($m) => ['id' => $m['id'], 'nome' => $m['nome']], $membros), 'satisfacao' => null,
            'destinos' => $solicitante ? [] : [['id' => $id(10), 'nome' => 'Manutenção predial', 'area_nome' => 'Facilities'], ['id' => $id(40), 'nome' => 'EPIs', 'area_nome' => 'SSMA', 'area_cor' => '#B54708']],
            'destinoCategoriaId' => '', 'destinoSelecionado' => null, 'camposDestino' => [], 'old' => []];
    })(),
};

// GETs locais usados pelo JavaScript real da portaria, sempre com dados fictícios.
if (str_starts_with($path, '/api/')) {
    $resposta = match ($path) {
        '/api/pessoas/busca', '/api/responsaveis/busca' => array_values(array_filter($pessoasDemo, static fn ($p) => str_contains(mb_strtolower($p['nome']), mb_strtolower((string) ($_GET['q'] ?? ''))))),
        '/api/veiculos/busca' => $veiculosDemo,
        '/api/portaria/analise' => ['pessoa'=>$pessoasDemo[0], 'veiculo'=>null,'documentos'=>array_map(static fn ($d)=>$d+['rotulo'=>TIPOS_DOC[$d['tipo']],'status'=>status_documento($d['validade']),'validade_fmt'=>fmt_data($d['validade'])],$documentosDemo),
            'presente_desde'=>null,'exige_solicitacao'=>true,'solicitacao_sugerida'=>$id(903),'solicitacao_manual'=>false,
            'solicitacoes'=>['vinculadas'=>[['id'=>$id(903),'visitante_nome'=>'Lia Exemplo','status'=>'aprovada','status_label'=>'Aprovada','risco'=>false,'area'=>'Facilities','responsavel'=>'Caio Modelo','empresa'=>'Oficina Modelo']],'candidatas'=>[],'outras'=>[]],
            'bloqueios'=>[],'avisos'=>[],'responsavel_padrao'=>null],
        default => null,
    };
    header('Content-Type: application/json; charset=utf-8');
    if ($resposta === null) { http_response_code(404); }
    echo json_encode($resposta, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR); exit;
}

// Este script só existe no roteador local. Intercepta submit antes do JS da aplicação.
if ($path === '/__demo.js') {
    header('Content-Type: text/javascript; charset=utf-8');
    echo <<<'JS'
// O JS real usa form.submit() em seletores automáticos; mantém esses envios na simulação.
HTMLFormElement.prototype.submit = function () { this.requestSubmit(); };
document.addEventListener('submit', event => {
  event.preventDefault(); event.stopImmediatePropagation();
  const form = event.target, fields = new FormData(form), button = event.submitter;
  const current = new URL(location.href), target = new URL(button?.hasAttribute('formaction') ? button.formAction : (form.action || location.href));
  const next = new URL(target.pathname, location.origin);
  next.searchParams.set('perfil', current.searchParams.get('perfil') || 'admin');
  next.searchParams.set('lang', document.documentElement.lang);
  if (form.method.toLowerCase() === 'get') {
    for (const [k,v] of fields) if (typeof v === 'string' && k !== '_csrf') next.searchParams.set(k,v);
  } else {
    next.searchParams.set('sucesso','1');
    if (target.pathname === '/login') next.pathname='/login/mfa';
    else if (target.pathname === '/login/mfa') next.pathname='/painel';
    else if (target.pathname === '/conta/perfil') {
      next.searchParams.set('perfil',fields.get('perfil'));
      next.pathname=fields.get('perfil') === 'porteiro' ? '/portaria' : fields.get('perfil') === 'colaborador' ? '/chamados/meus' : '/chamados/fila';
    } else if (target.pathname === '/chamados') { next.pathname='/chamados/demo'; next.searchParams.set('estado','novo'); }
    else if (/\/(atribuir|acompanhar|prorrogar|status)$/.test(target.pathname)) {
      next.pathname=target.pathname.replace(/\/(atribuir|acompanhar|prorrogar|status)$/,'');
      const action=target.pathname.split('/').pop();
      next.searchParams.set('estado',action === 'status' ? (fields.get('status') || 'fechado') : 'atribuido');
      next.searchParams.set('etapa',action);
      if (fields.get('acao') === 'avaliar') next.searchParams.set('avaliada','1');
    } else if (target.pathname === '/portaria/registrar') { next.pathname='/portaria'; next.searchParams.set('entrada','1'); }
    else if (target.pathname.startsWith('/servicos/config')) { next.pathname='/servicos/config'; next.searchParams.set('etapa', target.pathname.split('/').pop()); }
    else if (target.pathname === '/conta/mfa') next.searchParams.set('ativada','1');
    else if (target.pathname.startsWith('/conta/notificacoes')) next.pathname='/conta/notificacoes';
  }
  location.assign(next);
}, true);
// Preserva o perfil no menu da vitrine; links de idioma mantêm a própria query.
document.addEventListener('click', event => {
  const link=event.target.closest('a[href]');
  if (!link) return;
  const dest=new URL(link.href,location.href);
  if (dest.origin !== location.origin || !['http:','https:'].includes(dest.protocol)) return;
  if (!dest.searchParams.has('perfil')) dest.searchParams.set('perfil',new URL(location.href).searchParams.get('perfil') || 'admin');
  link.href=dest.href;
},true);
JS;
    exit;
}

$base = ['titulo'=>'Demonstração','locais'=>$locais,'old'=>[], 'flash'=>[
    ['tipo'=>'info','mensagem'=>'Vitrine local · dados fictícios · ações simuladas, sem gravação.'],
]];
if (isset($_GET['sucesso'])) { $base['flash'][]=['tipo'=>'sucesso','mensagem'=>'Demonstração concluída. Nenhum dado foi gravado.']; }
$layout = 'layout/app';
$dados = match ($path) {
    '/', '/painel' => ['view'=>'dashboard/index','titulo'=>'Painel','totais'=>['entradas'=>8,'saidas'=>5], 'presencaPorLocal'=>$locais,
        'pendentes'=>1,'aguardandoSsma'=>1,'visitasHoje'=>1,'alertasDocs'=>[$documentosDemo[0]],'ultimos'=>$registrosDemo],
    '/login'=>['view'=>'auth/login','titulo'=>'Entrar','ldap'=>true],
    '/login/mfa'=>['view'=>'auth/mfa','titulo'=>'Verificação em duas etapas'],
    '/solicitar-acesso'=>['view'=>'publico/form','titulo'=>'Solicitação de acesso','token'=>null,'fixo'=>null,'areas'=>$areasAcesso,'turnstile'=>null,'largo'=>true],
    '/privacidade'=>['view'=>'publico/privacidade','titulo'=>'Aviso de Privacidade','cfg'=>$cfg,'largo'=>true],
    '/portaria'=>['view'=>'portaria/index','titulo'=>'Portaria','local'=>$locais[0],'uploadCfg'=>['max_files'=>5,'max_mb'=>4],
        'exigirDestino'=>true,'idem'=>'demonstracao-sem-gravacao','respPadrao'=>null,'recentes'=>$registrosDemo,
        'confirmacao'=>isset($_GET['entrada']) ? ['titulo'=>'Entrada registrada — simulação','descricao'=>'Lia Exemplo · Oficina de manutenção','fotos'=>0,'avisos'=>[]] : null],
    '/solicitacoes'=>['view'=>'solicitacoes/index','titulo'=>'Solicitações','lista'=>$solicitacoesDemo,'aba'=>'todas','q'=>'','linkGerado'=>null,'areas'=>$areasAcesso,'validadeLinkHoras'=>72],
    '/ssma', '/ssma/demo'=>['view'=>'ssma/form','titulo'=>'Verificação SSMA','s'=>$solicitacoesDemo[1],'pessoa'=>$pessoasDemo[0],
        'docs'=>$documentosDemo,'checklist'=>['aso'=>'ASO válido','identificacao'=>'Documento de identificação','autorizacao'=>'Autorização para a atividade']],
    '/presenca'=>['view'=>'presenca/index','titulo'=>'Quem está no site','lista'=>$registrosDemo,'porCategoria'=>['colaborador'=>1,'terceiro'=>1,'visitante'=>1], 'alertaHoras'=>8,'localId'=>'','q'=>''],
    '/pessoas'=>['view'=>'pessoas/index','titulo'=>'Pessoas','rows'=>$pessoasDemo,'filtro'=>['q'=>'','categoria'=>'','situacao'=>'ativos'],'total'=>3,'pagina'=>1,'paginas'=>1],
    '/pessoas/nova'=>['view'=>'pessoas/form','titulo'=>'Nova pessoa','p'=>null,'docs'=>[],'usuariosLivres'=>[],'acessos'=>[]],
    '/veiculos'=>['view'=>'veiculos/index','titulo'=>'Veículos','rows'=>$veiculosDemo,'q'=>'','situacao'=>'ativos'],
    '/locais'=>['view'=>'locais/index','titulo'=>'Locais','rows'=>$locais,'editar'=>''],
    '/indicadores'=>['view'=>'indicadores/index','titulo'=>'Indicadores']+$indicadoresDemo,
    '/relatorios'=>['view'=>'relatorios/index','titulo'=>'Relatórios','rows'=>$registrosDemo,'resumo'=>['total'=>8,'entradas'=>5,'saidas'=>3],
        'f'=>['inicio'=>$data('-7 days'),'fim'=>$data('today'),'local_id'=>'','sentido'=>'','categoria'=>'','q'=>'']],
    '/auditoria'=>['view'=>'auditoria/index','titulo'=>'Auditoria','entidades'=>['auth','chamados'],'listaAcoes'=>['TROCA_PERFIL','ATRIBUIR'],'usuarios'=>$usuariosDemo,
        'f'=>['entidade'=>'','acao'=>'','usuario'=>'','inicio'=>$data('-7 days'),'fim'=>$data('today')], 'total'=>2,'pagina'=>1,'paginas'=>1,
        'rows'=>[['data_hora'=>$hora('-10 minutes'),'usuario_nome'=>'Alex Exemplo','entidade'=>'auth','registro_id'=>null,'acao'=>'TROCA_PERFIL','detalhes'=>'Perfil ativo: Porteiro (demonstração)','ip'=>'127.0.0.1'],
            ['data_hora'=>$hora('-20 minutes'),'usuario_nome'=>'Caio Modelo','entidade'=>'chamados','registro_id'=>$id(102),'acao'=>'ATRIBUIR','detalhes'=>'Chamado #124 — atendimento atribuído (demonstração)','ip'=>'127.0.0.1']]],
    '/usuarios'=>['view'=>'usuarios/index','titulo'=>'Usuários','rows'=>$usuariosDemo,'senhaTemp'=>null,'areasSemResp'=>[]],
    '/usuarios/demo'=>['view'=>'usuarios/form','titulo'=>'Perfis do usuário','u'=>$usuariosDemo[1],'areas'=>$areasAcesso,'pessoas'=>$pessoasDemo,'ldap'=>true],
    '/configuracoes'=>['view'=>'configuracoes/index','titulo'=>'Configurações','cfg'=>$cfg,'areas'=>$areasAcesso,'usuarios'=>$usuariosDemo,
        'linkPublico'=>'http://127.0.0.1:8260/solicitar-acesso','ldap'=>['enabled'=>true,'host'=>'ldaps://ad.example.invalid','port'=>636,'starttls'=>false,
            'base_dn'=>'DC=example,DC=invalid','bind_dn'=>'CN=leitura,DC=example,DC=invalid','tls_verify'=>true,'user_filter'=>'(objectClass=user)','auto_provision'=>true,'sync_roles'=>true,'intervalo_min'=>60],
        'ldapExt'=>true,'ldapTeste'=>null,'ldapUsuarios'=>1],
    '/conta/mfa'=>['view'=>'auth/conta-mfa','titulo'=>'Verificação em duas etapas','ativo'=>isset($_GET['ativada']),'ativadoEm'=>date('Y-m-d H:i:s'),
        'obrigatorio'=>false,'novo'=>['segredo'=>'JBSWY3DPEHPK3PXP','uri'=>'otpauth://totp/Demonstracao:lia%40example.invalid?secret=JBSWY3DPEHPK3PXP&issuer=Demonstracao']],
    '/tutoriais'=>['view'=>'tutoriais/index','titulo'=>'Tutoriais','modulos'=>\App\Services\Tutoriais::permitidos()],
    '/chamados/abrir','/chamados/meus','/chamados/fila','/servicos/config','/conta/notificacoes','/chamados/demo' => $dadosChamados,
    default=>null,
};
if ($dados === null) { http_response_code(404); exit('Tela não disponível na vitrine local.'); }
if ($path === '/chamados/abrir') {
    $areaEscolhida=$_GET['area_id'] ?? $id(1);
    $categoriaEscolhida=$_GET['categoria_id'] ?? $id(11);
    $dados['areaId']=$areaEscolhida;
    $dados['categorias']=array_values(array_filter($categorias,static fn ($c)=>$c['area_id']===$areaEscolhida));
    $dados['categoria']=null; $dados['categoriaId']='';
    foreach ($dados['categorias'] as $c) { if ($c['id']===$categoriaEscolhida) { $dados['categoria']=$c; $dados['categoriaId']=$c['id']; } }
    $dados['campos']=array_values(array_filter($campos,static fn ($f)=>$f['area_id']===$areaEscolhida && ($f['categoria_id']===null || $f['categoria_id']===$categoriaEscolhida)));
    foreach ($areas as $a) { if ($a['id']===$areaEscolhida && $dados['categoria']) { $dados['textoSla']=\App\Services\Chamados::textoSla($dados['categoria'],$a); } }
}
if (in_array($path,['/login','/login/mfa','/solicitar-acesso','/privacidade'],true)) { $layout='layout/simples'; }
if (isset($dados['c'])) {
    $estado = $_GET['estado'] ?? $dados['c']['status'];
    if (!array_key_exists($estado, \App\Services\Chamados::STATUS)) { http_response_code(400); exit('Estado inválido.'); }
    $dados['c']['status']=$estado;
    $dados['u'] = Auth::user();
    if ($estado === 'novo') {
        $dados['c']['respondido_em']=null; $dados['c']['solucionado_em']=null;
        $dados['c']['atendente_id']=null; $dados['c']['atendente_nome']=null; $dados['eventos']=[];
    }
    if (in_array($estado,['solucionado','fechado'],true)) { $dados['c']['solucionado_em']='2026-10-28 07:55:00'; }
    if ($estado === 'fechado') { $dados['c']['fechado_em']='2026-10-28 07:58:00'; }
    if (isset($_GET['avaliada'])) { $dados['satisfacao']=['nota'=>5,'comentario'=>'Problema resolvido. Obrigada pelo atendimento!','created_at'=>'2026-10-28 08:00:00']; }
    if (($_GET['etapa'] ?? '') === 'atribuir') { $dados['c']['atendente_nome']=$usuario['nome']; $dados['c']['atendente_id']=$usuario['id']; }
    if (($_GET['etapa'] ?? '') === 'prorrogar') { $dados['c']['prazo_prorrogado_min']=120; }
    if (isset($_GET['etapa'])) {
        $tipo = match ($_GET['etapa']) { 'acompanhar'=>'comentario','status'=>'solucao',default=>'status' };
        $texto = match ($_GET['etapa']) { 'acompanhar'=>'Equipe a caminho para verificar o equipamento.', 'prorrogar'=>'Prazo prorrogado em 2 h: aguardando peça de reposição.', 'status'=>'Manutenção concluída e funcionamento conferido.',default=>'atribuido' };
        $dados['eventos'][]=['id'=>'demo-evento','tipo'=>$tipo,'texto'=>$texto,'usuario_nome'=>$usuario['nome'],'created_at'=>'2026-10-28 08:00:00'];
    }
}
if ($path === '/servicos/config') {
    $dados['categorias']=array_values(array_filter($categorias,static fn ($c)=>$c['area_id']===$id(1)));
    $etapa=$_GET['etapa'] ?? '';
    if (in_array($etapa,['membros','categorias','campos'],true)) {
        $dados['membros'][]=['usuario_id'=>'u-lia','id'=>'u-lia','nome'=>'Lia Exemplo','email'=>'lia@example.invalid','papel'=>'atendente','chamados_abertos'=>0,'chamados_lista'=>[]];
    }
    if (in_array($etapa,['categorias','campos'],true)) {
        $dados['categorias'][]=array_replace($categorias[0],['id'=>$id(14),'nome'=>'Ventilação','caminho'=>'Ventilação','sla_resposta_min'=>30,'sla_solucao_min'=>240]);
    }
    if ($etapa === 'campos') {
        $dados['campos'][]=array_replace($campos[0],['id'=>$id(23),'rotulo'=>'Número do equipamento','ajuda'=>'Informe a identificação da etiqueta.','ordem'=>4]);
    }
}
if ($path === '/chamados/fila') { $dados['lista']=$lista; $dados['total']=count($lista); }
if ($path === '/chamados/meus') {
    $dados['lista'][0]['status']='solucionado'; $dados['lista'][0]['solucionado_em']='2026-10-28 07:55:00';
}
// Datas da referência deslocadas para agora, preservando os intervalos.
$deslocamento = time() - strtotime('2026-10-28 08:00:00');
if (str_starts_with($dados['view'], 'chamados/')) { array_walk_recursive($dados, static function (&$valor) use ($deslocamento): void {
    if (is_string($valor) && preg_match('/^2026-10-(2[1-9]|3[01])(?: \d{2}:\d{2}:\d{2})?$/', $valor)) {
        $valor=date(strlen($valor)===10 ? 'Y-m-d' : 'Y-m-d H:i:s',strtotime($valor)+$deslocamento);
    }
}); }
$view=$dados['view']; unset($dados['view']);
// Script externo permitido pela CSP, inserido apenas na resposta da vitrine.
ob_start();
View::render($view, array_replace($base,$dados), $layout);
$html=(string) ob_get_clean();
echo str_replace('</head>', '<script src="/__demo.js" defer></script></head>', $html);
