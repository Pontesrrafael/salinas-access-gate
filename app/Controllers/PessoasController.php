<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Crypto;
use App\Core\Database;
use App\Core\Http;
use App\Core\Uuid;
use App\Services\Csv;

final class PessoasController extends Controller
{
    private const CAMPOS = [
        'nome' => 200, 'rg' => 30, 'matricula' => 50, 'empresa' => 150, 'funcao' => 120,
        'telefone_principal' => 30, 'email_principal' => 190, 'whatsapp' => 30, 'observacoes_contato' => 2000,
        'destino_padrao' => 150, 'responsavel_interno_padrao' => 150, 'observacoes_portaria_padrao' => 2000,
        'observacoes' => 2000,
    ];

    public function index(): void
    {
        Auth::authorize('pessoas.ver');
        [$pagina, $limite, $offset] = $this->paginacao(50);
        [$where, $params] = $this->filtros();
        $total = (int) Database::value('SELECT COUNT(*) FROM pessoas p WHERE ' . $where, $params);
        $rows = Database::all(
            'SELECT p.*, u.id AS usuario_id, u.email AS usuario_email, u.papel AS usuario_papel
               FROM pessoas p LEFT JOIN usuarios u ON u.pessoa_id = p.id
              WHERE ' . $where . ' ORDER BY p.nome LIMIT ' . $limite . ' OFFSET ' . $offset,
            $params
        );
        $docs = [];
        if ($rows) {
            $ids = array_column($rows, 'id');
            foreach (Database::all('SELECT pessoa_id, tipo, validade FROM documentos WHERE pessoa_id IN (' . Database::in($ids) . ') ORDER BY validade', $ids) as $d) {
                $docs[$d['pessoa_id']][] = $d;
            }
        }
        $podeVer = Auth::can('pessoas.ver_cpf');
        foreach ($rows as &$r) {
            $cpf = Crypto::decryptCpf($r['cpf_enc']);
            $r['cpf_exibicao'] = $podeVer ? Crypto::formatCpf($cpf) : Crypto::maskCpf($cpf);
            $r['documentos'] = $docs[$r['id']] ?? [];
            unset($r['cpf_enc'], $r['cpf_hash']);
        }
        unset($r);

        $this->view('pessoas/index', [
            'titulo'    => 'Pessoas',
            'descricao' => 'Colaboradores, visitantes e terceiros',
            'rows'      => $rows,
            'total'     => $total,
            'pagina'    => $pagina,
            'paginas'   => max(1, (int) ceil($total / $limite)),
            'filtro'    => ['q' => Http::str('q'), 'categoria' => Http::str('categoria'), 'situacao' => Http::str('situacao', 'ativos')],
        ]);
    }

    public function nova(): void
    {
        Auth::authorize('pessoas.criar');
        $this->view('pessoas/form', ['titulo' => 'Nova pessoa', 'p' => null, 'docs' => [], 'usuariosLivres' => []]);
    }

    public function criar(): void
    {
        Auth::authorize('pessoas.criar');
        $dados = $this->dadosForm('/pessoas/nova');
        $cpf = Http::str('cpf');
        if ($cpf !== '') {
            if (!Crypto::cpfValido($cpf)) {
                $this->falha('CPF inválido.', '/pessoas/nova');
            }
            if ($outra = Database::value('SELECT nome FROM pessoas WHERE cpf_hash = ?', [Crypto::cpfHash($cpf)])) {
                $this->falha('CPF já cadastrado para ' . $outra . '.', '/pessoas/nova');
            }
        }
        $id = Uuid::v4();
        Database::insert('pessoas', [
            'id' => $id, ...$dados,
            'cpf_enc' => Crypto::encryptCpf($cpf), 'cpf_hash' => Crypto::cpfHash($cpf),
            'telefone' => $dados['telefone_principal'],
        ]);
        Audit::log('pessoas', $id, 'INSERT', ['nome' => $dados['nome'], 'categoria' => $dados['categoria'], 'com_cpf' => $cpf !== '']);
        $this->ok('Pessoa cadastrada.', '/pessoas/' . $id);
    }

