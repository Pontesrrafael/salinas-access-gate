<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Autenticação por sessão + autorização por papel (RBAC).
 * A matriz abaixo reproduz as políticas RLS do sistema anterior (Supabase),
 * agora aplicadas no servidor PHP.
 */
final class Auth
{
    public const PAPEIS = [
        'admin'      => 'Admin/TI',
        'supervisor' => 'Supervisor',
        'ssma'       => 'SSMA',
        'porteiro'   => 'Porteiro',
    ];

    /** permissão => papéis autorizados */
    private const PERMISSOES = [
        'portaria.operar'          => ['admin', 'porteiro'],
        'pessoas.ver'              => ['admin', 'supervisor', 'ssma', 'porteiro'],
        'pessoas.criar'            => ['admin', 'supervisor', 'ssma', 'porteiro'],
        'pessoas.editar'           => ['admin', 'supervisor', 'ssma'],
        'pessoas.excluir'          => ['admin'],
        'pessoas.ver_cpf'          => ['admin', 'supervisor'],
        'pessoas.vincular_usuario' => ['admin'],
        'lgpd.titular'             => ['admin'],
        'documentos.gerir'         => ['admin', 'supervisor', 'ssma'],
        'documentos.excluir'       => ['admin', 'ssma'],
        'veiculos.ver'             => ['admin', 'supervisor', 'ssma', 'porteiro'],
        'veiculos.criar'           => ['admin', 'supervisor', 'porteiro'],
        'veiculos.editar'          => ['admin', 'supervisor'],
        'veiculos.excluir'         => ['admin'],
        'infleet.ver'              => ['admin', 'supervisor'],
        'infleet.gerir'            => ['admin'],
        'locais.gerir'             => ['admin', 'supervisor'],
        'locais.excluir'           => ['admin'],
        'registros.ver'            => ['admin', 'supervisor', 'ssma', 'porteiro'],
        'solicitacoes.ver'         => ['admin', 'supervisor', 'ssma', 'porteiro'],
        'solicitacoes.criar'       => ['admin', 'supervisor', 'ssma', 'porteiro'],
        'ssma.verificar'           => ['admin', 'ssma'],
        'indicadores.ver'          => ['admin', 'supervisor', 'ssma'],
        'relatorios.ver'           => ['admin', 'supervisor'],
        'auditoria.ver'            => ['admin', 'supervisor'],
        'usuarios.gerir'           => ['admin'],
        'configuracoes.gerir'      => ['admin'],
    ];

    private static ?array $user = null;
    private static bool $loaded = false;

    /**
     * Autentica por e-mail (conta local) ou usuário de rede (Active Directory, se habilitado).
     * @return array{usuario: ?array, erro: ?string}
     */
    public static function attempt(string $login, string $senha): array
    {
        $login = trim($login);
        $chave = mb_strtolower($login);
        if (str_contains($chave, '\\')) {                 // DOMINIO\usuario
            $chave = substr($chave, (int) strrpos($chave, '\\') + 1);
        }
        $u = Database::one('SELECT * FROM usuarios WHERE email = ? OR (ldap_usuario IS NOT NULL AND ldap_usuario = ?) LIMIT 1', [$chave, $chave]);

        // Conta vinculada ao AD: a senha é validada no diretório
        if ($u && $u['auth_origem'] === 'ldap') {
            if (!\App\Services\Ldap::disponivel()) {
                return ['usuario' => null, 'erro' => 'Login pelo Active Directory desabilitado neste servidor. Procure o Admin/TI.'];
            }
            return \App\Services\LdapUsuarios::login($u, $login, $senha);
        }

        // Conta local (verificação em tempo constante mesmo se o usuário não existir)
        $hash = $u['senha_hash'] ?? '$2y$12$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG';
        $ok = password_verify($senha, $hash);
        if ($u && $ok) {
            if (password_needs_rehash($u['senha_hash'], PASSWORD_BCRYPT, ['cost' => self::cost()])) {
                Database::update('usuarios', ['senha_hash' => self::hash($senha)], 'id = ?', [$u['id']]);
            }
            return ['usuario' => $u, 'erro' => null];
        }

        // Sem conta local: tenta o AD (provisionamento automático, se habilitado)
        if (!$u && !str_contains($chave, '@local') && \App\Services\Ldap::disponivel()) {
            return \App\Services\LdapUsuarios::login(null, $login, $senha);
        }
        return ['usuario' => null, 'erro' => null];
    }

