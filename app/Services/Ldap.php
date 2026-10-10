<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use RuntimeException;

/**
 * Integração opcional com Active Directory / LDAP.
 *
 * Fluxo seguro:
 *  1. Conta de serviço (somente leitura) faz o bind e localiza o usuário.
 *  2. A senha do usuário é validada com um bind usando o DN encontrado.
 *  3. Grupos (inclusive aninhados, no AD) definem perfil e Segurança Patrimonial.
 *
 * Nenhuma senha de rede de usuário é armazenada no banco.
 * Configuração: tela Configurações (tabela configuracoes, chaves "ldap.*") com o .env como padrão.
 * A senha da conta de serviço definida pela tela fica cifrada (Crypto::cifrarSegredo).
 */
final class Ldap
{
    private const UAC_DESATIVADA = 0x2;
    private const OID_CADEIA = '1.2.840.113556.1.4.1941'; // LDAP_MATCHING_RULE_IN_CHAIN (AD)

    /** Campos editáveis pela tela (chave em configuracoes = "ldap." + nome). */
    public const CAMPOS_TELA = [
        'enabled' => 'bool', 'host' => 'str', 'port' => 'int', 'starttls' => 'bool', 'tls_verify' => 'bool',
        'ca_cert' => 'str', 'timeout' => 'int', 'base_dn' => 'str', 'users_base_dn' => 'str', 'bind_dn' => 'str',
        'auto_provision' => 'bool', 'sync_roles' => 'bool', 'nested_groups' => 'bool',
        'group_admin' => 'str', 'group_supervisor' => 'str', 'group_ssma' => 'str', 'group_porteiro' => 'str',
        'group_seg_patrimonial' => 'str',
    ];

    private static ?array $cfgCache = null;

    /** Configuração efetiva: .env como base, sobrescrita pelo que foi salvo na tela. */
    public static function cfg(): array
    {
        if (self::$cfgCache !== null) {
            return self::$cfgCache;
        }
        $c = App::config('ldap');
        try {
            $s = Settings::all();
        } catch (\Throwable) {
            $s = []; // banco indisponível ou tabela ainda não migrada: usa só o .env
        }
        foreach (self::CAMPOS_TELA as $k => $tipo) {
            $v = $s['ldap.' . $k] ?? null;
            if ($v === null) {
                continue;
            }
            $c[$k] = match ($tipo) { 'bool' => $v === '1', 'int' => (int) $v, default => (string) $v };
        }
        if (isset($s['ldap.bind_password_enc']) && $s['ldap.bind_password_enc'] !== '') {
            $c['bind_password'] = (string) (\App\Core\Crypto::decifrarSegredo($s['ldap.bind_password_enc']) ?? '');
        }
        if (($c['ca_cert'] ?? '') !== '' && !str_starts_with($c['ca_cert'], '/') && !preg_match('~^[A-Za-z]:[\\\\/]~', $c['ca_cert'])) {
            $c['ca_cert'] = App::root($c['ca_cert']); // caminho relativo à raiz do projeto (ex.: storage/certs/ldap-ca.pem)
        }
        $c['configurado_pela_tela'] = isset($s['ldap.enabled']);
        return self::$cfgCache = $c;
    }

    /** Descarta o cache após salvar pela tela. */
    public static function recarregar(): void
    {
        self::$cfgCache = null;
    }

    public static function habilitado(): bool
    {
        return (bool) (self::cfg()['enabled'] ?? false);
    }

    public static function disponivel(): bool
    {
        return self::habilitado() && extension_loaded('ldap');
    }

    /* ------------------------------------------------------------------
     * Conexão
     * ------------------------------------------------------------------ */

