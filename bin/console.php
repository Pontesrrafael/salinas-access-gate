<?php
declare(strict_types=1);

/**
 * Salinas Access Gate — utilitário de linha de comando.
 *
 *   php bin/console.php verificar                 Checa PHP, extensões, .env, banco e pastas
 *   php bin/console.php gerar-chaves [--gravar]   Gera chaves de criptografia do CPF
 *   php bin/console.php migrar                    Cria/atualiza as tabelas no banco
 *   php bin/console.php criar-admin --email=ti@salinasgold.com.br --nome="TI Salinas"
 *   php bin/console.php importar-supabase <pasta-com-csv> [--simular]
 *   php bin/console.php expirar-solicitacoes      (agendar diariamente no cron)
 *   php bin/console.php redefinir-senha --email=usuario@salinasgold.com.br
 *   php bin/console.php ldap-testar                Testa conexão com o Active Directory
 *   php bin/console.php ldap-buscar <termo>        Busca contas no AD
 *   php bin/console.php ldap-sincronizar           Sincroniza/desativa usuários do AD (agendar no cron)
 *   php bin/console.php ldap-vincular --email=<usuario local> --login=<usuario de rede>
 *   php bin/console.php bi-sql [--usuario=sag_bi] [--host=%]   SQL do usuário de consulta do BI (rodar como root)
 *   php bin/console.php bi-verificar               Confere se todas as colunas das views bi_* estão no dicionário
 *   php bin/console.php lgpd-retencao [--simular]  Anonimiza cadastros antigos e apaga fotos vencidas (cron mensal)
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\App;
use App\Core\Audit;
use App\Core\Auth;
use App\Core\Crypto;
use App\Core\Database;
use App\Core\Uuid;
use App\Services\ImportadorSupabase;
use App\Services\Ldap;
use App\Services\LdapUsuarios;

$args = $argv;
array_shift($args);
$cmd = array_shift($args) ?? 'ajuda';
$opts = [];
$pos = [];
foreach ($args as $a) {
    if (preg_match('/^--([a-z\-]+)(?:=(.*))?$/', $a, $m)) {
        $opts[$m[1]] = $m[2] ?? '1';
    } else {
        $pos[] = $a;
    }
}

function out(string $msg = ''): void { fwrite(STDOUT, $msg . PHP_EOL); }
function err(string $msg): never { fwrite(STDERR, 'ERRO: ' . $msg . PHP_EOL); exit(1); }
function ok(string $msg): void { out('  [OK] ' . $msg); }
function falhou(string $msg): void { out('  [!!] ' . $msg); }

function perguntarSenha(string $rotulo): string
{
    fwrite(STDOUT, $rotulo);
    $windows = DIRECTORY_SEPARATOR === '\\';
    if (!$windows) {
        @shell_exec('stty -echo');
    }
    $s = trim((string) fgets(STDIN));
    if (!$windows) {
        @shell_exec('stty echo');
        fwrite(STDOUT, PHP_EOL);
    }
    return $s;
}

function senhaTemporaria(): string
{
    $alfabeto = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
    $s = '';
    for ($i = 0; $i < 12; $i++) {
        $s .= $alfabeto[random_int(0, strlen($alfabeto) - 1)];
    }
    return $s . random_int(10, 99);
}

/** Executa um arquivo .sql respeitando blocos DELIMITER (triggers). */
function executarSql(string $arquivo): void
{
    $pdo = Database::pdo();
    $delim = ';';
    $buffer = '';
    foreach (file($arquivo) ?: [] as $linha) {
        $t = trim($linha);
        if (preg_match('/^DELIMITER\s+(\S+)$/i', $t, $m)) {
            $delim = $m[1];
            continue;
        }
        if ($buffer === '' && ($t === '' || str_starts_with($t, '--'))) {
            continue;
        }
        $buffer .= $linha;
        if (str_ends_with(rtrim($linha), $delim)) {
            $sql = trim(substr(rtrim($buffer), 0, -strlen($delim)));
            if ($sql !== '') {
                $pdo->exec($sql);
            }
            $buffer = '';
        }
    }
    if (trim($buffer) !== '') {
        $pdo->exec($buffer);
    }
}