    public static function login(array $u): void
    {
        Session::regenerate();
        Session::set('uid', $u['id']);
        Session::set('login_em', time());
        Database::update('usuarios', ['ultimo_login_em' => date('Y-m-d H:i:s')], 'id = ?', [$u['id']]);
        self::$user = null;
        self::$loaded = false;
    }

    public static function logout(): void
    {
        Session::destroy();
        self::$user = null;
        self::$loaded = true;
    }

    public static function user(): ?array
    {
        if (!self::$loaded) {
            self::$loaded = true;
            $uid = Session::get('uid');
            if (is_string($uid) && $uid !== '') {
                $u = Database::one('SELECT * FROM usuarios WHERE id = ?', [$uid]);
                if ($u && (int) $u['ativo'] === 1) {
                    unset($u['senha_hash'], $u['mfa_segredo_enc']);
                    self::$user = $u;
                } else {
                    Session::forget('uid');
                }
            }
        }
        return self::$user;
    }

    public static function id(): ?string
    {
        return self::user()['id'] ?? null;
    }

    public static function papel(): ?string
    {
        return self::user()['papel'] ?? null;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function is(string ...$papeis): bool
    {
        $p = self::papel();
        return $p !== null && in_array($p, $papeis, true);
    }

    public static function can(string $permissao): bool
    {
        $p = self::papel();
        return $p !== null && in_array($p, self::PERMISSOES[$permissao] ?? [], true);
    }

    public static function isSegurancaPatrimonial(): bool
    {
        return (int) (self::user()['seguranca_patrimonial'] ?? 0) === 1;
    }

    /** Garante sessão válida; força troca de senha quando necessário. */
    public static function requireLogin(): array
    {
        $u = self::user();
        if ($u === null) {
            if (Http::isAjax()) {
                Http::json(['erro' => 'Não autenticado'], 401);
            }
            Session::set('intended', $_SERVER['REQUEST_URI'] ?? '/');
            Http::redirect('/login');
        }
        if ((int) $u['must_change_password'] === 1 && ($u['auth_origem'] ?? 'local') === 'local' && Http::path() !== '/trocar-senha') {
            Http::redirect('/trocar-senha');
        }
        // MFA obrigatório para Admin/TI com conta local (ISO 27001 A.8.5): cadastra antes de usar o sistema
        if ($u['papel'] === 'admin' && ($u['auth_origem'] ?? 'local') === 'local' && (int) ($u['mfa_ativo'] ?? 0) !== 1
            && \App\Services\Settings::bool('mfa_obrigatorio_admin')
            && !in_array(Http::path(), ['/conta/mfa', '/trocar-senha'], true)) {
            if (Http::isAjax()) {
                Http::json(['erro' => 'Cadastre a verificação em duas etapas para continuar.'], 403);
            }
            Session::flash('aviso', 'Para Admin/TI a verificação em duas etapas é obrigatória. Cadastre-a para continuar.');
            Http::redirect('/conta/mfa');
        }
        return $u;
    }

    public static function authorize(string $permissao): array
    {
        $u = self::requireLogin();
        if (!self::can($permissao)) {
            Http::abort(403, 'Seu perfil não tem permissão para esta ação.');
        }
        return $u;
    }

    public static function cost(): int
    {
        return max(10, (int) (App::config('seguranca')['bcrypt_cost'] ?? 12));
    }

    public static function hash(string $senha): string
    {
        return password_hash($senha, PASSWORD_BCRYPT, ['cost' => self::cost()]);
    }

    /** Política de senha. Retorna mensagem de erro ou null. */
    public static function validarSenha(string $senha, string $email = ''): ?string
    {
        $min = (int) (App::config('seguranca')['senha_min'] ?? 8);
        if (mb_strlen($senha) < $min) {
            return "A senha deve ter no mínimo {$min} caracteres.";
        }
        if (strlen($senha) > 72) {
            return 'A senha deve ter no máximo 72 caracteres.';
        }
        if (!preg_match('/[A-Za-z]/', $senha) || !preg_match('/\d/', $senha)) {
            return 'A senha deve conter letras e números.';
        }
        $usuarioEmail = $email !== '' ? explode('@', $email)[0] : '';
        if (mb_strlen($usuarioEmail) >= 4 && stripos($senha, $usuarioEmail) !== false) {
            return 'A senha não pode conter o seu usuário de e-mail.';
        }
        return null;
    }
}