    /** @return \LDAP\Connection|resource */
    public static function conectar(bool $bindServico = true)
    {
        if (!extension_loaded('ldap')) {
            throw new RuntimeException('Extensão PHP "ldap" não está habilitada.');
        }
        $c = self::cfg();
        $hosts = trim((string) $c['host']);
        if ($hosts === '') {
            throw new RuntimeException('LDAP_HOST não configurado.');
        }
        $seguro = str_contains($hosts, 'ldaps://') || $c['starttls'];
        if (!$seguro && !$c['allow_insecure']) {
            throw new RuntimeException('Conexão LDAP sem criptografia bloqueada. Use ldaps:// ou LDAP_STARTTLS=true.');
        }

        // Opções TLS precisam ser definidas antes do connect (globais)
        if (!$c['tls_verify']) {
            ldap_set_option(null, LDAP_OPT_X_TLS_REQUIRE_CERT, LDAP_OPT_X_TLS_NEVER);
        } else {
            ldap_set_option(null, LDAP_OPT_X_TLS_REQUIRE_CERT, LDAP_OPT_X_TLS_DEMAND);
            if ($c['ca_cert'] !== '') {
                ldap_set_option(null, LDAP_OPT_X_TLS_CACERTFILE, $c['ca_cert']);
            }
        }
        ldap_set_option(null, LDAP_OPT_NETWORK_TIMEOUT, $c['timeout']);

        $uri = implode(' ', array_map(function (string $h) use ($c): string {
            if (!preg_match('#^ldaps?://#i', $h)) {
                $h = ($c['starttls'] ? 'ldap://' : 'ldaps://') . $h;
            }
            return ($c['port'] > 0 && !preg_match('#:\d+$#', $h)) ? $h . ':' . $c['port'] : $h;
        }, preg_split('/[\s,]+/', $hosts) ?: []));

        $conn = @ldap_connect($uri);
        if ($conn === false) {
            throw new RuntimeException('URI LDAP inválida: ' . $uri);
        }
        ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($conn, LDAP_OPT_REFERRALS, 0);
        ldap_set_option($conn, LDAP_OPT_NETWORK_TIMEOUT, $c['timeout']);
        ldap_set_option($conn, LDAP_OPT_TIMELIMIT, $c['timeout']);
        if (defined('LDAP_OPT_X_TLS_NEWCTX')) {
            @ldap_set_option($conn, LDAP_OPT_X_TLS_NEWCTX, 0);
        }
        if ($c['starttls'] && !str_contains($uri, 'ldaps://') && !@ldap_start_tls($conn)) {
            throw new RuntimeException('Falha no StartTLS: ' . ldap_error($conn));
        }
        if ($bindServico) {
            if ($c['bind_dn'] === '' || $c['bind_password'] === '') {
                throw new RuntimeException('LDAP_BIND_DN / LDAP_BIND_PASSWORD não configurados.');
            }
            if (!@ldap_bind($conn, $c['bind_dn'], $c['bind_password'])) {
                $erro = ldap_error($conn);
                $diag = '';
                @ldap_get_option($conn, LDAP_OPT_DIAGNOSTIC_MESSAGE, $diag);
                $dica = ldap_errno($conn) === -1
                    ? ' — verifique host/porta, firewall e o certificado TLS (LDAP_CA_CERT / LDAP_TLS_VERIFY; o nome do host deve constar no certificado do DC)'
                    : (ldap_errno($conn) === 49 ? ' — usuário/senha da conta de serviço incorretos ou senha expirada' : '');
                throw new RuntimeException('Falha no bind da conta de serviço: ' . $erro . ($diag ? " ({$diag})" : '') . $dica);
            }
        }
        return $conn;
    }

    /* ------------------------------------------------------------------
     * Consultas
     * ------------------------------------------------------------------ */

    /** Busca usuários por nome, login, e-mail ou matrícula (para o cadastro). */
    public static function buscar(string $termo, int $limite = 20): array
    {
        $c = self::cfg();
        $t = ldap_escape(trim($termo), '', LDAP_ESCAPE_FILTER);
        if (mb_strlen($termo) < 2) {
            return [];
        }
        $filtro = sprintf(
            '(&%s(|(%s=*%s*)(%s=*%s*)(%s=*%s*)(%s=%s)(userPrincipalName=%s*)))',
            $c['user_filter'], $c['attr_nome'], $t, $c['attr_login'], $t, $c['attr_email'], $t, $c['attr_matricula'], $t, $t
        );
        $conn = self::conectar();
        $res = self::search($conn, $filtro, $limite);
        $saida = [];
        foreach ($res as $e) {
            $saida[] = self::normalizar($conn, $e, false);
        }
        usort($saida, fn ($a, $b) => strcmp($a['nome'], $b['nome']));
        return $saida;
    }

    public static function porGuid(string $guid, bool $comGrupos = true): ?array
    {
        $c = self::cfg();
        $valor = self::guidParaFiltro($guid);
        if ($valor === null) {
            return null;
        }
        $conn = self::conectar();
        // Inclui contas desativadas (para detectar desligamento)
        $res = self::search($conn, sprintf('(%s=%s)', $c['attr_guid'], $valor), 2);
        return count($res) === 1 ? self::normalizar($conn, $res[0], $comGrupos) : null;
    }

