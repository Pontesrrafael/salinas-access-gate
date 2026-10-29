<?php
declare(strict_types=1);

/** @var App\Core\Router $router */

use App\Controllers\AuditoriaController;
use App\Controllers\AuthController;
use App\Controllers\ConfiguracoesController;
use App\Controllers\ChamadosController;
use App\Controllers\ContaNotificacoesController;
use App\Controllers\PerfilController;
use App\Controllers\ServicosConfigController;
use App\Controllers\DashboardController;
use App\Controllers\IndicadoresController;
use App\Controllers\InfleetController;
use App\Controllers\LocaisController;
use App\Controllers\MfaController;
use App\Controllers\PessoasController;
use App\Controllers\PortariaController;
use App\Controllers\PresencaController;
use App\Controllers\PublicoController;
use App\Controllers\RelatoriosController;
use App\Controllers\SolicitacoesController;
use App\Controllers\SsmaController;
use App\Controllers\UsuariosController;
use App\Controllers\VeiculosController;
use App\Controllers\TutoriaisController;

// ---- Saúde (monitoramento Zabbix/Uptime) ----
$router->get('/saude', [PublicoController::class, 'saude']);

// ---- Integração Infleet (opcional) ----
$router->get('/infleet', [InfleetController::class, 'index']);
$router->post('/infleet/sincronizar', [InfleetController::class, 'sincronizar']);
$router->post('/infleet/motoristas/cadastrar', [InfleetController::class, 'cadastrarMotorista']);
$router->get('/infleet/percursos', [InfleetController::class, 'percursos']);
$router->get('/api/infleet/percursos', [InfleetController::class, 'dadosPercursos']);

// ---- Autenticação ----
$router->get('/', [AuthController::class, 'inicio']);
$router->get('/login', [AuthController::class, 'formLogin']);
$router->post('/login', [AuthController::class, 'login']);
$router->post('/logout', [AuthController::class, 'logout']);
$router->get('/trocar-senha', [AuthController::class, 'formTrocarSenha']);
$router->post('/trocar-senha', [AuthController::class, 'trocarSenha']);
$router->get('/login/mfa', [MfaController::class, 'formDesafio']);
$router->post('/login/mfa', [MfaController::class, 'desafio']);
$router->get('/conta/mfa', [MfaController::class, 'conta']);
$router->post('/conta/mfa', [MfaController::class, 'ativar']);
$router->post('/conta/mfa/desativar', [MfaController::class, 'desativar']);

// ---- Operação ----
$router->get('/tutoriais', [TutoriaisController::class, 'index']);
$router->get('/painel', [DashboardController::class, 'index']);
$router->get('/indicadores', [IndicadoresController::class, 'index']);

$router->get('/portaria', [PortariaController::class, 'index']);
$router->post('/portaria/local', [PortariaController::class, 'trocarLocal']);
$router->post('/portaria/registrar', [PortariaController::class, 'registrar']);
$router->get('/api/portaria/analise', [PortariaController::class, 'analise']);
$router->get('/api/pessoas/busca', [PortariaController::class, 'buscarPessoas']);
$router->get('/api/veiculos/busca', [PortariaController::class, 'buscarVeiculos']);
$router->get('/api/responsaveis/busca', [PortariaController::class, 'buscarResponsaveis']);
$router->post('/api/pessoas/rapido', [PortariaController::class, 'cadastroRapidoPessoa']);
$router->post('/api/veiculos/rapido', [PortariaController::class, 'cadastroRapidoVeiculo']);
$router->get('/registros/{id}', [PortariaController::class, 'detalhe']);
$router->get('/anexos/{id}', [PortariaController::class, 'anexo']);

$router->get('/presenca', [PresencaController::class, 'index']);
$router->get('/presenca/imprimir', [PresencaController::class, 'imprimir']);

$router->get('/solicitacoes', [SolicitacoesController::class, 'index']);
$router->post('/solicitacoes', [SolicitacoesController::class, 'criar']);
$router->post('/solicitacoes/link', [SolicitacoesController::class, 'gerarLink']);
$router->post('/solicitacoes/{id}/aprovar', [SolicitacoesController::class, 'aprovar']);
$router->post('/solicitacoes/{id}/recusar', [SolicitacoesController::class, 'recusar']);
$router->get('/solicitacoes/{id}/link', [SolicitacoesController::class, 'copiarLink']);

$router->get('/ssma', [SsmaController::class, 'index']);
$router->get('/ssma/{id}', [SsmaController::class, 'form']);
$router->post('/ssma/{id}', [SsmaController::class, 'registrar']);