    public function editar(string $id): void
    {
        Auth::authorize('pessoas.ver');
        $p = $this->carregar($id);
        $cpf = Crypto::decryptCpf($p['cpf_enc']);
        $p['cpf_exibicao'] = Auth::can('pessoas.ver_cpf') ? Crypto::formatCpf($cpf) : Crypto::maskCpf($cpf);
        $p['tem_cpf'] = $cpf !== null;
        unset($p['cpf_enc'], $p['cpf_hash']);
        $p['usuario'] = Database::one('SELECT id, nome, email, papel FROM usuarios WHERE pessoa_id = ?', [$id]);
        $usuariosLivres = Auth::can('pessoas.vincular_usuario')
            ? Database::all('SELECT id, nome, email FROM usuarios WHERE ativo = 1 AND pessoa_id IS NULL ORDER BY nome')
            : [];
        $acessos = Database::all(
            'SELECT r.id, r.sentido, r.data_hora, l.nome AS local_nome, v.placa
               FROM registros_acesso r JOIN locais l ON l.id = r.local_id LEFT JOIN veiculos v ON v.id = r.veiculo_id
              WHERE r.pessoa_id = ? ORDER BY r.data_hora DESC, r.seq DESC LIMIT 10',
            [$id]
        );
        $this->view('pessoas/form', [
            'titulo'         => $p['nome'],
            'descricao'      => 'Cadastro de pessoa',
            'p'              => $p,
            'docs'           => Database::all('SELECT * FROM documentos WHERE pessoa_id = ? ORDER BY validade', [$id]),
            'usuariosLivres' => $usuariosLivres,
            'acessos'        => $acessos,
        ]);
    }

    public function atualizar(string $id): void
    {
        Auth::authorize('pessoas.editar');
        $atual = $this->carregar($id);
        $volta = '/pessoas/' . $id;
        $dados = $this->dadosForm($volta);
        $dados['ativo'] = Http::bool('ativo') ? 1 : 0;
        $dados['telefone'] = $dados['telefone_principal'];

        // CPF: somente perfis com permissão podem alterar (regra herdada do sistema anterior)
        $cpfAlterado = false;
        if (Auth::can('pessoas.ver_cpf') && array_key_exists('cpf', $_POST)) {
            $cpf = Http::str('cpf');
            $novoHash = Crypto::cpfHash($cpf);
            if ($novoHash !== $atual['cpf_hash']) {
                if ($cpf !== '' && !Crypto::cpfValido($cpf)) {
                    $this->falha('CPF inválido.', $volta);
                }
                if ($novoHash && Database::value('SELECT 1 FROM pessoas WHERE cpf_hash = ? AND id <> ?', [$novoHash, $id])) {
                    $this->falha('CPF já cadastrado para outra pessoa.', $volta);
                }
                $dados['cpf_enc'] = Crypto::encryptCpf($cpf);
                $dados['cpf_hash'] = $novoHash;
                $cpfAlterado = true;
            }
        }
        $alterados = [];
        foreach ($dados as $k => $v) {
            if (!in_array($k, ['cpf_enc', 'cpf_hash'], true) && (string) ($atual[$k] ?? '') !== (string) ($v ?? '')) {
                $alterados[] = $k;
            }
        }
        Database::update('pessoas', $dados, 'id = ?', [$id]);
        Audit::log('pessoas', $id, 'UPDATE', ['campos' => $alterados, 'cpf_alterado' => $cpfAlterado]);
        $this->ok('Cadastro atualizado.', $volta);
    }

