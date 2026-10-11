<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Crypto;
use App\Core\Database;
use App\Core\Http;
use App\Core\Session;
use App\Core\Uuid;
use App\Services\ConviteQr;
use App\Services\Portaria;
use App\Services\Settings;
use App\Services\Upload;

final class PortariaController extends Controller
{
    /* ------------------------------------------------------------------
     * Tela operacional
     * ------------------------------------------------------------------ */
    public function index(): void
    {
        $u = Auth::authorize('portaria.operar');
        $locais = Database::all('SELECT id, nome, tipo FROM locais WHERE ativo = 1 ORDER BY nome');
        $localId = $this->localAtual($locais);
        $local = null;
        foreach ($locais as $l) {
            if ($l['id'] === $localId) {
                $local = $l;
            }
        }

        $recentes = $localId ? Database::all(
            'SELECT r.id, r.sentido, r.data_hora, r.destino, p.nome AS pessoa_nome, p.empresa AS pessoa_empresa,
                    v.placa, v.empresa AS veiculo_empresa, us.nome AS porteiro_nome,
                    (SELECT COUNT(*) FROM registro_anexos a WHERE a.registro_acesso_id = r.id) AS fotos
               FROM registros_acesso r
               LEFT JOIN pessoas p ON p.id = r.pessoa_id
               LEFT JOIN veiculos v ON v.id = r.veiculo_id
               LEFT JOIN usuarios us ON us.id = r.porteiro_id
              WHERE r.local_id = ?
              ORDER BY r.data_hora DESC, r.seq DESC LIMIT 15',
            [$localId]
        ) : [];

        $presentes = $localId ? Database::all(
            'SELECT pessoa_id, pessoa_nome, pessoa_categoria, pessoa_empresa, veiculo_placa, entrada_em, local_id
               FROM vw_presenca_dentro WHERE local_id = ? ORDER BY entrada_em DESC LIMIT 300',
            [$localId]
        ) : [];

        // Responsável interno padrão = pessoa vinculada ao usuário logado
        $respPadrao = $u['pessoa_id']
            ? Database::one('SELECT id, nome, empresa, telefone_principal, email_principal FROM pessoas WHERE id = ? AND ativo = 1', [$u['pessoa_id']])
            : null;

        $idem = bin2hex(random_bytes(16));
        Session::set('portaria_idem', $idem);

        $this->view('portaria/index', [
            'titulo'        => 'Portaria',
            'descricao'     => ($local['nome'] ?? 'Nenhum local ativo') . ' — registro operacional',
            'locais'        => $locais,
            'local'         => $local,
            'recentes'      => $recentes,
            'presentes'     => $presentes,
            'respPadrao'    => $respPadrao,
            'exigirDestino' => Settings::bool('exigir_destino'),
            'idem'          => $idem,
            'uploadCfg'     => \App\Core\App::config('uploads'),
            'confirmacao'   => Session::get('portaria_confirmacao'),
        ]);
        Session::forget('portaria_confirmacao');
    }

    public function trocarLocal(): void
    {
        Auth::authorize('portaria.operar');
        $id = Http::str('local_id');
        if (Uuid::valid($id) && Database::value('SELECT 1 FROM locais WHERE id = ? AND ativo = 1', [$id])) {
            Session::set('local_id', $id);
            // Lembra o posto de trabalho neste computador/tablet por 180 dias
            setcookie('sag_local', $id, [
                'expires' => time() + 180 * 86400, 'path' => Http::basePath() === '' ? '/' : Http::basePath() . '/',
                'secure' => Http::isHttps(), 'httponly' => true, 'samesite' => 'Lax',
            ]);
        }
        Http::redirect('/portaria');
    }