    public static function porLogin(string $login, bool $comGrupos = true): ?array
    {
        $conn = self::conectar();
        $res = self::search($conn, self::filtroLogin($login), 2);
        return count($res) === 1 ? self::normalizar($conn, $res[0], $comGrupos) : null;
    }

    /**
     * Valida usuário e senha no diretório.
     * @return array|null  dados normalizados (com grupos) ou null se credenciais inválidas
     * @throws RuntimeException se o diretório estiver indisponível/mal configurado
     */
    public static function autenticar(string $login, string $senha, ?string $guid = null): ?array
    {
        // Senha vazia faria "bind anônimo" e seria aceita por alguns servidores
        if ($senha === '' || trim($login) === '') {
            return null;
        }
        $entrada = $guid !== null ? self::porGuid($guid) : self::porLogin($login);
        if ($entrada === null) {
            return null;
        }
        $conn = self::conectar(false);
        if (!@ldap_bind($conn, $entrada['dn'], $senha)) {
            $codigo = ldap_errno($conn);
            // 49 = invalidCredentials; demais códigos = problema de infraestrutura
            if ($codigo !== 49) {
                throw new RuntimeException('Diretório indisponível (' . ldap_error($conn) . ')');
            }
            return null;
        }
        @ldap_unbind($conn);
        return $entrada;
    }

    /** Diagnóstico para a tela de configurações e CLI. */
    public static function testar(): array
    {
        $c = self::cfg();
        $t0 = microtime(true);
        $conn = self::conectar();
        $n = count(self::search($conn, $c['user_filter'], 1000));
        $grupos = [];
        $mapa = self::mapaGrupos();
        if ($c['group_seg_patrimonial'] !== '') {
            $mapa['seguranca_patrimonial'] = $c['group_seg_patrimonial'];
        }
        foreach ($mapa as $papel => $dn) {
            $ok = @ldap_read($conn, $dn, '(objectClass=*)', ['cn']);
            $grupos[$papel] = ['dn' => $dn, 'existe' => $ok !== false && ldap_count_entries($conn, $ok) === 1];
        }
        return [
            'servidor'      => $c['host'],
            'base'          => $c['base_dn'],
            'usuarios'      => $n,
            'grupos'        => $grupos,
            'tempo_ms'      => (int) round((microtime(true) - $t0) * 1000),
        ];
    }

    /* ------------------------------------------------------------------
     * Perfis por grupo
     * ------------------------------------------------------------------ */

    /** Ordem = prioridade (o primeiro grupo encontrado define o perfil). */
    public static function mapaGrupos(): array
    {
        $c = self::cfg();
        return array_filter([
            'admin'      => $c['group_admin'],
            'supervisor' => $c['group_supervisor'],
            'ssma'       => $c['group_ssma'],
            'porteiro'   => $c['group_porteiro'],
        ], fn ($v) => $v !== '');
    }

    public static function papelPorGrupos(array $grupos): ?string
    {
        $g = array_map('strtolower', $grupos);
        foreach (self::mapaGrupos() as $papel => $dn) {
            if (in_array(strtolower($dn), $g, true)) {
                return $papel;
            }
        }
        return null;
    }

    public static function segurancaPatrimonial(array $grupos): bool
    {
        $dn = strtolower(self::cfg()['group_seg_patrimonial']);
        return $dn !== '' && in_array($dn, array_map('strtolower', $grupos), true);
    }

    /* ------------------------------------------------------------------
     * Internos
     * ------------------------------------------------------------------ */

    private static function filtroLogin(string $login): string
    {
        $c = self::cfg();
        $login = trim($login);
        if (str_contains($login, '\\')) {               // DOMINIO\usuario
            $login = substr($login, (int) strrpos($login, '\\') + 1);
        }
        $v = ldap_escape($login, '', LDAP_ESCAPE_FILTER);
        return sprintf('(&%s(|(%s=%s)(userPrincipalName=%s)(%s=%s)))', $c['user_filter'], $c['attr_login'], $v, $v, $c['attr_email'], $v);
    }

    private static function atributos(): array
    {
        $c = self::cfg();
        return array_values(array_unique([
            'dn', $c['attr_guid'], $c['attr_login'], $c['attr_nome'], $c['attr_email'], $c['attr_matricula'],
            'cn', 'userPrincipalName', 'department', 'title', 'userAccountControl', 'memberOf',
        ]));
    }

