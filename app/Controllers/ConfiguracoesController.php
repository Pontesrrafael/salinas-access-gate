<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Http;
use App\Core\Uuid;
use App\Services\Ldap;
use App\Services\Settings;
use App\Services\Chamados;
use InvalidArgumentException;
use RuntimeException;

final class ConfiguracoesController extends Controller
{
    public function index(): void
    {
        Auth::authorize('configuracoes.gerir');
        $this->view('configuracoes/index', [
            'titulo'    => 'Configurações',
            'descricao' => 'Preferências gerais, regras da portaria e áreas',
            'cfg'       => Settings::all(),
            'areas'     => Database::all(
                'SELECT a.*, u.nome AS resp_nome,
                        (SELECT COUNT(*) FROM solicitacoes_acesso s WHERE s.area_id = a.id) AS solicitacoes
                   FROM areas a LEFT JOIN usuarios u ON u.id = a.responsavel_user_id ORDER BY a.ativo DESC, a.nome'
            ),
            'usuarios'  => Database::all('SELECT id, nome FROM usuarios WHERE ativo = 1 ORDER BY nome'),
            'linkPublico' => Http::absoluteUrl('/solicitar-acesso'),
            'ldap'        => Ldap::cfg(),
            'ldapExt'     => extension_loaded('ldap'),
            'ldapTeste'   => \App\Core\Session::get('ldap_teste'),
            'ldapUsuarios'=> (int) Database::value("SELECT COUNT(*) FROM usuarios WHERE auth_origem = 'ldap'"),
        ]);
        \App\Core\Session::forget('ldap_teste');
    }

    public function salvar(): void
    {
        $u = Auth::authorize('configuracoes.gerir');
        // Valida o bloco inteiro antes de persistir qualquer configuração.
        try {
            $calendario = Chamados::calendario([
                'dias' => Http::input('horario_dias', []), 'inicio' => Http::str('horario_inicio'),
                'fim' => Http::str('horario_fim'), 'feriados' => Http::str('feriados'),
            ]);
            $retencao = filter_var(Http::input('retencao_chamados_meses'), FILTER_VALIDATE_INT);
            $fecharDias = filter_var(Http::input('chamados_fechar_dias'), FILTER_VALIDATE_INT);
            if ($retencao === false || $retencao < 6 || $retencao > 240 || $fecharDias === false || $fecharDias < 0 || $fecharDias > 365) {
                throw new InvalidArgumentException(t('Retenção: 6 a 240 meses. Fechamento automático: 0 a 365 dias.'));
            }
            foreach (['horario_comercial_ativo', 'email_ativo'] as $chave) {
                if (!in_array(Http::input($chave, '0'), ['0', '1'], true)) {
                    throw new InvalidArgumentException(t('Opção de chamados inválida.'));
                }
            }
        } catch (InvalidArgumentException $e) { $this->falha($e->getMessage(), '/configuracoes'); }
        $valores = [
            'horario_comercial_ativo'     => Http::bool('horario_comercial_ativo') ? '1' : '0',
            'horario_dias'                => implode(',', $calendario['dias']),
            'horario_inicio'              => $calendario['inicio'],
            'horario_fim'                 => $calendario['fim'],
            'feriados'                    => implode(',', $calendario['feriados']),
            'retencao_chamados_meses'     => (string) $retencao,
            'chamados_fechar_dias'        => (string) $fecharDias,
            'email_ativo'                 => Http::bool('email_ativo') ? '1' : '0',
            'org_nome'                    => Http::nstr('org_nome', 200),
            'org_cnpj'                    => Http::nstr('org_cnpj', 20),
            'exigir_destino'              => Http::bool('exigir_destino') ? '1' : '0',
            'alerta_permanencia_horas'    => (string) max(1, min(48, (int) Http::str('alerta_permanencia_horas', '8'))),
            'link_externo_validade_horas' => (string) max(1, min(720, (int) Http::str('link_externo_validade_horas', '72'))),
            'form_publico_ativo'          => Http::bool('form_publico_ativo') ? '1' : '0',
            'encarregado_nome'            => Http::nstr('encarregado_nome', 150),
            'encarregado_email'           => filter_var(Http::str('encarregado_email'), FILTER_VALIDATE_EMAIL) ?: null,
            'retencao_fotos_dias'         => (string) max(7, min(3650, (int) Http::str('retencao_fotos_dias', '180'))),
            'retencao_solicitacoes_meses' => (string) max(1, min(120, (int) Http::str('retencao_solicitacoes_meses', '12'))),
            'retencao_registros_meses'    => (string) max(6, min(240, (int) Http::str('retencao_registros_meses', '24'))),
            'retencao_cadastros_meses'    => (string) max(6, min(240, (int) Http::str('retencao_cadastros_meses', '24'))),
            'retencao_auditoria_meses'    => (string) max(6, min(240, (int) Http::str('retencao_auditoria_meses', '60'))),
            'mfa_obrigatorio_admin'       => Http::bool('mfa_obrigatorio_admin') ? '1' : '0',
        ];
        Database::transaction(static function () use ($valores, $u): void {
            foreach ($valores as $k => $v) { Settings::set($k, $v, $u['id']); }
            Audit::log('configuracoes', null, 'UPDATE', $valores);
        });
        $this->ok('Configurações salvas.', '/configuracoes');
    }

    public function salvarArea(?string $id = null): void
    {
        Auth::authorize('configuracoes.gerir');
        $nome = Http::nstr('nome', 120);
        $resp = Http::str('responsavel_user_id');
        if ($nome === null) {
            $this->falha('Informe o nome da área.', '/configuracoes');
        }
        if (Database::value('SELECT 1 FROM areas WHERE nome = ? AND id <> ?', [$nome, $id ?? ''])) {
            $this->falha('Já existe uma área com este nome.', '/configuracoes');
        }
        $d = ['nome' => $nome, 'responsavel_user_id' => Uuid::valid($resp) ? $resp : null];
        if ($id === null) {
            $id = Uuid::v4();
            Database::insert('areas', ['id' => $id, ...$d]);
            Audit::log('areas', $id, 'INSERT', $d);
        } else {
            $this->uuidOu404($id);
            $d['ativo'] = Http::bool('ativo') ? 1 : 0;
            Database::update('areas', $d, 'id = ?', [$id]);
            Audit::log('areas', $id, 'UPDATE', $d);
        }
        $this->ok('Área salva.', '/configuracoes');
    }

    public function testarLdap(): void
    {
        Auth::authorize('configuracoes.gerir');
        if (!Ldap::disponivel()) {
            $this->falha('Integração LDAP desabilitada (LDAP_ENABLED) ou extensão PHP "ldap" ausente.', '/configuracoes');
        }
        try {
            $r = Ldap::testar();
            \App\Core\Session::set('ldap_teste', ['ok' => true, ...$r]);
            Audit::log('configuracoes', null, 'LDAP_TESTE', ['ok' => true, 'usuarios' => $r['usuarios'], 'tempo_ms' => $r['tempo_ms']]);
            $this->ok('Conexão com o Active Directory OK.', '/configuracoes');
        } catch (RuntimeException $e) {
            \App\Core\Session::set('ldap_teste', ['ok' => false, 'erro' => $e->getMessage()]);
            Audit::log('configuracoes', null, 'LDAP_TESTE', ['ok' => false, 'erro' => $e->getMessage()]);
            $this->falha('Falha na conexão com o AD: ' . $e->getMessage(), '/configuracoes');
        }
    }
}