    /* ------------------------------------------------------------------
     * Registro de entrada/saída (validação final no servidor)
     * ------------------------------------------------------------------ */
    public function registrar(): void
    {
        $u = Auth::authorize('portaria.operar');
        $ajax = Http::isAjax();

        // Idempotência: pelo navegador, registro_id (UUID gerado na tela; também usado na fila offline);
        // sem JavaScript, token de uso único guardado na sessão.
        if (!$ajax) {
            $idem = Http::str('_idem');
            if ($idem === '' || !hash_equals((string) Session::get('portaria_idem', ''), $idem)) {
                Session::flash('aviso', 'Este formulário já foi enviado. Confira as movimentações recentes antes de registrar novamente.');
                Http::redirect('/portaria');
            }
            Session::forget('portaria_idem');
        }

        $fotos = Upload::files('fotos');
        if ($erros = Upload::validar($fotos)) {
            $this->responder($ajax, ['ok' => false, 'erro' => implode(' ', $erros)]);
        }
        $sincronizacao = $ajax && Http::bool('sincronizacao');
        $r = Portaria::registrar([
            'local_id'               => Http::str('local_id'),
            'sentido'                => Http::str('sentido'),
            'pessoa_id'              => Http::str('pessoa_id'),
            'veiculo_id'             => Http::str('veiculo_id'),
            'solicitacao_id'         => Http::str('solicitacao_id'),
            'destino'                => Http::str('destino'),
            'responsavel_interno_id' => Http::str('responsavel_interno_id'),
            'responsavel_interno'    => Http::str('responsavel_interno'),
            'observacoes'            => Http::str('observacoes'),
            'ciente_alertas'         => Http::bool('ciente_alertas'),
            'registro_id'            => $ajax ? Http::str('registro_id') : '',
            'offline_em'             => $sincronizacao ? Http::str('offline_em') : '',
        ], $u, $fotos, (array) ($_POST['fotos_tipo'] ?? []));

        if ($r['ok'] && empty($r['duplicado']) && !$sincronizacao) {
            Session::set('portaria_confirmacao', [
                'titulo'    => Http::str('sentido') === 'entrada' ? 'Entrada registrada' : 'Saída registrada',
                'descricao' => $r['pessoa_nome'] . ($r['placa'] ? ' · ' . $r['placa'] : '') . ' — ' . $r['local_nome'],
                'avisos'    => $r['avisos'],
                'fotos'     => count($fotos),
            ]);
            Session::flash('sucesso', 'Movimentação registrada.');
        }
        $this->responder($ajax, $r);
    }

    /** Resposta do registro: JSON para a tela (fetch) ou redirecionamento clássico. */
    private function responder(bool $ajax, array $r): never
    {
        if ($ajax) {
            unset($r['pessoa_nome'], $r['local_nome']);
            Http::json($r, $r['ok'] ? 200 : 422);
        }
        if (!$r['ok']) {
            $this->falha($r['erro'], '/portaria');
        }
        Http::redirect('/portaria');
    }

    /** Saída rápida / em lote: itens[] = "pessoa_id|local_id". */
    public function saidaRapida(): void
    {
        $u = Auth::authorize('portaria.operar');
        $voltar = Http::str('voltar') === '/presenca' ? '/presenca' : '/portaria';
        $itens = [];
        foreach ((array) ($_POST['itens'] ?? []) as $v) {
            [$p, $l] = array_pad(explode('|', (string) $v, 2), 2, '');
            if (Uuid::valid($p) && Uuid::valid($l)) {
                $itens[$p . $l] = ['pessoa_id' => strtolower($p), 'local_id' => strtolower($l)];
            }
        }
        if (!$itens) {
            $this->falha('Selecione ao menos uma pessoa para registrar a saída.', $voltar);
        }
        if (count($itens) > 200) {
            $this->falha('Máximo de 200 saídas por vez.', $voltar);
        }
        $r = Portaria::saidaRapida(array_values($itens), $u);
        if ($r['ok']) {
            Session::flash('sucesso', $r['ok'] === 1 ? '1 saída registrada.' : $r['ok'] . ' saídas registradas.');
        }
        if ($r['falhas']) {
            Session::flash('aviso', 'Não registradas: ' . implode(' ', $r['falhas']));
        }
        Http::redirect($voltar);
    }

