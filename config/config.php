<?php
declare(strict_types=1);

use App\Core\Env;

/**
 * Configuração central. TODOS os valores sensíveis vêm do arquivo .env
 * (nunca versionar o .env — use .env.example como modelo).
 */
return [
    'app' => [
        'nome'      => 'Salinas Access Gate',
        'env'       => Env::get('APP_ENV', 'local'),          // local | production
        'debug'     => Env::bool('APP_DEBUG', false),
        'url'       => rtrim((string) Env::get('APP_URL', ''), '/'),
        'timezone'  => Env::get('APP_TIMEZONE', 'America/Cuiaba'),
        'db_offset' => Env::get('DB_TIME_ZONE', '-04:00'),     // Horário de Mato Grosso – UTC-4
    ],
    'db' => [
        'host'     => Env::get('DB_HOST', '127.0.0.1'),
        'port'     => Env::int('DB_PORT', 3306),
        'database' => Env::get('DB_DATABASE', 'salinas_access_gate'),
        'username' => Env::get('DB_USERNAME', 'root'),
        'password' => Env::get('DB_PASSWORD', ''),
        'socket'   => Env::get('DB_SOCKET', ''),
    ],
    'session' => [
        'nome'            => Env::get('SESSION_NAME', 'sag_sessao'),
        'idle_minutos'    => Env::int('SESSION_IDLE_MINUTES', 120),
        'cookie_secure'   => Env::bool('SESSION_SECURE_COOKIE', false), // true em produção (HTTPS)
    ],
    'seguranca' => [
        'cpf_key'          => Env::get('CPF_ENCRYPTION_KEY', ''),   // base64 de 32 bytes
        'cpf_index_key'    => Env::get('CPF_INDEX_KEY', ''),        // base64 de 32 bytes
        'bcrypt_cost'      => Env::int('BCRYPT_COST', 12),
        'login_max'        => Env::int('LOGIN_MAX_ATTEMPTS', 5),
        'login_janela_min' => Env::int('LOGIN_WINDOW_MINUTES', 15),
        'senha_min'        => Env::int('PASSWORD_MIN_LENGTH', 8),
        'trusted_proxies'  => array_filter(array_map('trim', explode(',', (string) Env::get('TRUSTED_PROXIES', '')))),
    ],
    'uploads' => [
        'dir'        => Env::get('UPLOAD_DIR', dirname(__DIR__) . '/storage/uploads'),
        'max_mb'     => Env::int('UPLOAD_MAX_MB', 8),
        'max_files'  => Env::int('UPLOAD_MAX_FILES', 8),
    ],
    // Integração opcional com Active Directory / LDAP (desligada por padrão)
    'ldap' => [
        'enabled'               => Env::bool('LDAP_ENABLED', false),
        'host'                  => (string) Env::get('LDAP_HOST', ''),          // ex.: ldaps://dc01.salinasgold.local ldaps://dc02.salinasgold.local
        'port'                  => Env::int('LDAP_PORT', 0),                    // 0 = padrão do esquema (636 ldaps / 389 ldap)
        'starttls'              => Env::bool('LDAP_STARTTLS', false),
        'tls_verify'            => Env::bool('LDAP_TLS_VERIFY', true),
        'ca_cert'               => (string) Env::get('LDAP_CA_CERT', ''),
        'allow_insecure'        => Env::bool('LDAP_ALLOW_INSECURE', false),
        'timeout'               => Env::int('LDAP_TIMEOUT', 5),
        'base_dn'               => (string) Env::get('LDAP_BASE_DN', ''),
        'users_base_dn'         => (string) Env::get('LDAP_USERS_BASE_DN', ''),
        'bind_dn'               => (string) Env::get('LDAP_BIND_DN', ''),
        'bind_password'         => (string) Env::get('LDAP_BIND_PASSWORD', ''),
        'user_filter'           => (string) Env::get('LDAP_USER_FILTER', '(&(objectCategory=person)(objectClass=user))'),
        'attr_login'            => (string) Env::get('LDAP_ATTR_LOGIN', 'sAMAccountName'),
        'attr_guid'             => (string) Env::get('LDAP_ATTR_GUID', 'objectGUID'),
        'attr_nome'             => (string) Env::get('LDAP_ATTR_NOME', 'displayName'),
        'attr_email'            => (string) Env::get('LDAP_ATTR_EMAIL', 'mail'),
        'attr_matricula'        => (string) Env::get('LDAP_ATTR_MATRICULA', 'employeeID'),
        'nested_groups'         => Env::bool('LDAP_NESTED_GROUPS', true),
        'auto_provision'        => Env::bool('LDAP_AUTO_PROVISION', false),
        'sync_roles'            => Env::bool('LDAP_SYNC_ROLES', false),
        'group_admin'           => (string) Env::get('LDAP_GROUP_ADMIN', ''),
        'group_supervisor'      => (string) Env::get('LDAP_GROUP_SUPERVISOR', ''),
        'group_ssma'            => (string) Env::get('LDAP_GROUP_SSMA', ''),
        'group_porteiro'        => (string) Env::get('LDAP_GROUP_PORTEIRO', ''),
        'group_seg_patrimonial' => (string) Env::get('LDAP_GROUP_SEG_PATRIMONIAL', ''),
    ],
    'infleet' => [
        'enabled' => Env::bool('INFLEET_ENABLED', false),
        'token' => (string) Env::get('INFLEET_TOKEN', ''),
        'organization_id' => (string) Env::get('INFLEET_ORGANIZATION_ID', ''),
        'timeout' => Env::int('INFLEET_TIMEOUT', 20),
        'max_records' => Env::int('INFLEET_MAX_RECORDS', 10000),
    ],
    'google_maps' => [
        'enabled' => Env::bool('GOOGLE_MAPS_ENABLED', false),
        // Chave de navegador: restringir por domínio e exclusivamente à Maps JavaScript API.
        'browser_key' => (string) Env::get('GOOGLE_MAPS_BROWSER_KEY', ''),
    ],
    'turnstile' => [
        'site_key'   => Env::get('TURNSTILE_SITE_KEY', ''),
        'secret_key' => Env::get('TURNSTILE_SECRET_KEY', ''),
    ],
    'email' => [
        'transporte' => strtolower(trim((string) Env::get('EMAIL_TRANSPORTE', 'smtp'))), // smtp | graph
        'smtp_auth'  => strtolower(trim((string) Env::get('EMAIL_SMTP_AUTH', 'login'))), // login | oauth
    ],
    'microsoft' => [
        'tenant_id'            => trim((string) Env::get('MS_TENANT_ID', '')),
        'client_id'            => trim((string) Env::get('MS_CLIENT_ID', '')),
        'client_secret'        => (string) Env::get('MS_CLIENT_SECRET', ''),
        'client_cert_path'     => trim((string) Env::get('MS_CLIENT_CERT_PATH', '')),
        'client_cert_key_path' => trim((string) Env::get('MS_CLIENT_CERT_KEY_PATH', '')),
    ],
    'smtp' => [
        'host'       => (string) Env::get('SMTP_HOST', ''),
        'porta'      => Env::int('SMTP_PORT', 587),
        'usuario'    => (string) (Env::get('SMTP_USER') ?? Env::get('SMTP_USERNAME', '')),
        'senha'      => (string) (Env::get('SMTP_PASS') ?? Env::get('SMTP_PASSWORD', '')),
        'de_email'   => (string) (Env::get('SMTP_FROM_EMAIL') ?? Env::get('SMTP_FROM', 'noreply@salinasgold.com.br')),
        'de_nome'    => (string) Env::get('SMTP_FROM_NAME', 'Salinas Access Gate'),
        'seguranca'  => strtolower((string) (Env::get('SMTP_SECURITY') ?? Env::get('SMTP_ENCRYPTION', 'tls'))), // tls | ssl | nenhuma
        'timeout'    => Env::int('SMTP_TIMEOUT', 10),
        'tls_verify' => Env::bool('SMTP_TLS_VERIFY', true),
    ],
];