// ---- Serviços por área (todo POST é protegido pelo CSRF do roteador) ----
$router->get('/chamados/abrir', [ChamadosController::class, 'abrir']);
$router->post('/chamados', [ChamadosController::class, 'criar']);
$router->get('/chamados/meus', [ChamadosController::class, 'meus']);
$router->get('/chamados/fila', [ChamadosController::class, 'fila']);
$router->get('/chamados/anexos/{id}', [ChamadosController::class, 'anexo']);
$router->get('/chamados/{id}', [ChamadosController::class, 'detalhe']);
$router->post('/chamados/{id}/acompanhar', [ChamadosController::class, 'acompanhar']);
$router->post('/chamados/{id}/atribuir', [ChamadosController::class, 'atribuir']);
$router->post('/chamados/{id}/status', [ChamadosController::class, 'status']);
$router->post('/chamados/{id}/prorrogar', [ChamadosController::class, 'prorrogar']);
$router->post('/conta/perfil', [PerfilController::class, 'trocar']);
$router->get('/conta/notificacoes', [ContaNotificacoesController::class, 'index']);
$router->post('/conta/notificacoes', [ContaNotificacoesController::class, 'salvar']);
$router->get('/servicos/config', [ServicosConfigController::class, 'index']);
$router->post('/servicos/config/areas', [ServicosConfigController::class, 'salvarArea']);
$router->post('/servicos/config/areas/{id}', [ServicosConfigController::class, 'salvarArea']);
$router->post('/servicos/config/areas/{id}/membros', [ServicosConfigController::class, 'salvarMembro']);
$router->post('/servicos/config/areas/{id}/membros/{usuario_id}/remover', [ServicosConfigController::class, 'removerMembro']);
$router->post('/servicos/config/categorias', [ServicosConfigController::class, 'salvarCategoria']);
$router->post('/servicos/config/categorias/{id}', [ServicosConfigController::class, 'salvarCategoria']);
$router->post('/servicos/config/campos', [ServicosConfigController::class, 'salvarCampo']);
$router->post('/servicos/config/campos/{id}', [ServicosConfigController::class, 'salvarCampo']);
$router->post('/servicos/config/campos/{id}/mover', [ServicosConfigController::class, 'moverCampo']);
$router->post('/servicos/config/campos/{id}/alternar', [ServicosConfigController::class, 'alternarCampo']);

// ---- Cadastros ----
$router->get('/pessoas', [PessoasController::class, 'index']);
$router->get('/pessoas/nova', [PessoasController::class, 'nova']);
$router->post('/pessoas', [PessoasController::class, 'criar']);
$router->get('/pessoas/exportar', [PessoasController::class, 'exportar']);
$router->get('/pessoas/{id}', [PessoasController::class, 'editar']);
$router->post('/pessoas/{id}', [PessoasController::class, 'atualizar']);
$router->post('/pessoas/{id}/excluir', [PessoasController::class, 'excluir']);
$router->post('/pessoas/{id}/cpf', [PessoasController::class, 'revelarCpf']);
$router->post('/pessoas/{id}/dados-titular', [PessoasController::class, 'dadosTitular']);
$router->post('/pessoas/{id}/documentos', [PessoasController::class, 'adicionarDocumento']);
$router->post('/pessoas/{id}/documentos/{doc}/excluir', [PessoasController::class, 'excluirDocumento']);
$router->post('/pessoas/{id}/vinculo', [PessoasController::class, 'vincularUsuario']);

$router->get('/veiculos', [VeiculosController::class, 'index']);
$router->get('/veiculos/novo', [VeiculosController::class, 'novo']);
$router->post('/veiculos', [VeiculosController::class, 'criar']);
$router->get('/veiculos/{id}', [VeiculosController::class, 'editar']);
$router->post('/veiculos/{id}', [VeiculosController::class, 'atualizar']);
$router->post('/veiculos/{id}/excluir', [VeiculosController::class, 'excluir']);

$router->get('/locais', [LocaisController::class, 'index']);
$router->post('/locais', [LocaisController::class, 'salvar']);
$router->post('/locais/{id}', [LocaisController::class, 'salvar']);
$router->post('/locais/{id}/excluir', [LocaisController::class, 'excluir']);

// ---- Gestão ----
$router->get('/relatorios', [RelatoriosController::class, 'index']);
$router->get('/relatorios/csv', [RelatoriosController::class, 'csv']);
$router->get('/auditoria', [AuditoriaController::class, 'index']);

$router->get('/usuarios', [UsuariosController::class, 'index']);
$router->get('/api/ldap/busca', [UsuariosController::class, 'buscarAd']);
$router->get('/usuarios/novo', [UsuariosController::class, 'novo']);
$router->post('/usuarios', [UsuariosController::class, 'criar']);
$router->get('/usuarios/{id}', [UsuariosController::class, 'editar']);
$router->post('/usuarios/{id}', [UsuariosController::class, 'atualizar']);
$router->post('/usuarios/{id}/sincronizar-ad', [UsuariosController::class, 'sincronizarAd']);

$router->get('/configuracoes', [ConfiguracoesController::class, 'index']);
$router->post('/configuracoes', [ConfiguracoesController::class, 'salvar']);
$router->post('/configuracoes/areas', [ConfiguracoesController::class, 'salvarArea']);
$router->post('/configuracoes/ldap/testar', [ConfiguracoesController::class, 'testarLdap']);
$router->post('/configuracoes/areas/{id}', [ConfiguracoesController::class, 'salvarArea']);

// ---- Formulário público (sem login) ----
$router->get('/privacidade', [PublicoController::class, 'privacidade']);
$router->get('/solicitar-acesso', [PublicoController::class, 'formPublico']);
$router->post('/solicitar-acesso', [PublicoController::class, 'enviarPublico']);
$router->get('/solicitar-acesso/{token}', [PublicoController::class, 'formToken']);
$router->post('/solicitar-acesso/{token}', [PublicoController::class, 'enviarToken']);