    /** Leitura do QR do convite (leitor USB ou câmera). */
    public function lerQr(): void
    {
        Auth::authorize('portaria.operar');
        $solId = ConviteQr::validar(Http::str('t'));
        if ($solId === null) {
            Audit::log('portaria', null, 'QR_INVALIDO', ['inicio' => mb_substr(Http::str('t'), 0, 12)]);
            Http::json(['erro' => 'QR inválido ou adulterado. Busque a pessoa pelo nome ou CPF.'], 422);
        }
        $s = ConviteQr::solicitacao($solId);
        if (!$s) {
            Http::json(['erro' => 'Solicitação do convite não encontrada.'], 404);
        }
        $erro = null;
        if ($s['status'] !== 'aprovada') {
            $erro = 'Convite de ' . $s['visitante_nome'] . ': solicitação "' . (STATUS_SOLICITACAO[$s['status']] ?? $s['status']) . '", não aprovada.';
        } elseif ($s['data_prevista'] !== date('Y-m-d')) {
            $erro = 'Convite de ' . $s['visitante_nome'] . ' é para ' . fmt_data($s['data_prevista']) . ', não para hoje.';
        }
        $pessoaId = ConviteQr::pessoaDaSolicitacao($s);
        $cpf = Crypto::cpfDigits($s['visitante_doc']);
        Audit::log('solicitacoes_acesso', $s['id'], 'QR_LIDO', ['pessoa_id' => $pessoaId, 'valido_hoje' => $erro === null]);
        Http::json([
            'erro'          => $erro,
            'solicitacao'   => ['id' => $s['id'], 'visitante_nome' => $s['visitante_nome'], 'empresa' => $s['empresa'], 'area' => $s['area_nome'], 'local' => $s['local_nome']],
            'pessoa_id'     => $pessoaId,
            'cadastro'      => $pessoaId ? null : [
                'nome' => $s['visitante_nome'], 'empresa' => $s['empresa'], 'categoria' => $s['tipo'] === 'terceiro' ? 'terceiro' : 'visitante',
                // CPF completo só para quem pode ver CPF; para o porteiro o servidor usa o da solicitação no cadastro
                'cpf' => strlen($cpf) === 11 && Crypto::cpfValido($cpf) ? (Auth::can('pessoas.ver_cpf') ? Crypto::formatCpf($cpf) : '') : '',
                'cpf_na_solicitacao' => strlen($cpf) === 11 && Crypto::cpfValido($cpf),
            ],
        ], $erro ? 409 : 200);
    }

    /**
     * Pacote para operar sem conexão: pessoas ativas (sem CPF), veículos, solicitações aprovadas de hoje
     * e quem está presente no local. Guardado no navegador do posto (IndexedDB) e apagado no logout.
     */
    public function cacheOffline(): void
    {
        Auth::authorize('portaria.operar');
        $localId = Http::str('local_id');
        if (!Uuid::valid($localId)) {
            Http::json(['erro' => 'Local inválido'], 400);
        }
        $pessoas = Database::all(
            "SELECT p.id, p.nome, p.categoria, p.empresa, p.matricula,
                    EXISTS (SELECT 1 FROM documentos d WHERE d.pessoa_id = p.id
                             AND d.validade < CURDATE()
                             AND NOT EXISTS (SELECT 1 FROM documentos n WHERE n.pessoa_id = p.id AND n.tipo = d.tipo AND n.validade >= CURDATE())) AS doc_vencido
               FROM pessoas p WHERE p.ativo = 1 ORDER BY p.nome LIMIT 20000"
        );
        Http::json([
            'gerado_em'    => date('Y-m-d H:i:s'),
            'local_id'     => $localId,
            'pessoas'      => array_map(fn ($p) => [...$p, 'doc_vencido' => (int) $p['doc_vencido'] === 1,
                                                    'categoria_label' => CATEGORIAS[$p['categoria']] ?? $p['categoria']], $pessoas),
            'veiculos'     => Database::all('SELECT id, placa, tipo, marca_modelo, cor, empresa, tag FROM veiculos WHERE ativo = 1 ORDER BY placa LIMIT 20000'),
            'solicitacoes' => Database::all(
                "SELECT id, visitante_nome, empresa, pessoa_id, atividade_risco FROM solicitacoes_acesso
                  WHERE status = 'aprovada' AND data_prevista = CURDATE()"
            ),
            'presentes'    => array_column(Database::all('SELECT pessoa_id FROM vw_presenca_dentro WHERE local_id = ?', [$localId]), 'pessoa_id'),
        ]);
    }

