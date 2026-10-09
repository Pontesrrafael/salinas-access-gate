<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Http;
use App\Core\Session;
use App\Core\Uuid;
use App\Services\Ldap;
use App\Services\LdapUsuarios;
use RuntimeException;

/** Gestão de usuários (substitui as Edge Functions admin-create-user / admin-update-user). */
final class UsuariosController extends Controller
{
    public function index(): void
    {
        Auth::authorize('usuarios.gerir');
        $rows = Database::all(
            'SELECT u.id, u.nome, u.email, u.papel, u.ativo, u.must_change_password, u.seguranca_patrimonial, u.auth_origem, u.ldap_usuario,
                    u.ultimo_login_em, p.id AS pessoa_id, p.nome AS pessoa_nome,
                    (SELECT GROUP_CONCAT(a.nome ORDER BY a.nome SEPARATOR ", ") FROM areas a WHERE a.responsavel_user_id = u.id) AS areas
               FROM usuarios u LEFT JOIN pessoas p ON p.id = u.pessoa_id
              ORDER BY u.ativo DESC, u.nome'
        );
        $this->view('usuarios/index', [
            'titulo'    => 'Usuários',
            'descricao' => 'Contas de acesso, perfis e responsabilidades por área',
            'rows'      => $rows,
            'senhaTemp' => Session::get('senha_temporaria'),
            'areasSemResp' => Database::all('SELECT nome FROM areas WHERE ativo = 1 AND responsavel_user_id IS NULL ORDER BY nome'),
        ]);
        Session::forget('senha_temporaria');
    }

    public function novo(): void
    {
        Auth::authorize('usuarios.gerir');
        $this->form(null);
    }

    public function editar(string $id): void
    {
        Auth::authorize('usuarios.gerir');
        $this->form($this->carregar($id));
    }

    public function criar(): void
    {
        Auth::authorize('usuarios.gerir');
        $ad = $this->vinculoAd('/usuarios/novo', null);
        $d = $this->dados('/usuarios/novo', null);
        $senha = (string) ($_POST['senha'] ?? '');
        $temporaria = $senha === '';
        if ($ad !== null) {
            $senha = bin2hex(random_bytes(24)); // inutilizável: autenticação é feita no AD
            $temporaria = false;
        } elseif ($temporaria) {
            $senha = $this->senhaTemporaria();
        } elseif ($e = Auth::validarSenha($senha, $d['email'])) {
            $this->falha($e, '/usuarios/novo');
        }
        $id = Uuid::v4();
        $areas = $this->areasSelecionadas();
        $pessoaId = $this->pessoaSelecionada(null);
        Database::transaction(function () use ($id, $d, $senha, $areas, $pessoaId, $ad): void {
            Database::insert('usuarios', [
                'id' => $id, ...$d, 'senha_hash' => Auth::hash($senha), 'must_change_password' => $ad ? 0 : 1, 'ativo' => 1,
                'pessoa_id' => $pessoaId, ...$this->camposAd($ad),
            ]);
            $this->aplicarAreas($id, $areas);
            Audit::log('usuarios', $id, 'CREATE_USER', [
                'email' => $d['email'], 'nome' => $d['nome'], 'papel' => $d['papel'], 'areas' => $areas,
                'origem' => $ad ? 'ldap' : 'local', 'ldap_usuario' => $ad['login'] ?? null,
            ]);
        });
        if ($ad !== null) {
            $this->ok('Usuário vinculado ao Active Directory (' . $ad['login'] . '). Ele entra com o usuário e a senha de rede.', '/usuarios');
        }
        Session::set('senha_temporaria', ['email' => $d['email'], 'senha' => $temporaria ? $senha : null]);
        $this->ok('Usuário criado. A troca de senha será exigida no primeiro acesso.', '/usuarios');
    }

