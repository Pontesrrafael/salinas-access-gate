<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Uuid;
use RuntimeException;

/**
 * Regras de vínculo entre usuários do sistema e contas do Active Directory.
 */
final class LdapUsuarios
{
    /**
     * Login de rede. Retorna ['usuario' => array|null, 'erro' => string|null].
     * Mensagens específicas só aparecem DEPOIS de a senha ter sido validada no AD.
     */
    public static function login(?array $local, string $login, string $senha): array
    {
        $cfg = Ldap::cfg();
        try {
            $entrada = $local
                ? Ldap::autenticar($login, $senha, $local['ldap_guid'])
                : Ldap::autenticar($login, $senha);
        } catch (RuntimeException $e) {
            \App\Core\App::log('LDAP indisponível no login: ' . $e->getMessage());
            return ['usuario' => null, 'erro' => 'Não foi possível validar com o Active Directory agora. Tente novamente ou use uma conta local.'];
        }
        if ($entrada === null) {
            return ['usuario' => null, 'erro' => null];
        }
        if (!$entrada['ativo']) {
            if ($local) {
                self::desativar($local, 'Conta desativada no Active Directory');
            }
            return ['usuario' => null, 'erro' => 'Sua conta está desativada no Active Directory.'];
        }

        if ($local) {
            $u = self::sincronizar($local, $entrada, 'LOGIN');
            if ($u === null) {
                return ['usuario' => null, 'erro' => 'Seu usuário de rede não pertence a nenhum grupo autorizado do Access Gate.'];
            }
            return ['usuario' => $u, 'erro' => null];
        }

        // Sem usuário local vinculado: provisionamento automático (se habilitado)
        if (!$cfg['auto_provision']) {
            return ['usuario' => null, 'erro' => 'Usuário de rede ainda não cadastrado no Access Gate. Solicite o acesso ao Admin/TI.'];
        }
        $perfis = self::perfisPorGrupos($entrada['grupos']);
        $papel = $perfis[0] ?? null;
        if ($papel === null) {
            return ['usuario' => null, 'erro' => 'Seu usuário de rede não pertence a nenhum grupo autorizado do Access Gate.'];
        }
        if ($entrada['email'] !== '' && Database::value('SELECT 1 FROM usuarios WHERE email = ?', [$entrada['email']])) {
            return ['usuario' => null, 'erro' => 'Já existe uma conta local com o seu e-mail. Peça ao Admin/TI para vinculá-la ao AD.'];
        }
        $id = self::criar($entrada, $papel, Ldap::segurancaPatrimonial($entrada['grupos']), null);
        Audit::log('usuarios', $id, 'LDAP_PROVISIONAMENTO', ['login' => $entrada['login'], 'papel' => $papel, 'perfis' => $perfis], $id);
        $u = Database::one('SELECT * FROM usuarios WHERE id = ?', [$id]);
        return ['usuario' => $u, 'erro' => null];
    }

    /** Cria usuário vinculado ao AD (usado pelo provisionamento e pelo cadastro do admin). */
    public static function criar(array $e, string $papel, bool $segPatrimonial, ?string $pessoaId): string
    {
        if (!isset(Auth::PAPEIS[$papel])) { throw new \InvalidArgumentException('Perfil inválido.'); }
        $id = Uuid::v4();
        Database::transaction(function () use ($id, $e, $papel, $segPatrimonial, $pessoaId): void {
            Database::insert('usuarios', [
                'id'                    => $id,
                'nome'                  => $e['nome'] ?: $e['login'],
                'email'                 => $e['email'] ?: mb_strtolower($e['login']) . '@ad.local',
                'senha_hash'            => Auth::hash(bin2hex(random_bytes(24))), // inutilizável: senha fica no AD
                'auth_origem'           => 'ldap',
                'ldap_guid'             => $e['guid'],
                'ldap_usuario'          => mb_strtolower($e['login']),
                'ldap_dn'               => $e['dn'],
                'ldap_sincronizado_em'  => date('Y-m-d H:i:s'),
                'papel'                 => $papel,
                'matricula'             => $e['matricula'],
                'ativo'                 => 1,
                'must_change_password'  => 0,
                'seguranca_patrimonial' => $segPatrimonial ? 1 : 0,
                'pessoa_id'             => $pessoaId ?? self::pessoaPorMatricula($e['matricula'], null),
            ]);
            self::gravarPerfis($id, array_values(array_unique([$papel, ...self::perfisPorGrupos($e['grupos'])])));
        });
        return $id;
    }