    /* ------------------------------------------------------------------
     * API JSON usada pela tela da portaria
     * ------------------------------------------------------------------ */
    public function analise(): void
    {
        Auth::authorize('portaria.operar');
        $pessoaId = Http::str('pessoa_id');
        $localId = Http::str('local_id');
        $sentido = Http::str('sentido') === 'saida' ? 'saida' : 'entrada';
        $veiculoId = Uuid::valid(Http::str('veiculo_id')) ? Http::str('veiculo_id') : null;
        $solId = Uuid::valid(Http::str('solicitacao_id')) ? Http::str('solicitacao_id') : null;
        if (!Uuid::valid($pessoaId) || !Uuid::valid($localId)) {
            Http::json(['erro' => 'Parâmetros inválidos'], 400);
        }
        $a = Portaria::analisar($pessoaId, $localId, $sentido, $veiculoId, $solId);
        if (isset($a['erro'])) {
            Http::json($a, 404);
        }
        // Responsável padrão do cadastro → tenta casar com pessoa cadastrada
        $respPadrao = null;
        if (!empty($a['pessoa']['responsavel_interno_padrao'])) {
            $respPadrao = Database::one(
                'SELECT id, nome, empresa, telefone_principal, email_principal FROM pessoas WHERE ativo = 1 AND nome = ? LIMIT 1',
                [$a['pessoa']['responsavel_interno_padrao']]
            );
        }
        $veiculo = $veiculoId ? Database::one('SELECT id, placa, marca_modelo, cor, empresa FROM veiculos WHERE id = ?', [$veiculoId]) : null;
        $a['documentos'] = array_map(fn ($d) => [...$d, 'rotulo' => TIPOS_DOC[$d['tipo']] ?? $d['tipo'], 'validade_fmt' => fmt_data($d['validade'])], $a['documentos']);
        $a['presente_desde'] = $a['presente'] ? fmt_data_hora($a['presente']['data_hora']) : null;
        foreach (['vinculadas', 'candidatas', 'outras'] as $k) {
            $a['solicitacoes'][$k] = array_map(fn ($s) => [
                'id' => $s['id'], 'visitante_nome' => $s['visitante_nome'], 'empresa' => $s['empresa'],
                'status' => $s['status'], 'status_label' => STATUS_SOLICITACAO[$s['status']] ?? $s['status'],
                'area' => $s['area_nome'], 'responsavel' => $s['responsavel_nome'],
                'risco' => (int) $s['atividade_risco'] === 1, 'motivo' => $s['motivo'],
            ], $a['solicitacoes'][$k]);
        }
        $a['pessoa']['categoria_label'] = CATEGORIAS[$a['pessoa']['categoria']] ?? $a['pessoa']['categoria'];
        $a['responsavel_padrao'] = $respPadrao;
        $a['veiculo'] = $veiculo;
        Http::json($a);
    }

    public function buscarPessoas(): void
    {
        Auth::authorize('pessoas.ver');
        $q = trim((string) Http::input('q', ''));
        if (mb_strlen($q) < 2) {
            Http::json([]);
        }
        $digitos = Crypto::cpfDigits($q);
        if (strlen($digitos) === 11 && preg_match('/^[\d.\-\s]+$/', $q)) {
            $rows = Database::all(
                'SELECT p.id, p.nome, p.cpf_enc, p.matricula, p.categoria, p.empresa, p.ativo,
                        (SELECT u.id FROM usuarios u WHERE u.pessoa_id = p.id) AS usuario_id
                   FROM pessoas p WHERE p.cpf_hash = ? AND p.ativo = 1',
                [Crypto::cpfHash($digitos)]
            );
        } else {
            $like = '%' . $this->escapeLike($q) . '%';
            $rows = Database::all(
                'SELECT p.id, p.nome, p.cpf_enc, p.matricula, p.categoria, p.empresa, p.ativo,
                        (SELECT u.id FROM usuarios u WHERE u.pessoa_id = p.id) AS usuario_id
                   FROM pessoas p
                  WHERE p.ativo = 1 AND (p.nome LIKE ? OR p.matricula LIKE ? OR p.empresa LIKE ?)
                  ORDER BY p.nome LIMIT 20',
                [$like, $like, $like]
            );
        }
        $podeVer = Auth::can('pessoas.ver_cpf');
        Http::json(array_map(function ($p) use ($podeVer) {
            $cpf = Crypto::decryptCpf($p['cpf_enc']);
            return [
                'id' => $p['id'], 'nome' => $p['nome'], 'matricula' => $p['matricula'],
                'categoria' => $p['categoria'], 'categoria_label' => CATEGORIAS[$p['categoria']] ?? $p['categoria'],
                'empresa' => $p['empresa'], 'usuario' => $p['usuario_id'] !== null,
                'cpf_exibicao' => $podeVer ? Crypto::formatCpf($cpf) : Crypto::maskCpf($cpf),
            ];
        }, $rows));
    }