    private static function search($conn, string $filtro, int $limite): array
    {
        $c = self::cfg();
        $base = $c['users_base_dn'] !== '' ? $c['users_base_dn'] : $c['base_dn'];
        $sr = @ldap_search($conn, $base, $filtro, self::atributos(), 0, $limite);
        if ($sr === false) {
            $codigo = ldap_errno($conn);
            if ($codigo === 4) { // sizeLimitExceeded: resultados parciais são aceitáveis
                return [];
            }
            throw new RuntimeException('Erro na consulta LDAP: ' . ldap_error($conn));
        }
        $entradas = ldap_get_entries($conn, $sr);
        $out = [];
        for ($i = 0; $i < ($entradas['count'] ?? 0); $i++) {
            $out[] = $entradas[$i];
        }
        return $out;
    }

    private static function attr(array $e, string $nome): ?string
    {
        $k = strtolower($nome);
        return isset($e[$k][0]) && $e[$k][0] !== '' ? (string) $e[$k][0] : null;
    }

    private static function normalizar($conn, array $e, bool $comGrupos): array
    {
        $c = self::cfg();
        $uac = (int) (self::attr($e, 'userAccountControl') ?? 0);
        $guidBruto = self::attr($e, $c['attr_guid']);
        $dados = [
            'dn'           => (string) $e['dn'],
            'guid'         => $guidBruto === null ? null : self::guidParaTexto($guidBruto),
            'login'        => self::attr($e, $c['attr_login']) ?? '',
            'nome'         => self::attr($e, $c['attr_nome']) ?? (self::attr($e, 'cn') ?? ''),
            'email'        => mb_strtolower((string) (self::attr($e, $c['attr_email']) ?? self::attr($e, 'userPrincipalName') ?? '')),
            'matricula'    => self::attr($e, $c['attr_matricula']),
            'departamento' => self::attr($e, 'department'),
            'cargo'        => self::attr($e, 'title'),
            'ativo'        => ($uac & self::UAC_DESATIVADA) === 0,
            'grupos'       => [],
        ];
        if ($comGrupos) {
            $dados['grupos'] = self::grupos($conn, $e);
        }
        return $dados;
    }

    private static function grupos($conn, array $e): array
    {
        $c = self::cfg();
        if ($c['nested_groups']) {
            $dnEsc = ldap_escape((string) $e['dn'], '', LDAP_ESCAPE_FILTER);
            $sr = @ldap_search($conn, $c['base_dn'], '(member:' . self::OID_CADEIA . ':=' . $dnEsc . ')', ['dn'], 0, 500);
            if ($sr !== false) {
                $r = ldap_get_entries($conn, $sr);
                $g = [];
                for ($i = 0; $i < ($r['count'] ?? 0); $i++) {
                    $g[] = (string) $r[$i]['dn'];
                }
                return $g;
            }
        }
        $m = $e['memberof'] ?? [];
        unset($m['count']);
        return array_values(array_map('strval', $m));
    }

    /** objectGUID (binário, little-endian) → texto padrão; outros atributos (entryUUID) já são texto. */
    private static function guidParaTexto(string $v): string
    {
        if (strtolower(self::cfg()['attr_guid']) !== 'objectguid' || strlen($v) !== 16) {
            return strtolower($v);
        }
        $h = bin2hex($v);
        return strtolower(
            substr($h, 6, 2) . substr($h, 4, 2) . substr($h, 2, 2) . substr($h, 0, 2) . '-' .
            substr($h, 10, 2) . substr($h, 8, 2) . '-' . substr($h, 14, 2) . substr($h, 12, 2) . '-' .
            substr($h, 16, 4) . '-' . substr($h, 20, 12)
        );
    }

    private static function guidParaFiltro(string $guid): ?string
    {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $guid)) {
            return null;
        }
        if (strtolower(self::cfg()['attr_guid']) !== 'objectguid') {
            return ldap_escape(strtolower($guid), '', LDAP_ESCAPE_FILTER);
        }
        $h = str_replace('-', '', strtolower($guid));
        $bin = substr($h, 6, 2) . substr($h, 4, 2) . substr($h, 2, 2) . substr($h, 0, 2)
             . substr($h, 10, 2) . substr($h, 8, 2) . substr($h, 14, 2) . substr($h, 12, 2) . substr($h, 16);
        return '\\' . implode('\\', str_split($bin, 2));
    }
}
