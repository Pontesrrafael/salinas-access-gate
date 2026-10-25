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
use App\Services\Portaria;
use App\Services\Settings;
use App\Services\Upload;
use DomainException;
use Throwable;

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

        // Idempotência: evita registro duplicado por duplo clique / reenvio
        $idem = Http::str('_idem');
        if ($idem === '' || !hash_equals((string) Session::get('portaria_idem', ''), $idem)) {
            Session::flash('aviso', 'Este formulário já foi enviado. Confira as movimentações recentes antes de registrar novamente.');
            Http::redirect('/portaria');
        }
        Session::forget('portaria_idem');

        $localId = Http::str('local_id');
        $sentido = Http::str('sentido');
        $pessoaId = Http::str('pessoa_id');
        $veiculoId = Http::str('veiculo_id') ?: null;
        $solicitacaoId = Http::str('solicitacao_id') ?: null;
        // A confirmação deve vir explicitamente no POST, nunca de parâmetros da URL.
        $confirmarSolicitacaoManual = ($_POST['confirmar_solicitacao_manual'] ?? null) === '1';

        $local = Uuid::valid($localId) ? Database::one('SELECT * FROM locais WHERE id = ? AND ativo = 1', [$localId]) : null;
        if (!$local) {
            $this->falha('Selecione um local válido.', '/portaria');
        }
        if (!in_array($sentido, ['entrada', 'saida'], true)) {
            $this->falha('Movimentação inválida.', '/portaria');
        }
        if (!Uuid::valid($pessoaId)) {
            $this->falha('Selecione uma pessoa antes de registrar.', '/portaria');
        }
        if ($veiculoId !== null && !Uuid::valid($veiculoId)) {
            $this->falha('Veículo inválido.', '/portaria');
        }
        if ($solicitacaoId !== null && !Uuid::valid($solicitacaoId)) {
            $solicitacaoId = null;
        }

        $destino = Http::nstr('destino', 150);
        if ($sentido === 'entrada' && Settings::bool('exigir_destino') && $destino === null) {
            $this->falha('Informe o destino/setor da entrada.', '/portaria');
        }

        $analise = Portaria::analisar($pessoaId, $localId, $sentido, $veiculoId, $solicitacaoId);
        if (isset($analise['erro'])) {
            $this->falha($analise['erro'], '/portaria');
        }
        if ($analise['bloqueios']) {
            Audit::log('portaria', $pessoaId, 'TENTATIVA_BLOQUEADA', [
                'sentido' => $sentido, 'local_id' => $localId, 'motivos' => $analise['bloqueios'],
            ]);
            $this->falha(($sentido === 'entrada' ? 'Entrada bloqueada: ' : 'Saída não permitida: ') . implode(' ', $analise['bloqueios']), '/portaria');
        }
        if ($analise['solicitacao_manual'] && !$confirmarSolicitacaoManual) {
            $this->falha('A solicitação selecionada não confere com a identificação da pessoa. Confirme explicitamente a seleção manual para registrar o acesso.', '/portaria');
        }
        if ($sentido === 'entrada' && $analise['exige_solicitacao']) {
            $solicitacaoId = $solicitacaoId ?: $analise['solicitacao_sugerida'];
        } elseif ($sentido === 'saida') {
            $solicitacaoId = null;
        }

        // Responsável interno: dados de contato vêm do cadastro (não do navegador)
        $respId = Http::str('responsavel_interno_id');
        $respNome = Http::nstr('responsavel_interno', 150);
        $resp = Uuid::valid($respId)
            ? Database::one('SELECT id, nome, telefone_principal, email_principal FROM pessoas WHERE id = ?', [$respId])
            : null;

        $fotos = Upload::files('fotos');
        $tiposFoto = (array) ($_POST['fotos_tipo'] ?? []);
        if ($erros = Upload::validar($fotos)) {
            $this->falha(implode(' ', $erros), '/portaria');
        }

        $registroId = Uuid::v4();
        $gravados = [];
        try {
            Database::transaction(function () use ($registroId, $pessoaId, $veiculoId, $localId, $solicitacaoId, $confirmarSolicitacaoManual, $sentido, $destino, $resp, $respNome, $u, $fotos, $tiposFoto, &$gravados, &$analise): void {
                // Bloqueia os cadastros mesmo sem histórico; todos os registros seguem esta ordem.
                if (!Database::one('SELECT id FROM pessoas WHERE id = ? FOR UPDATE', [$pessoaId])) {
                    throw new DomainException('Pessoa não encontrada. Atualize a tela antes de registrar novamente.');
                }
                if ($veiculoId !== null && !Database::one('SELECT id FROM veiculos WHERE id = ? FOR UPDATE', [$veiculoId])) {
                    throw new DomainException('Veículo não encontrado. Atualize a tela antes de registrar novamente.');
                }
                if ($solicitacaoId !== null) {
                    Database::one('SELECT id FROM solicitacoes_acesso WHERE id = ? FOR UPDATE', [$solicitacaoId]);
                }

                // Leituras com bloqueio veem o último estado confirmado, inclusive após aguardar outro porteiro.
                $ultimaPessoa = Database::one(
                    'SELECT sentido FROM registros_acesso WHERE pessoa_id = ? AND local_id = ?
                      ORDER BY data_hora DESC, seq DESC LIMIT 1 FOR UPDATE',
                    [$pessoaId, $localId]
                );
                $pessoaPresente = ($ultimaPessoa['sentido'] ?? null) === 'entrada';
                if ($sentido === 'entrada' && $pessoaPresente) {
                    throw new DomainException('Entrada bloqueada: a pessoa já está registrada como presente neste local. Atualize a tela.');
                }
                if ($sentido === 'saida' && !$pessoaPresente) {
                    throw new DomainException('Saída não permitida: a pessoa não está registrada como presente neste local. Atualize a tela.');
                }
                if ($veiculoId !== null) {
                    $ultimoVeiculo = Database::one(
                        'SELECT sentido FROM registros_acesso WHERE veiculo_id = ? AND local_id = ?
                          ORDER BY data_hora DESC, seq DESC LIMIT 1 FOR UPDATE',
                        [$veiculoId, $localId]
                    );
                    $veiculoPresente = ($ultimoVeiculo['sentido'] ?? null) === 'entrada';
                    if ($sentido === 'entrada' && $veiculoPresente) {
                        throw new DomainException('Entrada bloqueada: o veículo já está registrado como presente neste local. Atualize a tela.');
                    }
                    if ($sentido === 'saida' && !$veiculoPresente) {
                        throw new DomainException('Saída não permitida: o veículo não está registrado como presente neste local. Atualize a tela.');
                    }
                }

                // Revalida também a solicitação após o bloqueio, pois ela pode ter sido vinculada por outro registro.
                $analise = Portaria::analisar($pessoaId, $localId, $sentido, $veiculoId, $solicitacaoId);
                if (isset($analise['erro']) || $analise['bloqueios']) {
                    throw new DomainException($analise['erro'] ?? implode(' ', $analise['bloqueios']));
                }
                if ($analise['solicitacao_manual'] && !$confirmarSolicitacaoManual) {
                    throw new DomainException('Confirme explicitamente a seleção manual da solicitação antes de registrar o acesso.');
                }
                Database::insert('registros_acesso', [
                    'id'                           => $registroId,
                    'pessoa_id'                    => $pessoaId,
                    'veiculo_id'                   => $veiculoId,
                    'local_id'                     => $localId,
                    'solicitacao_id'               => $solicitacaoId,
                    'sentido'                      => $sentido,
                    'destino'                      => $destino,
                    'responsavel_interno'          => $resp['nome'] ?? $respNome,
                    'responsavel_interno_id'       => $resp['id'] ?? null,
                    'responsavel_interno_nome'     => $resp['nome'] ?? $respNome,
                    'responsavel_interno_telefone' => $resp['telefone_principal'] ?? null,
                    'responsavel_interno_email'    => $resp['email_principal'] ?? null,
                    'porteiro_id'                  => $u['id'],
                    'observacoes'                  => Http::nstr('observacoes', 2000),
                ]);
                if ($solicitacaoId !== null) {
                    // Vincula a solicitação aprovada do dia à pessoa (somente se ainda livre)
                    Database::run(
                        "UPDATE solicitacoes_acesso SET pessoa_id = ?
                          WHERE id = ? AND pessoa_id IS NULL AND status = 'aprovada' AND data_prevista = CURDATE()",
                        [$pessoaId, $solicitacaoId]
                    );
                }
                if ($analise['solicitacao_manual']) {
                    Audit::log('solicitacoes_acesso', $solicitacaoId, 'SOLICITACAO_MANUAL', [
                        'pessoa_id' => $pessoaId, 'local_id' => $localId, 'registro_acesso_id' => $registroId,
                        'confirmar_solicitacao_manual' => 1,
                    ], $u['id']);
                }
                foreach ($fotos as $i => $f) {
                    $meta = Upload::salvar($f);
                    $gravados[] = $meta['storage_path'];
                    $tipo = (string) ($tiposFoto[$i] ?? 'outra');
                    Database::insert('registro_anexos', [
                        'id'                 => Uuid::v4(),
                        'registro_acesso_id' => $registroId,
                        'storage_path'       => $meta['storage_path'],
                        'tipo_foto'          => array_key_exists($tipo, TIPOS_FOTO) ? $tipo : 'outra',
                        'nome_arquivo'       => $meta['nome_arquivo'],
                        'mime_type'          => $meta['mime_type'],
                        'tamanho_bytes'      => $meta['tamanho_bytes'],
                        'sha256'             => $meta['sha256'],
                        'criado_por'         => $u['id'],
                    ]);
                }
            });
        } catch (Throwable $e) {
            foreach ($gravados as $rel) {
                @unlink(Upload::dir() . '/' . $rel);
            }
            if ($e instanceof DomainException) {
                Audit::log('portaria', $pessoaId, 'TENTATIVA_BLOQUEADA', [
                    'sentido' => $sentido, 'local_id' => $localId, 'motivos' => [$e->getMessage()],
                ]);
                $this->falha($e->getMessage(), '/portaria');
            }
            throw $e;
        }

        $veic = $veiculoId ? Database::value('SELECT placa FROM veiculos WHERE id = ?', [$veiculoId]) : null;
        Session::set('portaria_confirmacao', [
            'titulo'    => $sentido === 'entrada' ? 'Entrada registrada' : 'Saída registrada',
            'descricao' => $analise['pessoa']['nome'] . ($veic ? ' · ' . $veic : '') . ' — ' . $local['nome'],
            'avisos'    => $analise['avisos'],
            'fotos'     => count($fotos),
        ]);
        Session::flash('sucesso', 'Movimentação registrada.');
        Http::redirect('/portaria');
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