    public function buscarVeiculos(): void
    {
        Auth::authorize('veiculos.ver');
        $q = trim((string) Http::input('q', ''));
        if (mb_strlen($q) < 2) {
            Http::json([]);
        }
        $like = '%' . $this->escapeLike($q) . '%';
        $placa = '%' . $this->escapeLike(preg_replace('/[^A-Za-z0-9]/', '', $q) ?? '') . '%';
        Http::json(Database::all(
            'SELECT id, placa, tipo, marca_modelo, cor, empresa, tag FROM veiculos
              WHERE ativo = 1 AND (placa LIKE ? OR marca_modelo LIKE ? OR empresa LIKE ? OR tag LIKE ?)
              ORDER BY placa LIMIT 10',
            [$placa, $like, $like, $like]
        ));
    }

    public function buscarResponsaveis(): void
    {
        Auth::authorize('pessoas.ver');
        $q = trim((string) Http::input('q', ''));
        if (mb_strlen($q) < 2) {
            Http::json([]);
        }
        $like = '%' . $this->escapeLike($q) . '%';
        Http::json(Database::all(
            'SELECT p.id, p.nome, p.empresa, p.matricula, p.telefone_principal, p.email_principal,
                    (SELECT u.id FROM usuarios u WHERE u.pessoa_id = p.id) IS NOT NULL AS usuario
               FROM pessoas p
              WHERE p.ativo = 1 AND (p.nome LIKE ? OR p.matricula LIKE ? OR p.empresa LIKE ? OR p.email_principal LIKE ? OR p.telefone_principal LIKE ?)
              ORDER BY p.nome LIMIT 10',
            [$like, $like, $like, $like, $like]
        ));
    }

    public function cadastroRapidoPessoa(): void
    {
        Auth::authorize('pessoas.criar');
        $nome = Http::nstr('nome', 200);
        $cpf = Http::str('cpf');
        // Cadastro a partir do QR: usa o CPF informado na solicitação sem expô-lo ao navegador do porteiro
        $solId = Http::str('solicitacao_id');
        if ($cpf === '' && Uuid::valid($solId)) {
            $doc = Crypto::cpfDigits((string) Database::value("SELECT visitante_doc FROM solicitacoes_acesso WHERE id = ? AND status = 'aprovada'", [$solId]));
            if (strlen($doc) === 11 && Crypto::cpfValido($doc)) {
                $cpf = $doc;
            }
        }
        $categoria = Http::str('categoria', 'visitante');
        if ($nome === null) {
            Http::json(['erro' => 'Nome obrigatório'], 422);
        }
        if (!array_key_exists($categoria, CATEGORIAS)) {
            Http::json(['erro' => 'Categoria inválida'], 422);
        }
        if ($cpf !== '') {
            if (!Crypto::cpfValido($cpf)) {
                Http::json(['erro' => 'CPF inválido'], 422);
            }
            $existente = Database::one('SELECT id, nome FROM pessoas WHERE cpf_hash = ?', [Crypto::cpfHash($cpf)]);
            if ($existente) {
                Http::json(['erro' => 'CPF já cadastrado para ' . $existente['nome'] . '. Use a busca.', 'id' => $existente['id']], 409);
            }
        }
        $id = Uuid::v4();
        Database::insert('pessoas', [
            'id'        => $id,
            'nome'      => $nome,
            'cpf_enc'   => Crypto::encryptCpf($cpf),
            'cpf_hash'  => Crypto::cpfHash($cpf),
            'categoria' => $categoria,
            'empresa'   => Http::nstr('empresa', 150),
            'telefone'  => Http::nstr('telefone', 30),
            'telefone_principal' => Http::nstr('telefone', 30),
            'ativo'     => 1,
        ]);
        Audit::log('pessoas', $id, 'INSERT', ['origem' => 'portaria', 'nome' => $nome, 'categoria' => $categoria]);
        Http::json(['id' => $id, 'nome' => $nome], 201);
    }

