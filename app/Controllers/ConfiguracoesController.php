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
        $valores = [
            'org_nome'                    => Http::nstr('org_nome', 200),
            'org_cnpj'                    => Http::nstr('org_cnpj', 20),
            'exigir_destino'              => Http::bool('exigir_destino') ? '1' : '0',
            'alerta_permanencia_horas'    => (string) max(1, min(48, (int) Http::str('alerta_permanencia_horas', '8'))),
            'link_externo_validade_horas' => (string) max(1, min(720, (int) Http::str('link_externo_validade_horas', '72'))),
            'form_publico_ativo'          => Http::bool('form_publico_ativo') ? '1' : '0',
        ];
        foreach ($valores as $k => $v) {
            Settings::set($k, $v, $u['id']);
        }
        Audit::log('configuracoes', null, 'UPDATE', $valores);
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

    /**
     * Liga/desliga e configura o AD/LDAP pela tela. Valores gravados em configuracoes ("ldap.*")
     * sobrescrevem o .env. A senha da conta de serviço é cifrada e nunca vai para a auditoria.
     */
    public function salvarLdap(): void
    {
        $u = Auth::authorize('configuracoes.gerir');
        $ligado = Http::bool('enabled');
        $dn = static fn (string $k): string => trim((string) Http::nstr($k, 500));
        $v = [
            'enabled'        => $ligado ? '1' : '0',
            'host'           => trim(preg_replace('/\s+/', ' ', (string) Http::nstr('host', 500))),
            'port'           => (string) max(0, min(65535, (int) Http::str('port', '0'))),
            'starttls'       => Http::bool('starttls') ? '1' : '0',
            'tls_verify'     => Http::bool('tls_verify') ? '1' : '0',
            'timeout'        => (string) max(1, min(60, (int) Http::str('timeout', '5'))),
            'base_dn'        => $dn('base_dn'),
            'users_base_dn'  => $dn('users_base_dn'),
            'bind_dn'        => $dn('bind_dn'),
            'auto_provision' => Http::bool('auto_provision') ? '1' : '0',
            'sync_roles'     => Http::bool('sync_roles') ? '1' : '0',
            'nested_groups'  => Http::bool('nested_groups') ? '1' : '0',
        ];
        foreach (['group_admin', 'group_supervisor', 'group_ssma', 'group_porteiro', 'group_seg_patrimonial'] as $g) {
            $v[$g] = $dn($g);
        }

        if ($ligado) {
            $erros = [];
            if ($v['host'] === '') {
                $erros[] = 'informe o servidor';
            } elseif (!preg_match('~^(ldaps?://)?[A-Za-z0-9.\-]+(:\d+)?( (ldaps?://)?[A-Za-z0-9.\-]+(:\d+)?)*$~', $v['host'])) {
                $erros[] = 'servidor inválido (ex.: ldaps://dc01.salinasgold.local)';
            } elseif (!str_contains($v['host'], 'ldaps://') && $v['starttls'] !== '1' && !Ldap::cfg()['allow_insecure']) {
                $erros[] = 'use ldaps:// ou marque StartTLS (LDAP sem criptografia é bloqueado)';
            }
            if ($v['base_dn'] === '' || !self::pareceDn($v['base_dn'])) {
                $erros[] = 'Base DN inválido (ex.: DC=salinasgold,DC=local)';
            }
            foreach (['users_base_dn', 'bind_dn', 'group_admin', 'group_supervisor', 'group_ssma', 'group_porteiro', 'group_seg_patrimonial'] as $k) {
                if ($v[$k] !== '' && !self::pareceDn($v[$k])) {
                    $erros[] = "DN inválido em {$k}";
                }
            }
            if ($v['auto_provision'] === '1' && $v['group_admin'] . $v['group_supervisor'] . $v['group_ssma'] . $v['group_porteiro'] === '') {
                $erros[] = 'o provisionamento automático exige ao menos um grupo de perfil mapeado';
            }
            if ($erros) {
                $this->falha('LDAP não salvo: ' . implode('; ', $erros) . '.', '/configuracoes');
            }
        }

        // Certificado da CA (opcional): validado e gravado em storage/certs
        $pem = trim((string) Http::nstr('ca_pem', 20000));
        if ($pem !== '') {
            if (!preg_match('/^-----BEGIN CERTIFICATE-----[A-Za-z0-9+\/=\s]+-----END CERTIFICATE-----$/', $pem)
                || (function_exists('openssl_x509_parse') && openssl_x509_parse($pem) === false)) {
                $this->falha('Certificado da CA inválido: cole o conteúdo PEM (BEGIN/END CERTIFICATE).', '/configuracoes');
            }
            $dir = \App\Core\App::root('storage/certs');
            if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
                $this->falha('Não foi possível criar storage/certs.', '/configuracoes');
            }
            file_put_contents($dir . '/ldap-ca.pem', $pem . "\n", LOCK_EX);
            $v['ca_cert'] = 'storage/certs/ldap-ca.pem';
        }

        foreach ($v as $k => $valor) {
            Settings::set('ldap.' . $k, $valor, $u['id']);
        }
        $senha = (string) Http::input('bind_password', '');
        $senhaAlterada = $senha !== '';
        if ($senhaAlterada) {
            Settings::set('ldap.bind_password_enc', \App\Core\Crypto::cifrarSegredo($senha), $u['id']);
        }
        Ldap::recarregar();
        Audit::log('configuracoes', null, 'LDAP_CONFIG', [...$v, 'senha_servico_alterada' => $senhaAlterada, 'ca_atualizada' => $pem !== '']);

        if (!$ligado) {
            $this->ok('LDAP desligado. O login usa somente contas locais.', '/configuracoes');
        }
        if (Http::bool('testar') && extension_loaded('ldap')) {
            $this->testarLdap();
        }
        $this->ok('Configuração LDAP salva.', '/configuracoes');
    }

    private static function pareceDn(string $dn): bool
    {
        return (bool) preg_match('/^[A-Za-z][A-Za-z0-9-]*=[^,=]+(,\s*[A-Za-z][A-Za-z0-9-]*=[^,=]+)*$/u', $dn);
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