    public function excluir(string $id): void
    {
        Auth::authorize('pessoas.excluir');
        $p = $this->carregar($id);
        $usos = (int) Database::value(
            'SELECT (SELECT COUNT(*) FROM registros_acesso WHERE pessoa_id = ? OR responsavel_interno_id = ?)
                  + (SELECT COUNT(*) FROM verificacoes_ssma WHERE pessoa_id = ?)',
            [$id, $id, $id]
        );
        if ($usos > 0) {
            $this->falha('Não é possível excluir: existem registros de acesso/SSMA vinculados (histórico imutável). Use "Inativar".', '/pessoas/' . $id);
        }
        Database::transaction(function () use ($id, $p): void {
            Database::run('UPDATE usuarios SET pessoa_id = NULL WHERE pessoa_id = ?', [$id]);
            Database::run('DELETE FROM pessoas WHERE id = ?', [$id]);
            Audit::log('pessoas', $id, 'DELETE', ['nome' => $p['nome'], 'categoria' => $p['categoria']]);
        });
        $this->ok('Pessoa excluída.', '/pessoas');
    }

    public function revelarCpf(string $id): void
    {
        Auth::authorize('pessoas.ver_cpf');
        $p = $this->carregar($id);
        $motivo = Http::nstr('motivo', 300);
        Audit::log('pessoas', $id, 'reveal_cpf', ['motivo' => $motivo]);
        Http::json(['cpf' => Crypto::formatCpf(Crypto::decryptCpf($p['cpf_enc']))]);
    }

    public function adicionarDocumento(string $id): void
    {
        Auth::authorize('documentos.gerir');
        $this->carregar($id);
        $volta = '/pessoas/' . $id;
        $tipo = Http::str('tipo');
        $validade = Http::str('validade');
        $emissao = Http::str('emissao');
        if (!array_key_exists($tipo, TIPOS_DOC)) {
            $this->falha('Tipo de documento inválido.', $volta);
        }
        if (!$this->data($validade)) {
            $this->falha('Informe a validade do documento.', $volta);
        }
        if ($emissao !== '' && (!$this->data($emissao) || $emissao > $validade)) {
            $this->falha('Data de emissão inválida.', $volta);
        }
        $did = Uuid::v4();
        Database::insert('documentos', [
            'id' => $did, 'pessoa_id' => $id, 'tipo' => $tipo, 'numero' => Http::nstr('numero', 60),
            'emissao' => $emissao ?: null, 'validade' => $validade, 'observacoes' => Http::nstr('observacoes', 1000),
        ]);
        Audit::log('documentos', $did, 'INSERT', ['pessoa_id' => $id, 'tipo' => $tipo, 'validade' => $validade]);
        $this->ok('Documento adicionado.', $volta);
    }

    public function excluirDocumento(string $id, string $doc): void
    {
        Auth::authorize('documentos.excluir');
        $this->uuidOu404($doc);
        $d = Database::one('SELECT * FROM documentos WHERE id = ? AND pessoa_id = ?', [$doc, $id]) ?? Http::abort(404);
        Database::run('DELETE FROM documentos WHERE id = ?', [$doc]);
        Audit::log('documentos', $doc, 'DELETE', ['pessoa_id' => $id, 'tipo' => $d['tipo'], 'validade' => $d['validade']]);
        $this->ok('Documento removido.', '/pessoas/' . $id);
    }

    public function vincularUsuario(string $id): void
    {
        Auth::authorize('pessoas.vincular_usuario');
        $this->carregar($id);
        $uid = Http::str('usuario_id');
        try {
        Database::transaction(function () use ($id, $uid): void {
            Database::run('UPDATE usuarios SET pessoa_id = NULL WHERE pessoa_id = ?', [$id]);
            if (Uuid::valid($uid)) {
                $n = Database::run('UPDATE usuarios SET pessoa_id = ? WHERE id = ? AND pessoa_id IS NULL', [$id, $uid])->rowCount();
                if ($n === 0) {
                    throw new \RuntimeException('Este usuário já está vinculado a outra pessoa.');
                }
            }
            Audit::log('pessoas', $id, 'VINCULO_USUARIO', ['usuario_id' => $uid ?: null]);
        });
        } catch (\RuntimeException $e) {
            $this->falha($e->getMessage(), '/pessoas/' . $id);
        }
        $this->ok('Vínculo atualizado.', '/pessoas/' . $id);
    }