    public function cadastroRapidoVeiculo(): void
    {
        Auth::authorize('veiculos.criar');
        $placa = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', Http::str('placa')) ?? '');
        if (!preg_match('/^[A-Z0-9]{5,10}$/', $placa)) {
            Http::json(['erro' => 'Placa inválida'], 422);
        }
        $existe = Database::one('SELECT id FROM veiculos WHERE placa = ?', [$placa]);
        if ($existe) {
            Http::json(['erro' => 'Placa já cadastrada. Use a busca.', 'id' => $existe['id']], 409);
        }
        $tipo = Http::str('tipo', 'carro');
        $id = Uuid::v4();
        Database::insert('veiculos', [
            'id'           => $id,
            'placa'        => $placa,
            'tipo'         => array_key_exists($tipo, TIPOS_VEICULO) ? $tipo : 'carro',
            'marca_modelo' => Http::nstr('marca_modelo', 120),
            'cor'          => Http::nstr('cor', 40),
            'empresa'      => Http::nstr('empresa', 150),
        ]);
        Audit::log('veiculos', $id, 'INSERT', ['origem' => 'portaria', 'placa' => $placa]);
        Http::json(['id' => $id, 'placa' => $placa], 201);
    }

    /* ------------------------------------------------------------------
     * Detalhe do registro e fotos
     * ------------------------------------------------------------------ */
    public function detalhe(string $id): void
    {
        Auth::authorize('registros.ver');
        $this->uuidOu404($id);
        $r = Database::one(
            'SELECT r.*, p.nome AS pessoa_nome, p.categoria, p.empresa AS pessoa_empresa, v.placa, v.marca_modelo,
                    l.nome AS local_nome, u.nome AS porteiro_nome, s.visitante_nome AS sol_nome, s.status AS sol_status
               FROM registros_acesso r
               LEFT JOIN pessoas p ON p.id = r.pessoa_id
               LEFT JOIN veiculos v ON v.id = r.veiculo_id
               JOIN locais l ON l.id = r.local_id
               LEFT JOIN usuarios u ON u.id = r.porteiro_id
               LEFT JOIN solicitacoes_acesso s ON s.id = r.solicitacao_id
              WHERE r.id = ?',
            [$id]
        ) ?? Http::abort(404, 'Registro não encontrado.');
        $anexos = Database::all('SELECT id, tipo_foto, nome_arquivo, tamanho_bytes, created_at FROM registro_anexos WHERE registro_acesso_id = ?', [$id]);
        $this->view('portaria/detalhe', ['titulo' => 'Registro de acesso', 'r' => $r, 'anexos' => $anexos]);
    }

    public function anexo(string $id): void
    {
        Auth::authorize('registros.ver');
        $this->uuidOu404($id);
        $a = Database::one('SELECT storage_path, mime_type FROM registro_anexos WHERE id = ?', [$id]);
        $path = $a ? Upload::caminho($a['storage_path']) : null;
        if (!$path) {
            Http::abort(404, 'Arquivo não encontrado.');
        }
        header('Content-Type: ' . $a['mime_type']);
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: private, max-age=3600');
        header('Content-Disposition: inline; filename="' . basename($path) . '"');
        readfile($path);
        exit;
    }

    /* ------------------------------------------------------------------ */
    private function localAtual(array $locais): ?string
    {
        $id = Session::get('local_id') ?? ($_COOKIE['sag_local'] ?? null);
        foreach ($locais as $l) {
            if ($l['id'] === $id) {
                Session::set('local_id', $id);
                return $id;
            }
        }
        $primeiro = $locais[0]['id'] ?? null;
        if ($primeiro) {
            Session::set('local_id', $primeiro);
        }
        return $primeiro;
    }

    private function escapeLike(string $s): string
    {
        return addcslashes($s, '%_\\');
    }
}
