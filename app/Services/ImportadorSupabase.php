<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Core\Auth;
use App\Core\Crypto;
use App\Core\Database;
use App\Core\Uuid;
use DateTime;
use DateTimeZone;
use RuntimeException;
use Throwable;

/**
 * Importa os CSVs exportados do Supabase (um arquivo por tabela, com cabeçalho):
 *   locais.csv, areas.csv, pessoas.csv, profiles.csv, user_roles.csv, veiculos.csv, documentos.csv,
 *   solicitacoes_acesso.csv, verificacoes_ssma.csv, registros_acesso.csv, registro_anexos.csv, audit_log.csv
 *
 * - IDs (UUID) são preservados → reexecução é segura (INSERT IGNORE).
 * - Datas em UTC são convertidas para o Horário de Mato Grosso – UTC-4.
 * - CPF é cifrado na importação (libsodium) + índice cego.
 * - Senhas do Supabase Auth NÃO são exportáveis: cada usuário recebe senha temporária,
 *   gravada em storage/importacao/senhas-temporarias-*.csv (apague após distribuir).
 * - Fotos: se existir a pasta <pasta>/registros-acesso-fotos/ com os arquivos do bucket, elas são copiadas.
 * - Use --simular para validar tudo e desfazer ao final.
 */
final class ImportadorSupabase
{
    private array $log = [];
    private array $usuariosConhecidos = [];
    private array $pessoasConhecidas = [];
    private DateTimeZone $tzLocal;
    private array $senhas = [];
    private array $arquivosCopiados = [];

    public function __construct(private string $pasta, private bool $simular = false)
    {
        $this->pasta = rtrim($pasta, '/\\');
        if (!is_dir($this->pasta)) {
            throw new RuntimeException('Pasta não encontrada: ' . $pasta);
        }
        $this->tzLocal = new DateTimeZone(App::config('app')['timezone']);
    }