try {
switch ($cmd) {
    case 'verificar':
        out('Verificação do ambiente — Salinas Access Gate');
        version_compare(PHP_VERSION, '8.2.0', '>=') ? ok('PHP ' . PHP_VERSION) : falhou('PHP ' . PHP_VERSION . ' (mínimo 8.2)');
        foreach (['pdo_mysql', 'sodium', 'mbstring', 'fileinfo', 'json'] as $ext) {
            extension_loaded($ext) ? ok("extensão {$ext}") : falhou("extensão {$ext} ausente (habilite no php.ini)");
        }
        extension_loaded('curl') ? ok('extensão curl (Turnstile)') : out('  [--] extensão curl ausente (necessária só para Turnstile)');
        is_file(App::root('.env')) ? ok('.env encontrado') : falhou('.env ausente — copie .env.example para .env');
        try {
            Crypto::cpfHash('00000000000');
            Crypto::decryptCpf(Crypto::encryptCpf('52998224725')) === '52998224725' ? ok('chaves de criptografia do CPF') : falhou('chaves do CPF');
        } catch (Throwable $e) {
            falhou($e->getMessage());
        }
        try {
            $v = Database::value('SELECT VERSION()');
            ok('banco conectado: ' . $v . ' · fuso ' . Database::value('SELECT @@session.time_zone'));
            $n = Database::value("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'registros_acesso'");
            $n ? ok('tabelas criadas') : falhou('tabelas ausentes — rode: php bin/console.php migrar');
            $t = (int) Database::value("SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE()");
            $t >= 10 ? ok("{$t} triggers append-only ativas") : falhou("apenas {$t} triggers (esperado 10)");
            $a = (int) Database::value("SELECT COUNT(*) FROM usuarios WHERE papel = 'admin' AND ativo = 1");
            $a ? ok("{$a} administrador(es) ativo(s)") : falhou('nenhum admin — rode: php bin/console.php criar-admin');
        } catch (Throwable $e) {
            falhou('banco: ' . $e->getMessage());
        }
        if (Ldap::habilitado()) {
            extension_loaded('ldap') ? ok('extensão ldap') : falhou('extensão ldap ausente (necessária com LDAP_ENABLED=true)');
            try {
                $t = Ldap::testar();
                ok("Active Directory conectado ({$t['usuarios']} contas, {$t['tempo_ms']} ms)");
            } catch (Throwable $e) {
                falhou('Active Directory: ' . $e->getMessage());
            }
        } else {
            out('  [--] integração LDAP/AD desabilitada (LDAP_ENABLED=false)');
        }
        foreach (['storage/uploads', 'storage/logs', 'storage/sessions'] as $d) {
            $p = App::root($d);
            if (!is_dir($p)) {
                @mkdir($p, 0770, true);
            }
            is_writable($p) ? ok("{$d} gravável") : falhou("{$d} sem permissão de escrita");
        }
        break;

    case 'gerar-chaves':
        $k1 = Crypto::newKey();
        $k2 = Crypto::newKey();
        if (isset($opts['gravar'])) {
            $env = App::root('.env');
            if (!is_file($env)) {
                err('.env não existe. Copie .env.example para .env antes.');
            }
            $c = (string) file_get_contents($env);
            if (preg_match('/^CPF_ENCRYPTION_KEY=\S+/m', $c)) {
                err('O .env já possui CPF_ENCRYPTION_KEY. NÃO troque a chave com dados cadastrados (CPFs ficariam ilegíveis).');
            }
            $c = preg_replace('/^CPF_ENCRYPTION_KEY=.*$/m', 'CPF_ENCRYPTION_KEY=' . $k1, $c) ?? $c;
            $c = preg_replace('/^CPF_INDEX_KEY=.*$/m', 'CPF_INDEX_KEY=' . $k2, $c) ?? $c;
            file_put_contents($env, $c);
            ok('Chaves gravadas no .env. Guarde uma cópia em cofre de senhas (perda = CPFs irrecuperáveis).');
        } else {
            out('CPF_ENCRYPTION_KEY=' . $k1);
            out('CPF_INDEX_KEY=' . $k2);
        }
        break;

    case 'migrar':
        Database::pdo()->exec('CREATE TABLE IF NOT EXISTS schema_migrations (versao VARCHAR(100) PRIMARY KEY, aplicado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $feitas = array_column(Database::all('SELECT versao FROM schema_migrations'), 'versao');
        $arquivos = glob(App::root('database/migrations/*.sql')) ?: [];
        sort($arquivos);
        $n = 0;
        foreach ($arquivos as $arq) {
            $v = basename($arq);
            if (in_array($v, $feitas, true)) {
                continue;
            }
            out("Aplicando {$v} ...");
            executarSql($arq);
            Database::run('INSERT INTO schema_migrations (versao) VALUES (?)', [$v]);
            $n++;
        }
        ok($n ? "{$n} migração(ões) aplicada(s)." : 'Banco já está atualizado.');
        break;

    case 'criar-admin':
        $email = mb_strtolower(trim($opts['email'] ?? ''));
        $nome = trim($opts['nome'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $nome === '') {
            err('Uso: php bin/console.php criar-admin --email=ti@salinasgold.com.br --nome="Nome do Admin"');
        }
        if (Database::value('SELECT 1 FROM usuarios WHERE email = ?', [$email])) {
            err('Já existe usuário com este e-mail. Use redefinir-senha.');
        }
        $senha = $opts['senha'] ?? '';
        $temporaria = false;
        if ($senha === '') {
            $senha = perguntarSenha('Senha do admin (Enter = gerar temporária): ');
        }
        if ($senha === '') {
            $senha = senhaTemporaria();
            $temporaria = true;
        } elseif ($e = Auth::validarSenha($senha, $email)) {
            err($e);
        }
        $id = Uuid::v4();
        Database::insert('usuarios', [
            'id' => $id, 'nome' => $nome, 'email' => $email, 'senha_hash' => Auth::hash($senha),
            'papel' => 'admin', 'ativo' => 1, 'must_change_password' => $temporaria ? 1 : 0,
        ]);
        Audit::log('usuarios', $id, 'CREATE_ADMIN_CLI', ['email' => $email], $id);
        ok("Admin criado: {$email}");
        if ($temporaria) {
            out("  Senha temporária: {$senha}   (troca obrigatória no 1º acesso)");
        }
        break;

    case 'redefinir-senha':
        $email = mb_strtolower(trim($opts['email'] ?? ''));
        $u = Database::one('SELECT id FROM usuarios WHERE email = ?', [$email]) ?? err('Usuário não encontrado.');
        $senha = senhaTemporaria();
        Database::update('usuarios', ['senha_hash' => Auth::hash($senha), 'must_change_password' => 1, 'ativo' => 1, 'senha_alterada_em' => date('Y-m-d H:i:s')], 'id = ?', [$u['id']]);
        Audit::log('usuarios', $u['id'], 'RESET_SENHA_CLI', [], null);
        ok("Senha temporária de {$email}: {$senha}  (troca obrigatória no próximo acesso)");
        break;

    case 'bi-sql':
        // Gera o SQL do usuário de consulta para BI (executar como root/DBA). Não executa nada.
        $usuario = $opts['usuario'] ?? 'sag_bi';
        $host = $opts['host'] ?? '%';
        if (!preg_match('/^[A-Za-z0-9_]{1,32}$/', $usuario) || !preg_match('/^[A-Za-z0-9_.%:\-]{1,255}$/', $host)) {
            err('Usuário ou host inválido.');
        }
        $db = (string) App::config('db')['database'];
        $objetos = array_column(Database::all(
            "SELECT table_name AS n FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE 'bi!_%' ESCAPE '!' ORDER BY table_name"
        ), 'n');
        if (!$objetos) {
            err('Nenhuma view bi_* encontrada. Rode antes: php bin/console.php migrar');
        }
        $senha = (string) (getenv('SAG_BI_SENHA') ?: '');
        $sqlStr = static fn (string $v): string => "'" . str_replace(['\\', "'"], ['\\\\', "''"], $v) . "'";
        $conta = $sqlStr($usuario) . '@' . $sqlStr($host);
        out('-- Salinas Access Gate — usuário somente leitura para BI (MD-TI-SIS-CBA-006)');
        out('-- Execute como root. Gerado em ' . date('d/m/Y H:i') . ' (Horário de Mato Grosso – UTC-4). Reexecutar é seguro.');
        if ($senha === '') {
            out('-- ATENÇÃO: troque TROQUE_ESTA_SENHA por uma senha forte antes de executar.');
        }
        out("CREATE USER IF NOT EXISTS {$conta} IDENTIFIED BY " . $sqlStr($senha !== '' ? $senha : 'TROQUE_ESTA_SENHA') . ';');
        if ($senha !== '') {
            out("ALTER USER {$conta} IDENTIFIED BY " . $sqlStr($senha) . ';');
        }
        out("-- Limites: até 5 conexões simultâneas e consultas de no máximo 120 s (protege a portaria de relatórios pesados)");
        out("ALTER USER {$conta} WITH MAX_USER_CONNECTIONS 5 MAX_STATEMENT_TIME 120;");
        out("REVOKE ALL PRIVILEGES, GRANT OPTION FROM {$conta};");
        foreach ($objetos as $o) {
            out('GRANT SELECT ON `' . str_replace('`', '', $db) . '`.`' . $o . "` TO {$conta};");
        }
        break;

    case 'bi-verificar':
        // Confere se toda coluna das views bi_* está descrita no bi_dicionario (e vice-versa).
        $reais = Database::all(
            "SELECT table_name AS o, column_name AS c FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name LIKE 'bi!_%' ESCAPE '!'"
        );
        $doc = Database::all("SELECT objeto AS o, coluna AS c FROM bi_dicionario WHERE coluna <> '*'");
        $k = static fn (array $l): array => array_map(static fn ($r) => $r['o'] . '.' . $r['c'], $l);
        $semDescricao = array_diff($k($reais), $k($doc));
        $orfas = array_diff($k($doc), $k($reais));
        foreach ($semDescricao as $x) { falhou("sem descrição no bi_dicionario: {$x}"); }
        foreach ($orfas as $x) { falhou("descrita mas inexistente: {$x}"); }
        if ($semDescricao || $orfas) {
            exit(1);
        }
        ok(count($reais) . ' colunas em ' . count(array_unique(array_column($reais, 'o'))) . ' objetos bi_* documentadas.');
        break;

    case 'lgpd-retencao':
        // Política de retenção LGPD (agendar mensalmente). --simular mostra o que seria feito.
        $r = \App\Services\Lgpd::aplicarRetencao(isset($opts['simular']));
        out(($r['simulacao'] ? '[SIMULAÇÃO] ' : '') . sprintf(
            'Retenção LGPD: %d pessoa(s) anonimizada(s), %d solicitação(ões) antiga(s) anonimizada(s), %d foto(s) apagada(s). (cadastro: %d meses; fotos: %d meses)',
            $r['pessoas'], $r['solicitacoes'], $r['fotos'], \App\Services\Lgpd::retencaoMeses(), \App\Services\Lgpd::fotosMeses()
        ));
        break;

    case 'expirar-solicitacoes':
        $n1 = Database::run(
            "UPDATE solicitacoes_acesso SET status = 'expirada', status_observacao = 'Expirada automaticamente (data prevista passou)'
              WHERE status IN ('pendente','aguardando_ssma','aprovada') AND data_prevista < CURDATE()"
        )->rowCount();
        $n2 = Database::run(
            "UPDATE solicitacoes_acesso SET status = 'expirada', status_observacao = 'Link externo expirou sem preenchimento'
              WHERE aguardando_preenchimento = 1 AND status = 'pendente' AND link_expira_em < NOW()"
        )->rowCount();
        if ($n1 + $n2) {
            Audit::log('solicitacoes_acesso', null, 'EXPIRACAO_AUTOMATICA', ['por_data' => $n1, 'links' => $n2], null);
        }
        Database::run('DELETE FROM rate_limits WHERE criado_em < (NOW() - INTERVAL 1 DAY)');
        ok("Solicitações expiradas: {$n1} por data, {$n2} links.");
        break;

    case 'ldap-testar':
        if (!Ldap::disponivel()) {
            err('LDAP desabilitado ou extensão ldap ausente.');
        }
        $t = Ldap::testar();
        ok("Conectado a {$t['servidor']} em {$t['tempo_ms']} ms — {$t['usuarios']} contas pelo filtro");
        foreach ($t['grupos'] as $papel => $g) {
            $g['existe'] ? ok("grupo {$papel}: {$g['dn']}") : falhou("grupo {$papel} NÃO encontrado: {$g['dn']}");
        }
        break;

    case 'ldap-buscar':
        if (!Ldap::disponivel()) {
            err('LDAP desabilitado ou extensão ldap ausente.');
        }
        foreach (Ldap::buscar($pos[0] ?? err('Informe o termo de busca.'), 50) as $e) {
            out(sprintf('  %-22s %-35s %-40s %s%s', $e['login'], $e['nome'], $e['email'], $e['matricula'] ?? '', $e['ativo'] ? '' : '  [DESATIVADA]'));
        }
        break;

    case 'ldap-sincronizar':
        if (!Ldap::disponivel()) {
            err('LDAP desabilitado ou extensão ldap ausente.');
        }
        $r = LdapUsuarios::sincronizarTodos();
        ok("Verificados {$r['verificados']} · atualizados {$r['atualizados']} · desativados {$r['desativados']} · erros {$r['erros']}");
        if ($r['erros']) {
            exit(2);
        }
        break;

    case 'ldap-vincular':
        if (!Ldap::disponivel()) {
            err('LDAP desabilitado ou extensão ldap ausente.');
        }
        $u = Database::one('SELECT * FROM usuarios WHERE email = ?', [mb_strtolower($opts['email'] ?? '')]) ?? err('Usuário local não encontrado (--email).');
        $e = Ldap::porLogin($opts['login'] ?? '') ?? err('Conta não encontrada no AD (--login).');
        if (Database::value('SELECT 1 FROM usuarios WHERE ldap_guid = ? AND id <> ?', [$e['guid'], $u['id']])) {
            err('Esta conta do AD já está vinculada a outro usuário.');
        }
        Database::update('usuarios', [
            'auth_origem' => 'ldap', 'ldap_guid' => $e['guid'], 'ldap_usuario' => mb_strtolower($e['login']),
            'ldap_dn' => $e['dn'], 'ldap_sincronizado_em' => date('Y-m-d H:i:s'), 'must_change_password' => 0,
        ], 'id = ?', [$u['id']]);
        Audit::log('usuarios', $u['id'], 'LDAP_VINCULO_CLI', ['login' => $e['login']], null);
        ok("{$u['email']} agora entra com o usuário de rede {$e['login']}.");
        break;

    case 'importar-supabase':
        $pasta = $pos[0] ?? err('Informe a pasta com os CSVs exportados do Supabase.');
        (new ImportadorSupabase($pasta, isset($opts['simular'])))->executar();
        break;

    default:
        $doc = (string) file_get_contents(__FILE__);
        preg_match('#/\*\*(.*?)\*/#s', $doc, $m);
        out(trim(preg_replace('/^\s*\* ?/m', '', $m[1] ?? '')));
}
} catch (Throwable $e) {
    err($e->getMessage());
}