    /**
     * Atualiza dados do usuário a partir do AD. Retorna o usuário atualizado,
     * ou null se perdeu o acesso (sincronização de perfis ativa e sem grupo mapeado).
     */
    public static function sincronizar(array $u, array $e, string $origem): ?array
    {
        $cfg = Ldap::cfg();
        $upd = [
            'ldap_usuario'         => mb_strtolower($e['login']),
            'ldap_dn'              => $e['dn'],
            'ldap_sincronizado_em' => date('Y-m-d H:i:s'),
            'must_change_password' => 0,
        ];
        if ($e['nome'] !== '') {
            $upd['nome'] = $e['nome'];
        }
        if ($e['email'] !== '' && !Database::value('SELECT 1 FROM usuarios WHERE email = ? AND id <> ?', [$e['email'], $u['id']])) {
            $upd['email'] = $e['email'];
        }
        if ($e['matricula']) {
            $upd['matricula'] = $e['matricula'];
        }
        if (!$u['pessoa_id'] && ($p = self::pessoaPorMatricula($e['matricula'], $u['id']))) {
            $upd['pessoa_id'] = $p;
        }
        $mudancas = [];
        $perfis = null;
        if ($cfg['sync_roles']) {
            $perfis = self::perfisPorGrupos($e['grupos']);
            $papel = $perfis[0] ?? null;
            if ($papel === null) {
                self::desativar($u, 'Removido dos grupos do Access Gate no AD');
                return null;
            }
            // Protege o último administrador ativo
            $anteriores = array_column(Database::all('SELECT perfil FROM usuario_perfis WHERE usuario_id = ?', [$u['id']]), 'perfil');
            if (in_array('admin', $anteriores, true) && !in_array('admin', $perfis, true)
                && !self::outroAdmin($u['id'])) {
                $perfis[] = 'admin';
            }
            // Preserva a escolha de padrão enquanto ela continuar concedida pelo AD.
            $papel = in_array($u['papel'], $perfis, true) ? $u['papel'] : $perfis[0];
            $seg = Ldap::segurancaPatrimonial($e['grupos']) ? 1 : 0;
            if ($anteriores !== $perfis) { $mudancas['perfis'] = [$anteriores, $perfis]; }
            if ($papel !== $u['papel']) {
                $mudancas['papel'] = [$u['papel'], $papel];
            }
            if ($seg !== (int) $u['seguranca_patrimonial']) {
                $mudancas['seguranca_patrimonial'] = [(int) $u['seguranca_patrimonial'], $seg];
            }
            $upd['papel'] = $papel;
            $upd['seguranca_patrimonial'] = $seg;
        }
        Database::transaction(function () use ($u, $upd, $perfis): void {
            Database::update('usuarios', $upd, 'id = ?', [$u['id']]);
            if ($perfis !== null) { self::gravarPerfis($u['id'], $perfis); }
        });
        if ($mudancas || $origem !== 'LOGIN') {
            Audit::log('usuarios', $u['id'], 'LDAP_SINCRONIZACAO', ['origem' => $origem, 'mudancas' => $mudancas], $origem === 'LOGIN' ? $u['id'] : null);
        }
        return Database::one('SELECT * FROM usuarios WHERE id = ?', [$u['id']]);
    }