    public function exportar(): void
    {
        Auth::authorize('pessoas.ver');
        [$where, $params] = $this->filtros();
        $comCpf = Auth::can('pessoas.ver_cpf') && Http::bool('cpf');
        $rows = Database::all('SELECT * FROM pessoas p WHERE ' . $where . ' ORDER BY p.nome', $params);
        Audit::log('pessoas', 'export', $comCpf ? 'export_cpf' : 'export', ['total' => count($rows), 'filtros' => $_GET]);
        Csv::download('pessoas-' . date('Ymd-His') . '.csv',
            ['Nome', 'CPF', 'Matrícula', 'Categoria', 'Empresa', 'Função', 'Telefone', 'E-mail', 'Ativo'],
            (function () use ($rows, $comCpf) {
                foreach ($rows as $r) {
                    $cpf = Crypto::decryptCpf($r['cpf_enc']);
                    yield [$r['nome'], $comCpf ? Crypto::formatCpf($cpf) : Crypto::maskCpf($cpf), $r['matricula'],
                        CATEGORIAS[$r['categoria']] ?? $r['categoria'], $r['empresa'], $r['funcao'],
                        $r['telefone_principal'], $r['email_principal'], (int) $r['ativo'] ? 'Sim' : 'Não'];
                }
            })()
        );
    }

    /* ------------------------------------------------------------------ */
    private function filtros(): array
    {
        $where = ['1 = 1'];
        $params = [];
        $q = Http::str('q');
        $cat = Http::str('categoria');
        $sit = Http::str('situacao', 'ativos');
        if ($q !== '') {
            $dig = Crypto::cpfDigits($q);
            if (strlen($dig) === 11 && preg_match('/^[\d.\-\s]+$/', $q)) {
                $where[] = 'p.cpf_hash = ?';
                $params[] = Crypto::cpfHash($dig);
            } else {
                $like = '%' . addcslashes($q, '%_\\') . '%';
                $where[] = '(p.nome LIKE ? OR p.matricula LIKE ? OR p.empresa LIKE ? OR p.email_principal LIKE ?)';
                array_push($params, $like, $like, $like, $like);
            }
        }
        if (array_key_exists($cat, CATEGORIAS)) {
            $where[] = 'p.categoria = ?';
            $params[] = $cat;
        }
        if ($sit === 'ativos') {
            $where[] = 'p.ativo = 1';
        } elseif ($sit === 'inativos') {
            $where[] = 'p.ativo = 0';
        }
        return [implode(' AND ', $where), $params];
    }

    private function dadosForm(string $volta): array
    {
        $d = [];
        foreach (self::CAMPOS as $campo => $max) {
            $d[$campo] = Http::nstr($campo, $max);
        }
        if ($d['nome'] === null) {
            $this->falha('Nome é obrigatório.', $volta);
        }
        $cat = Http::str('categoria');
        if (!array_key_exists($cat, CATEGORIAS)) {
            $this->falha('Selecione a categoria.', $volta);
        }
        $d['categoria'] = $cat;
        if ($d['email_principal'] !== null && !filter_var($d['email_principal'], FILTER_VALIDATE_EMAIL)) {
            $this->falha('E-mail principal inválido.', $volta);
        }
        return $d;
    }

    private function carregar(string $id): array
    {
        $this->uuidOu404($id);
        return Database::one('SELECT * FROM pessoas WHERE id = ?', [$id]) ?? Http::abort(404, 'Pessoa não encontrada.');
    }

    private function data(string $d): bool
    {
        $dt = \DateTime::createFromFormat('Y-m-d', $d);
        return $dt !== false && $dt->format('Y-m-d') === $d;
    }
}