    public function atualizar(string $id): void
    {
        $eu = Auth::authorize('usuarios.gerir');
        $atual = $this->carregar($id);
        $volta = '/usuarios/' . $id;
        $ad = $this->vinculoAd($volta, $id);
        $d = $this->dados($volta, $id);
        $d['ativo'] = Http::bool('ativo') ? 1 : 0;

        // Proteções contra auto-bloqueio e perda do último administrador
        if ($id === $eu['id'] && ($d['ativo'] === 0 || $d['papel'] !== 'admin')) {
            $this->falha('Você não pode desativar nem remover o perfil Admin da sua própria conta.', $volta);
        }
        if ($atual['papel'] === 'admin' && ($d['papel'] !== 'admin' || $d['ativo'] === 0)) {
            $outros = (int) Database::value("SELECT COUNT(*) FROM usuarios WHERE papel = 'admin' AND ativo = 1 AND id <> ?", [$id]);
            if ($outros === 0) {
                $this->falha('É necessário manter pelo menos um administrador ativo.', $volta);
            }
        }
        // Deixou de ser AD → passa a ser conta local com senha temporária obrigatória
        $voltouLocal = $ad === null && $atual['auth_origem'] === 'ldap';
        $reset = $ad === null && (Http::bool('redefinir_senha') || $voltouLocal);
        $senha = $reset ? $this->senhaTemporaria() : null;
        $areas = $this->areasSelecionadas();
        $pessoaId = $this->pessoaSelecionada($id);

        Database::transaction(function () use ($id, $d, $senha, $areas, $pessoaId, $atual, $reset, $ad): void {
            $upd = [...$d, 'pessoa_id' => $pessoaId, ...$this->camposAd($ad)];
            if ($ad !== null) {
                $upd['must_change_password'] = 0;
            }
            if ($senha !== null) {
                $upd['senha_hash'] = Auth::hash($senha);
                $upd['must_change_password'] = 1;
            }
            Database::update('usuarios', $upd, 'id = ?', [$id]);
            $this->aplicarAreas($id, $areas);
            Audit::log('usuarios', $id, 'UPDATE_USER', [
                'nome' => $d['nome'], 'papel' => $d['papel'], 'papel_anterior' => $atual['papel'],
                'ativo' => $d['ativo'], 'seguranca_patrimonial' => $d['seguranca_patrimonial'],
                'reset_senha' => $reset, 'areas' => $areas,
                'origem' => $ad ? 'ldap' : 'local', 'origem_anterior' => $atual['auth_origem'],
            ]);
        });
        if ($senha !== null) {
            Session::set('senha_temporaria', ['email' => $d['email'], 'senha' => $senha]);
        }
        $this->ok('Usuário atualizado.', '/usuarios');
    }

    /** Busca de contas no AD para vincular (AJAX). */
    public function buscarAd(): void
    {
        Auth::authorize('usuarios.gerir');
        if (!Ldap::disponivel()) {
            Http::json(['erro' => 'Integração LDAP desabilitada'], 400);
        }
        try {
            $lista = Ldap::buscar((string) Http::input('q', ''), 25);
        } catch (RuntimeException $e) {
            Http::json(['erro' => $e->getMessage()], 502);
        }
        $vinc = [];
        $guids = array_filter(array_column($lista, 'guid'));
        if ($guids) {
            foreach (Database::all('SELECT ldap_guid, nome FROM usuarios WHERE ldap_guid IN (' . Database::in($guids) . ')', array_values($guids)) as $r) {
                $vinc[$r['ldap_guid']] = $r['nome'];
            }
        }
        Http::json(array_map(fn ($e) => [
            'guid' => $e['guid'], 'login' => $e['login'], 'nome' => $e['nome'], 'email' => $e['email'],
            'matricula' => $e['matricula'], 'departamento' => $e['departamento'], 'cargo' => $e['cargo'],
            'ativo' => $e['ativo'], 'dn' => $e['dn'], 'vinculado_a' => $vinc[$e['guid']] ?? null,
        ], $lista));
    }

    /** Atualiza dados (e perfil, se LDAP_SYNC_ROLES) a partir do AD. */
    public function sincronizarAd(string $id): void
    {
        Auth::authorize('usuarios.gerir');
        $u = Database::one('SELECT * FROM usuarios WHERE id = ?', [$this->uuidOu404($id)]) ?? Http::abort(404);
        if ($u['auth_origem'] !== 'ldap' || !Ldap::disponivel()) {
            $this->falha('Usuário não vinculado ao AD ou integração desabilitada.', '/usuarios/' . $id);
        }
        try {
            $e = Ldap::porGuid((string) $u['ldap_guid']);
        } catch (RuntimeException $ex) {
            $this->falha('Falha ao consultar o AD: ' . $ex->getMessage(), '/usuarios/' . $id);
        }
        if ($e === null || !$e['ativo']) {
            LdapUsuarios::desativar($u, $e === null ? 'Conta não encontrada no Active Directory' : 'Conta desativada no Active Directory');
            $this->falha($e === null ? 'Conta não encontrada no AD — usuário desativado.' : 'Conta desativada no AD — usuário desativado.', '/usuarios/' . $id);
        }
        $r = LdapUsuarios::sincronizar($u, $e, 'MANUAL');
        $this->ok($r === null ? 'Usuário sem grupo autorizado no AD — desativado.' : 'Dados sincronizados com o AD.', '/usuarios/' . $id);
    }