    public function executar(): void
    {
        $this->info('Importação Supabase → MariaDB' . ($this->simular ? ' (SIMULAÇÃO — nada será gravado)' : ''));
        $pdo = Database::pdo();
        $pdo->exec('SET @sag_import = 1');
        $pdo->beginTransaction();
        try {
            $this->locais();
            $this->pessoas();
            $this->usuarios();
            $this->areas();
            $this->veiculos();
            $this->documentos();
            $this->solicitacoes();
            $this->verificacoes();
            $this->registros();
            $this->anexos();
            $this->auditoria();
            if ($this->simular) {
                $pdo->rollBack();
                foreach ($this->arquivosCopiados as $f) {
                    @unlink($f);
                }
                $this->info('Simulação concluída — alterações desfeitas.');
            } else {
                $pdo->commit();
                $this->gravarSenhas();
                $this->info('Importação concluída com sucesso.');
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            foreach ($this->arquivosCopiados as $f) {
                @unlink($f);
            }
            throw $e;
        } finally {
            $pdo->exec('SET @sag_import = NULL');
        }
    }

    /* ------------------------------------------------------------------ */
    private function locais(): void
    {
        $n = 0;
        foreach ($this->csv('locais') as $r) {
            $existe = Database::value('SELECT id FROM locais WHERE nome = ?', [$r['nome']]);
            if ($existe && $existe !== $r['id'] && !Database::value('SELECT 1 FROM registros_acesso WHERE local_id = ? LIMIT 1', [$existe])) {
                Database::run('DELETE FROM locais WHERE id = ?', [$existe]); // local semeado → assume o ID do Supabase
            }
            $n += $this->ins('locais', [
                'id' => $r['id'], 'nome' => $r['nome'], 'tipo' => $r['tipo'], 'endereco' => $this->n($r['endereco'] ?? null),
                'ativo' => $this->b($r['ativo'] ?? 'true'), 'created_at' => $this->dt($r['created_at'] ?? null),
            ]);
        }
        $this->info("locais: {$n}");
    }

    private function pessoas(): void
    {
        $n = 0;
        $cpfInvalidos = 0;
        $hashes = [];
        foreach ($this->csv('pessoas') as $r) {
            $cpf = Crypto::cpfDigits($r['cpf'] ?? '');
            $rg = $this->n($r['rg'] ?? null);
            if ($cpf !== '' && (!Crypto::cpfValido($cpf) || isset($hashes[$cpf]))) {
                // CPF inválido/duplicado: preserva o valor original no campo RG/documento
                $cpfInvalidos++;
                $rg = $rg ?? ($r['cpf'] ?? null);
                $cpf = '';
            }
            if ($cpf !== '') {
                $hashes[$cpf] = true;
            }
            $tel = $this->n($r['telefone_principal'] ?? null) ?? $this->n($r['telefone'] ?? null);
            $n += $this->ins('pessoas', [
                'id' => $r['id'], 'nome' => $r['nome'],
                'cpf_enc' => Crypto::encryptCpf($cpf), 'cpf_hash' => Crypto::cpfHash($cpf), 'rg' => $rg,
                'matricula' => $this->n($r['matricula'] ?? null), 'categoria' => $r['categoria'],
                'empresa' => $this->n($r['empresa'] ?? null), 'funcao' => $this->n($r['funcao'] ?? null),
                'telefone' => $this->n($r['telefone'] ?? null), 'telefone_principal' => $tel,
                'email_principal' => $this->n($r['email_principal'] ?? null), 'whatsapp' => $this->n($r['whatsapp'] ?? null),
                'observacoes_contato' => $this->n($r['observacoes_contato'] ?? null),
                'destino_padrao' => $this->n($r['destino_padrao'] ?? null),
                'responsavel_interno_padrao' => $this->n($r['responsavel_interno_padrao'] ?? null),
                'observacoes_portaria_padrao' => $this->n($r['observacoes_portaria_padrao'] ?? null),
                'observacoes' => $this->n($r['observacoes'] ?? null), 'ativo' => $this->b($r['ativo'] ?? 'true'),
                'created_at' => $this->dt($r['created_at'] ?? null), 'updated_at' => $this->dt($r['updated_at'] ?? null),
            ]);
        }
        foreach (Database::all('SELECT id FROM pessoas') as $p) {
            $this->pessoasConhecidas[$p['id']] = true;
        }
        $this->info("pessoas: {$n}" . ($cpfInvalidos ? " ({$cpfInvalidos} CPF inválido/duplicado movido para RG/documento — revisar)" : ''));
    }

    private function usuarios(): void
    {
        $papeis = [];
        $prioridade = ['admin' => 4, 'supervisor' => 3, 'ssma' => 2, 'porteiro' => 1];
        foreach ($this->csv('user_roles') as $r) {
            $atual = $papeis[$r['user_id']] ?? null;
            if ($atual === null || ($prioridade[$r['role']] ?? 0) > ($prioridade[$atual] ?? 0)) {
                $papeis[$r['user_id']] = $r['role'];
            }
        }
        $n = 0;
        foreach ($this->csv('profiles') as $r) {
            $email = mb_strtolower(trim($r['email']));
            if (Database::value('SELECT 1 FROM usuarios WHERE email = ? AND id <> ?', [$email, $r['id']])) {
                $this->info("  ! e-mail {$email} já existe com outro ID — usuário ignorado");
                continue;
            }
            $senha = $this->senhaTemporaria();
            $pessoaId = $this->n($r['pessoa_id'] ?? null);
            $novo = $this->ins('usuarios', [
                'id' => $r['id'], 'nome' => $r['nome'], 'email' => $email, 'senha_hash' => Auth::hash($senha),
                'papel' => $papeis[$r['id']] ?? 'porteiro', 'matricula' => $this->n($r['matricula'] ?? null),
                'ativo' => $this->b($r['ativo'] ?? 'true'), 'must_change_password' => 1,
                'seguranca_patrimonial' => $this->b($r['seguranca_patrimonial'] ?? 'false'),
                'pessoa_id' => ($pessoaId && isset($this->pessoasConhecidas[$pessoaId])) ? $pessoaId : null,
                'created_at' => $this->dt($r['created_at'] ?? null),
            ]);
            if ($novo) {
                $this->senhas[] = [$email, $r['nome'], $papeis[$r['id']] ?? 'porteiro', $senha];
            }
            $n += $novo;
        }
        foreach (Database::all('SELECT id FROM usuarios') as $u) {
            $this->usuariosConhecidos[$u['id']] = true;
        }
        $this->info("usuarios: {$n} (senhas temporárias geradas; troca obrigatória no 1º acesso)");
    }

    private function areas(): void
    {
        $n = 0;
        foreach ($this->csv('areas') as $r) {
            $resp = $this->n($r['responsavel_user_id'] ?? null);
            $existe = Database::value('SELECT id FROM areas WHERE nome = ?', [$r['nome']]);
            if ($existe && $existe !== $r['id']) {
                // Área semeada com outro ID: alinha o ID ao do Supabase (ainda sem referências)
                $refs = (int) Database::value('SELECT COUNT(*) FROM solicitacoes_acesso WHERE area_id = ?', [$existe]);
                if ($refs === 0) {
                    Database::run('DELETE FROM areas WHERE id = ?', [$existe]);
                }
            }
            $n += $this->ins('areas', [
                'id' => $r['id'], 'nome' => $r['nome'], 'ativo' => $this->b($r['ativo'] ?? 'true'),
                'responsavel_user_id' => ($resp && isset($this->usuariosConhecidos[$resp])) ? $resp : null,
                'created_at' => $this->dt($r['created_at'] ?? null),
            ]);
        }
        $this->info("areas: {$n}");
    }

    private function veiculos(): void
    {
        $n = 0;
        foreach ($this->csv('veiculos') as $r) {
            $n += $this->ins('veiculos', [
                'id' => $r['id'], 'placa' => strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $r['placa']) ?? ''),
                'tipo' => $r['tipo'] ?: 'carro', 'marca_modelo' => $this->n($r['marca_modelo'] ?? null),
                'cor' => $this->n($r['cor'] ?? null), 'empresa' => $this->n($r['empresa'] ?? null), 'tag' => $this->n($r['tag'] ?? null),
                'comissionado_mina' => $this->b($r['comissionado_mina'] ?? 'false'),
                'comissionamento_validade' => $this->d($r['comissionamento_validade'] ?? null),
                'observacoes' => $this->n($r['observacoes'] ?? null), 'ativo' => $this->b($r['ativo'] ?? 'true'),
                'created_at' => $this->dt($r['created_at'] ?? null),
            ]);
        }
        $this->info("veiculos: {$n}");
    }

    private function documentos(): void
    {
        $n = 0;
        foreach ($this->csv('documentos') as $r) {
            if (!isset($this->pessoasConhecidas[$r['pessoa_id']])) {
                continue;
            }
            $n += $this->ins('documentos', [
                'id' => $r['id'], 'pessoa_id' => $r['pessoa_id'], 'tipo' => $r['tipo'], 'numero' => $this->n($r['numero'] ?? null),
                'emissao' => $this->d($r['emissao'] ?? null), 'validade' => $this->d($r['validade']),
                'observacoes' => $this->n($r['observacoes'] ?? null), 'created_at' => $this->dt($r['created_at'] ?? null),
            ]);
        }
        $this->info("documentos: {$n}");
    }

    private function solicitacoes(): void
    {
        $n = 0;
        foreach ($this->csv('solicitacoes_acesso') as $r) {
            $aguardando = ($r['visitante_nome'] ?? '') === '(aguardando preenchimento externo)' ? 1 : 0;
            $origem = in_array($r['origem'] ?? '', ['interna', 'link', 'publica'], true) ? $r['origem'] : 'interna';
            $n += $this->ins('solicitacoes_acesso', [
                'id' => $r['id'], 'visitante_nome' => $r['visitante_nome'], 'visitante_doc' => $this->n($r['visitante_doc'] ?? null),
                'empresa' => $this->n($r['empresa'] ?? null), 'contato_telefone' => $this->n($r['contato_telefone'] ?? null),
                'contato_email' => $this->n($r['contato_email'] ?? null), 'tipo' => $r['tipo'] ?: 'visitante',
                'area_id' => $this->ref('areas', $r['area_id'] ?? null),
                'responsavel_interno_id' => $this->usr($r['responsavel_interno_id'] ?? null),
                'pessoa_id' => $this->pes($r['pessoa_id'] ?? null),
                'local_id' => $this->ref('locais', $r['local_id'] ?? null),
                'data_prevista' => $this->d($r['data_prevista']), 'motivo' => $this->n($r['motivo'] ?? null),
                'atividade_risco' => $this->b($r['atividade_risco'] ?? 'false'), 'status' => $r['status'],
                'status_observacao' => $this->n($r['status_observacao'] ?? null), 'origem' => $origem,
                'token_externo' => $this->n($r['token_externo'] ?? null), 'link_expira_em' => $this->dt($r['link_expira_em'] ?? null, true),
                'aguardando_preenchimento' => $aguardando,
                'autorizado_por' => $this->usr($r['autorizado_por'] ?? null), 'autorizado_em' => $this->dt($r['autorizado_em'] ?? null, true),
                'motivo_recusa' => $this->n($r['motivo_recusa'] ?? null), 'consentimento_lgpd' => $this->b($r['consentimento_lgpd'] ?? 'false'),
                'criado_por' => $this->usr($r['criado_por'] ?? null), 'created_at' => $this->dt($r['created_at'] ?? null),
            ]);
        }
        $this->info("solicitacoes_acesso: {$n}");
    }

    private function verificacoes(): void
    {
        $n = 0;
        foreach ($this->csv('verificacoes_ssma') as $r) {
            if (!Database::value('SELECT 1 FROM solicitacoes_acesso WHERE id = ?', [$r['solicitacao_id']])) {
                continue;
            }
            $check = $r['checklist'] ?? '{}';
            $n += $this->ins('verificacoes_ssma', [
                'id' => $r['id'], 'solicitacao_id' => $r['solicitacao_id'], 'pessoa_id' => $this->pes($r['pessoa_id'] ?? null),
                'status' => $r['status'], 'observacao' => $this->n($r['observacao'] ?? null),
                'checklist' => json_decode((string) $check) !== null ? $check : '{}', 'validade_ate' => $this->d($r['validade_ate'] ?? null),
                'verificado_por' => $this->usr($r['verificado_por'] ?? null), 'verificado_por_nome' => $this->n($r['verificado_por_nome'] ?? null),
                'created_at' => $this->dt($r['created_at'] ?? null),
            ]);
        }
        $this->info("verificacoes_ssma: {$n}");
    }

    private function registros(): void
    {
        $rows = iterator_to_array($this->csv('registros_acesso'), false);
        usort($rows, fn ($a, $b) => strcmp((string) $a['data_hora'], (string) $b['data_hora']));
        $n = 0;
        $semPessoa = 0;
        foreach ($rows as $r) {
            $pessoa = $this->pes($r['pessoa_id'] ?? null);
            if ((($r['pessoa_id'] ?? '') !== '' && $pessoa === null) || $this->ref('locais', $r['local_id'] ?? null) === null) {
                $semPessoa++;
                continue;
            }
            $n += $this->ins('registros_acesso', [
                'id' => $r['id'], 'pessoa_id' => $pessoa, 'veiculo_id' => $this->ref('veiculos', $r['veiculo_id'] ?? null),
                'local_id' => $r['local_id'], 'sentido' => $r['sentido'], 'data_hora' => $this->dt($r['data_hora']),
                'destino' => $this->n($r['destino'] ?? null), 'responsavel_interno' => $this->n($r['responsavel_interno'] ?? null),
                'responsavel_interno_id' => $this->pes($r['responsavel_interno_id'] ?? null),
                'responsavel_interno_nome' => $this->n($r['responsavel_interno_nome'] ?? null),
                'responsavel_interno_telefone' => $this->n($r['responsavel_interno_telefone'] ?? null),
                'responsavel_interno_email' => $this->n($r['responsavel_interno_email'] ?? null),
                'porteiro_id' => $this->usrObrigatorio($r['porteiro_id']), 'observacoes' => $this->n($r['observacoes'] ?? null),
                'created_at' => $this->dt($r['created_at'] ?? $r['data_hora']),
            ]);
        }
        $this->info("registros_acesso: {$n}" . ($semPessoa ? " ({$semPessoa} ignorados: pessoa ou local inexistente)" : ''));
    }

    private function anexos(): void
    {
        $base = $this->pasta . '/registros-acesso-fotos';
        $n = 0;
        $faltando = 0;
        foreach ($this->csv('registro_anexos') as $r) {
            $orig = $base . '/' . ltrim((string) $r['storage_path'], '/');
            if (!is_file($orig) || !Database::value('SELECT 1 FROM registros_acesso WHERE id = ?', [$r['registro_acesso_id']])) {
                $faltando++;
                continue;
            }
            if (Database::value('SELECT 1 FROM registro_anexos WHERE id = ?', [$r['id']])) {
                continue;
            }
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($orig) ?: '';
            $ext = Upload::MIMES[$mime] ?? null;
            if ($ext === null) {
                $faltando++;
                continue;
            }
            $quando = $this->dt($r['created_at'] ?? null) ?? date('Y-m-d H:i:s');
            $rel = substr($quando, 0, 4) . '/' . substr($quando, 5, 2) . '/' . Uuid::v4() . '.' . $ext;
            $dest = Upload::dir() . '/' . $rel;
            @mkdir(dirname($dest), 0750, true);
            copy($orig, $dest);
            $this->arquivosCopiados[] = $dest;
            $n += $this->ins('registro_anexos', [
                'id' => $r['id'], 'registro_acesso_id' => $r['registro_acesso_id'], 'storage_path' => $rel,
                'tipo_foto' => $r['tipo_foto'] ?: 'outra', 'nome_arquivo' => $this->n($r['nome_arquivo'] ?? null),
                'mime_type' => $mime, 'tamanho_bytes' => filesize($dest), 'sha256' => hash_file('sha256', $dest),
                'criado_por' => $this->usr($r['criado_por'] ?? null), 'created_at' => $quando,
            ]);
        }
        $this->info("registro_anexos: {$n}" . ($faltando ? " ({$faltando} sem arquivo — copie o bucket para {$base})" : ''));
    }

    private function auditoria(): void
    {
        $rows = iterator_to_array($this->csv('audit_log'), false);
        usort($rows, fn ($a, $b) => strcmp((string) $a['data_hora'], (string) $b['data_hora']));
        $n = 0;
        foreach ($rows as $r) {
            $det = $r['detalhes'] ?? null;
            $n += $this->ins('audit_log', [
                'id' => $r['id'], 'entidade' => $r['entidade'], 'registro_id' => $this->n($r['registro_id'] ?? null),
                'acao' => $r['acao'], 'usuario_id' => $this->n($r['usuario_id'] ?? null),
                'detalhes' => ($det !== null && $det !== '' && json_decode($det) !== null) ? $det : null,
                'ip' => 'supabase', 'data_hora' => $this->dt($r['data_hora']),
            ]);
        }
        $this->info("audit_log: {$n} (histórico do Supabase)");
    }

    /* ------------------------------------------------------------------ */
    /** @return \Generator<int, array<string, string>> */
    private function csv(string $tabela): \Generator
    {
        $arq = $this->pasta . '/' . $tabela . '.csv';
        if (!is_file($arq)) {
            $this->info("  (arquivo {$tabela}.csv não encontrado — ignorado)");
            return;
        }
        $h = fopen($arq, 'r');
        $primeira = (string) fgets($h);
        $delim = substr_count($primeira, ';') > substr_count($primeira, ',') ? ';' : ',';
        rewind($h);
        $cab = fgetcsv($h, 0, $delim, '"', '');
        if (!$cab) {
            return;
        }
        $cab = array_map(fn ($c) => trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $c) ?? ''), $cab);
        while (($l = fgetcsv($h, 0, $delim, '"', '')) !== false) {
            if ($l === [null]) {
                continue;
            }
            yield array_combine($cab, array_pad(array_slice($l, 0, count($cab)), count($cab), ''));
        }
        fclose($h);
    }

    private function ins(string $tabela, array $dados): int
    {
        $cols = array_keys($dados);
        $sql = 'INSERT IGNORE INTO `' . $tabela . '` (`' . implode('`, `', $cols) . '`) VALUES (' . Database::in($cols) . ')';
        return Database::run($sql, array_values($dados))->rowCount();
    }

    private function n(?string $v): ?string
    {
        $v = $v === null ? null : trim($v);
        return ($v === '' || $v === null || strtolower($v) === 'null') ? null : $v;
    }

    private function b(?string $v): int
    {
        return in_array(strtolower(trim((string) $v)), ['true', 't', '1', 'sim', 'yes'], true) ? 1 : 0;
    }

    private function d(?string $v): ?string
    {
        $v = $this->n($v);
        return $v === null ? null : substr($v, 0, 10);
    }

    /** timestamptz (UTC) → DATETIME local (UTC-4). */
    private function dt(?string $v, bool $nulo = false): ?string
    {
        $v = $this->n($v);
        if ($v === null) {
            return $nulo ? null : date('Y-m-d H:i:s');
        }
        $v = preg_replace('/([+-]\d{2})$/', '$1:00', $v) ?? $v; // "+00" → "+00:00"
        try {
            $d = new DateTime($v, new DateTimeZone('UTC'));
            return $d->setTimezone($this->tzLocal)->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return $nulo ? null : date('Y-m-d H:i:s');
        }
    }

    private function ref(string $tabela, ?string $id): ?string
    {
        $id = $this->n($id);
        return ($id && Database::value('SELECT 1 FROM `' . $tabela . '` WHERE id = ?', [$id])) ? $id : null;
    }

    private function usr(?string $id): ?string
    {
        $id = $this->n($id);
        return ($id && isset($this->usuariosConhecidos[$id])) ? $id : null;
    }

    private function pes(?string $id): ?string
    {
        $id = $this->n($id);
        return ($id && isset($this->pessoasConhecidas[$id])) ? $id : null;
    }

    /** Porteiro é obrigatório: cria usuário placeholder inativo se não veio no export. */
    private function usrObrigatorio(string $id): string
    {
        if (isset($this->usuariosConhecidos[$id])) {
            return $id;
        }
        $this->ins('usuarios', [
            'id' => $id, 'nome' => 'Usuário migrado ' . substr($id, 0, 8), 'email' => 'migrado-' . substr($id, 0, 8) . '@invalido.local',
            'senha_hash' => Auth::hash(bin2hex(random_bytes(16))), 'papel' => 'porteiro', 'ativo' => 0, 'must_change_password' => 1,
        ]);
        $this->usuariosConhecidos[$id] = true;
        $this->info("  ! porteiro {$id} não estava em profiles.csv — criado usuário inativo placeholder");
        return $id;
    }

    private function senhaTemporaria(): string
    {
        $a = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
        $s = '';
        for ($i = 0; $i < 10; $i++) {
            $s .= $a[random_int(0, strlen($a) - 1)];
        }
        return $s . random_int(10, 99);
    }

    private function gravarSenhas(): void
    {
        if (!$this->senhas) {
            return;
        }
        $dir = App::root('storage/importacao');
        @mkdir($dir, 0700, true);
        $arq = $dir . '/senhas-temporarias-' . date('Ymd-His') . '.csv';
        $h = fopen($arq, 'w');
        fwrite($h, "\xEF\xBB\xBF");
        fputcsv($h, ['email', 'nome', 'perfil', 'senha_temporaria'], ';', '"', '');
        foreach ($this->senhas as $l) {
            fputcsv($h, $l, ';', '"', '');
        }
        fclose($h);
        @chmod($arq, 0600);
        $this->info("Senhas temporárias: {$arq}  ← distribua com segurança e APAGUE o arquivo.");
    }

    private function info(string $m): void
    {
        $this->log[] = $m;
        if (PHP_SAPI === 'cli') {
            fwrite(STDOUT, $m . PHP_EOL);
        }
    }
}