    public static function desativar(array $u, string $motivo): void
    {
        if ((int) $u['ativo'] === 0) {
            return;
        }
        if (Database::value("SELECT 1 FROM usuario_perfis WHERE usuario_id = ? AND perfil = 'admin'", [$u['id']])
            && !self::outroAdmin($u['id'])) {
            return; // nunca desativa o último admin automaticamente
        }
        Database::update('usuarios', ['ativo' => 0], 'id = ?', [$u['id']]);
        Audit::log('usuarios', $u['id'], 'LDAP_DESATIVACAO', ['motivo' => $motivo], null);
    }

    /** Sincroniza todos os usuários vinculados ao AD (CLI/cron). */
    public static function sincronizarTodos(): array
    {
        $r = ['verificados' => 0, 'atualizados' => 0, 'desativados' => 0, 'erros' => 0];
        foreach (Database::all("SELECT * FROM usuarios WHERE auth_origem = 'ldap' AND ativo = 1") as $u) {
            $r['verificados']++;
            try {
                $e = $u['ldap_guid'] ? Ldap::porGuid($u['ldap_guid']) : null;
                if ($e === null) {
                    self::desativar($u, 'Conta não encontrada no Active Directory');
                    $r['desativados']++;
                } elseif (!$e['ativo']) {
                    self::desativar($u, 'Conta desativada no Active Directory');
                    $r['desativados']++;
                } elseif (self::sincronizar($u, $e, 'CRON') === null) {
                    $r['desativados']++;
                } else {
                    $r['atualizados']++;
                }
            } catch (RuntimeException $ex) {
                $r['erros']++;
                \App\Core\App::log('LDAP sincronização: ' . $ex->getMessage(), ['usuario' => $u['email']]);
            }
        }
        return $r;
    }

    /** Compatibilidade: o primeiro grupo continua sendo o padrão no provisionamento. */
    public static function papelPorGrupos(array $grupos): ?string
    {
        return self::perfisPorGrupos($grupos)[0] ?? null;
    }

    /** Todos os grupos mapeados concedem perfis; não altera identificadores do diretório. */
    public static function perfisPorGrupos(array $grupos): array
    {
        $mapa = [...Ldap::mapaGrupos(),
            'gestor_area' => (string) \App\Core\Env::get('LDAP_GROUP_GESTOR_AREA', ''),
            'colaborador' => (string) \App\Core\Env::get('LDAP_GROUP_COLABORADOR', ''),
        ];
        $grupos = array_map(static fn (string $g): string => strtolower(trim($g)), $grupos);
        $perfis = [];
        foreach ($mapa as $perfil => $dn) {
            $dn = strtolower(trim($dn));
            if ($dn !== '' && in_array($dn, $grupos, true)) { $perfis[] = $perfil; }
        }
        return $perfis;
    }

    private static function gravarPerfis(string $id, array $perfis): void
    {
        Database::run('DELETE FROM usuario_perfis WHERE usuario_id = ?', [$id]);
        foreach ($perfis as $perfil) {
            Database::insert('usuario_perfis', ['usuario_id' => $id, 'perfil' => $perfil]);
        }
    }

    private static function outroAdmin(string $id): bool
    {
        return (bool) Database::value("SELECT 1 FROM usuarios u JOIN usuario_perfis p ON p.usuario_id = u.id AND p.perfil = 'admin' WHERE u.ativo = 1 AND u.id <> ? LIMIT 1", [$id]);
    }

    /** Colaborador ativo com a mesma matrícula e ainda sem usuário vinculado. */
    private static function pessoaPorMatricula(?string $matricula, ?string $usuarioId): ?string
    {
        if (!$matricula) {
            return null;
        }
        $id = Database::value(
            "SELECT p.id FROM pessoas p
              WHERE p.matricula = ? AND p.ativo = 1 AND p.categoria = 'colaborador'
                AND NOT EXISTS (SELECT 1 FROM usuarios u WHERE u.pessoa_id = p.id AND u.id <> ?)
              LIMIT 2",
            [$matricula, $usuarioId ?? '']
        );
        return $id ?: null;
    }
}