    /* ------------------------------------------------------------------ */
    /** Valida o vínculo com o AD informado no formulário (dados sempre relidos do diretório). */
    private function vinculoAd(string $volta, ?string $id): ?array
    {
        if (Http::str('auth_origem') !== 'ldap') {
            return null;
        }
        if (!Ldap::disponivel()) {
            $this->falha('A integração com o Active Directory não está habilitada neste servidor.', $volta);
        }
        $guid = Http::str('ldap_guid');
        try {
            $e = $guid !== '' ? Ldap::porGuid($guid, false) : null;
        } catch (RuntimeException $ex) {
            $this->falha('Falha ao consultar o AD: ' . $ex->getMessage(), $volta);
        }
        if ($e === null) {
            $this->falha('Selecione a conta do Active Directory na busca.', $volta);
        }
        if (!$e['ativo']) {
            $this->falha('A conta ' . $e['login'] . ' está desativada no Active Directory.', $volta);
        }
        if (Database::value('SELECT 1 FROM usuarios WHERE ldap_guid = ? AND id <> ?', [$e['guid'], $id ?? ''])) {
            $this->falha('Esta conta do AD já está vinculada a outro usuário.', $volta);
        }
        // Campos em branco são completados com os dados do AD
        foreach (['nome' => 'nome', 'email' => 'email', 'matricula' => 'matricula'] as $campo => $attr) {
            if (trim((string) ($_POST[$campo] ?? '')) === '' && !empty($e[$attr])) {
                $_POST[$campo] = $e[$attr];
            }
        }
        return $e;
    }

    private function camposAd(?array $e): array
    {
        if ($e === null) {
            return ['auth_origem' => 'local', 'ldap_guid' => null, 'ldap_usuario' => null, 'ldap_dn' => null, 'ldap_sincronizado_em' => null];
        }
        return [
            'auth_origem' => 'ldap', 'ldap_guid' => $e['guid'], 'ldap_usuario' => mb_strtolower($e['login']),
            'ldap_dn' => $e['dn'], 'ldap_sincronizado_em' => date('Y-m-d H:i:s'),
        ];
    }

    private function form(?array $u): void
    {
        $this->view('usuarios/form', [
            'titulo'  => $u ? $u['nome'] : 'Novo usuário',
            'u'       => $u,
            'ldap'    => Ldap::disponivel(),
            'areas'   => Database::all('SELECT a.id, a.nome, a.responsavel_user_id, r.nome AS resp_nome FROM areas a LEFT JOIN usuarios r ON r.id = a.responsavel_user_id WHERE a.ativo = 1 ORDER BY a.nome'),
            'pessoas' => Database::all(
                'SELECT p.id, p.nome, p.empresa, p.matricula FROM pessoas p
                  WHERE p.ativo = 1 AND p.categoria = \'colaborador\'
                    AND (NOT EXISTS (SELECT 1 FROM usuarios x WHERE x.pessoa_id = p.id) OR p.id = ?)
                  ORDER BY p.nome LIMIT 1000',
                [$u['pessoa_id'] ?? '']
            ),
        ]);
    }

    private function dados(string $volta, ?string $id): array
    {
        $nome = Http::nstr('nome', 150);
        $email = mb_strtolower(Http::str('email'));
        $papel = Http::str('papel');
        if ($nome === null) {
            $this->falha('Nome é obrigatório.', $volta);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->falha('E-mail inválido.', $volta);
        }
        if (!array_key_exists($papel, Auth::PAPEIS)) {
            $this->falha('Perfil inválido.', $volta);
        }
        if (Database::value('SELECT 1 FROM usuarios WHERE email = ? AND id <> ?', [$email, $id ?? ''])) {
            $this->falha('Já existe usuário com este e-mail.', $volta);
        }
        return [
            'nome' => $nome, 'email' => $email, 'papel' => $papel,
            'matricula' => Http::nstr('matricula', 50),
            'seguranca_patrimonial' => Http::bool('seguranca_patrimonial') ? 1 : 0,
        ];
    }

    private function areasSelecionadas(): array
    {
        $ids = array_filter((array) ($_POST['areas'] ?? []), fn ($v) => is_string($v) && Uuid::valid($v));
        return array_values(array_unique($ids));
    }

    private function aplicarAreas(string $userId, array $areas): void
    {
        Database::run('UPDATE areas SET responsavel_user_id = NULL WHERE responsavel_user_id = ?', [$userId]);
        if ($areas) {
            Database::run('UPDATE areas SET responsavel_user_id = ? WHERE id IN (' . Database::in($areas) . ')', [$userId, ...$areas]);
        }
    }

    private function pessoaSelecionada(?string $userId): ?string
    {
        $pid = Http::str('pessoa_id');
        if (!Uuid::valid($pid)) {
            return null;
        }
        $dono = Database::value('SELECT id FROM usuarios WHERE pessoa_id = ?', [$pid]);
        if ($dono && $dono !== $userId) {
            $this->falha('Esta pessoa já está vinculada a outro usuário.', $userId ? '/usuarios/' . $userId : '/usuarios/novo');
        }
        return $pid;
    }

    private function senhaTemporaria(): string
    {
        $alfabeto = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
        $s = '';
        for ($i = 0; $i < 10; $i++) {
            $s .= $alfabeto[random_int(0, strlen($alfabeto) - 1)];
        }
        return $s . random_int(10, 99);
    }

    private function carregar(string $id): array
    {
        $this->uuidOu404($id);
        $u = Database::one('SELECT * FROM usuarios WHERE id = ?', [$id]) ?? Http::abort(404, 'Usuário não encontrado.');
        unset($u['senha_hash']);
        return $u;
    }
}
