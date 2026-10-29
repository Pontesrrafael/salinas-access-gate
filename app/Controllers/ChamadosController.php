<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\{Audit, Auth, Database, Http, Session, Uuid};
use App\Services\{Chamados, FormularioDinamico, Notificacoes, Settings, Upload};
use DateTimeImmutable;
use InvalidArgumentException;
use Throwable;

/** Solicitações de serviços. Toda leitura/mutação é limitada ao solicitante ou à equipe da área. */
final class ChamadosController extends Controller
{
    private function habilitado(string $permissao = 'chamados.abrir'): array
    {
        $u = Auth::authorize($permissao);
        if (!Settings::bool('chamados_ativo')) { Http::abort(404, t('Serviços indisponíveis.')); }
        return $u;
    }

    private function atende(string $areaId, array $u): bool
    {
        return $u['papel'] === 'admin' || (bool) Database::value(
            'SELECT 1 FROM servico_area_membros WHERE area_id = ? AND usuario_id = ?', [$areaId, $u['id']]
        );
    }

    private function chamado(string $id, array $u, bool $lock = false): array
    {
        $c = Database::one('SELECT * FROM chamados WHERE id = ?' . ($lock ? ' FOR UPDATE' : ''), [$this->uuidOu404($id)]);
        if (!$c || ($c['solicitante_id'] !== $u['id'] && !$this->atende($c['area_id'], $u))) {
            Http::abort(404, t('Chamado não encontrado.'));
        }
        return $c;
    }

    private function categoria(string $areaId, string $categoriaId): ?array
    {
        if (!Uuid::valid($areaId) || !Uuid::valid($categoriaId)) { return null; }
        return Database::one('SELECT c.* FROM servico_categorias c JOIN servico_areas a ON a.id = c.area_id WHERE c.id = ? AND c.area_id = ? AND c.ativo = 1 AND a.ativo = 1 AND EXISTS (SELECT 1 FROM servico_area_membros m JOIN usuarios u ON u.id = m.usuario_id AND u.ativo = 1 WHERE m.area_id = a.id)', [$categoriaId, $areaId]);
    }

    public function abrir(): void
    {
        $this->habilitado();
        $old = Session::pullOld();
        $areaId = Http::str('area_id', (string) ($old['area_id'] ?? ''));
        $categoriaId = Http::str('categoria_id', (string) ($old['categoria_id'] ?? ''));
        $areas = Database::all('SELECT a.id, a.nome, a.descricao FROM servico_areas a WHERE a.ativo = 1 AND EXISTS (SELECT 1 FROM servico_area_membros m JOIN usuarios u ON u.id = m.usuario_id AND u.ativo = 1 WHERE m.area_id = a.id) AND EXISTS (SELECT 1 FROM servico_categorias c WHERE c.area_id = a.id AND c.ativo = 1) ORDER BY a.ordem, a.nome');
        if (!in_array($areaId, array_column($areas, 'id'), true)) { $areaId = ''; }
        $categorias = $areaId ? Chamados::categoriasEmArvore(Database::all('SELECT * FROM servico_categorias WHERE area_id = ? AND ativo = 1 ORDER BY ordem, nome', [$areaId])) : [];
        $categoria = $this->categoria($areaId, $categoriaId);
        if (!$categoria) { $categoriaId = ''; }
        $area = $categoria ? Database::one('SELECT * FROM servico_areas WHERE id = ?', [$areaId]) : [];
        $sla = $categoria ? Chamados::slaEfetivo($categoria, $area ?? []) : null;
        $textoSla = $categoria ? Chamados::textoSla($categoria, $area ?? []) : '';
        $campos = $categoria ? FormularioDinamico::campos($areaId, $categoriaId) : [];
        $this->view('chamados/abrir', compact('areas', 'areaId', 'categorias', 'categoriaId', 'categoria', 'campos', 'old', 'textoSla', 'sla') + ['titulo' => t('Abrir chamado')]);
    }

    public function criar(): void
    {
        $u = $this->habilitado();
        $areaId = Http::str('area_id');
        $categoriaId = Http::str('categoria_id');
        $categoria = $this->categoria($areaId, $categoriaId);
        $voltar = '/chamados/abrir';
        if (!$categoria) { $this->falha(t('Selecione uma área e uma categoria ativas.'), $voltar); }
        $titulo = Http::str('titulo');
        $descricao = Http::str('descricao');
        $beneficiario = Http::str('beneficiario_nome');
        $paraVoce = Http::str('para_voce', '1');
        $urgencia = filter_var(Http::input('urgencia'), FILTER_VALIDATE_INT);
        $impacto = filter_var(Http::input('impacto'), FILTER_VALIDATE_INT);
        if ($titulo === '' || mb_strlen($titulo) > 200 || mb_strlen($descricao) > 20000
            || !isset(Chamados::URGENCIAS[$urgencia], Chamados::IMPACTOS[$impacto])
            || !in_array($paraVoce, ['0', '1'], true) || ($paraVoce === '0' && ($beneficiario === '' || mb_strlen($beneficiario) > 150))) {
            $this->falha(t('Confira título, descrição, urgência, impacto e beneficiário.'), $voltar);
        }
        $campos = FormularioDinamico::campos($areaId, $categoriaId);
        $validacao = FormularioDinamico::validar($campos, $_POST, $_FILES);
        $files = Upload::files('anexos');
        $todosArquivos = $files;
        foreach ($validacao['arquivos'] as $uploads) { array_push($todosArquivos, ...$uploads); }
        $erros = [...$validacao['erros'], ...Upload::validar($todosArquivos, true)];
        if ($erros) { $this->falha(implode(' ', $erros), $voltar); }
        $id = Uuid::v4();
        $salvos = [];
        try {
            Database::transaction(function () use ($u, $categoria, $categoriaId, $areaId, $titulo, $descricao, $paraVoce, $beneficiario, $urgencia, $impacto, $files, $validacao, $campos, $id, &$salvos): void {
                // Bloqueia o cadastro enquanto registra a demanda (não aceita uma categoria desativada entre GET e POST).
                $ativa = Database::one('SELECT c.* FROM servico_categorias c JOIN servico_areas a ON a.id = c.area_id WHERE c.id = ? AND c.area_id = ? AND c.ativo = 1 AND a.ativo = 1 AND EXISTS (SELECT 1 FROM servico_area_membros m JOIN usuarios u ON u.id = m.usuario_id AND u.ativo = 1 WHERE m.area_id = a.id) FOR UPDATE', [$categoriaId, $areaId]);
                if (!$ativa) { throw new InvalidArgumentException(t('Selecione uma área e uma categoria ativas.')); }
                $categoria = $ativa;
                $area = Database::one('SELECT * FROM servico_areas WHERE id = ? FOR UPDATE', [$areaId]);
                $sla = Chamados::slaEfetivo($categoria, $area);
                $calendario = Chamados::calendarioConfigurado($categoria, $area);
                $agora = new DateTimeImmutable();
                Database::insert('chamados', [
                    'id' => $id, 'area_id' => $areaId, 'categoria_id' => $categoriaId,
                    'titulo' => $titulo, 'descricao' => $descricao ?: null, 'solicitante_id' => $u['id'],
                    'beneficiario_nome' => $paraVoce === '0' ? $beneficiario : null,
                    'recorrente' => Http::bool('recorrente') ? 1 : 0, 'urgencia' => $urgencia, 'impacto' => $impacto,
                    'prioridade' => Chamados::prioridade($urgencia, $impacto),
                    'calendario_sla' => $calendario === null ? null : json_encode($calendario, JSON_THROW_ON_ERROR),
                    'prazo_resposta' => Chamados::prazo($agora, $sla['resposta_min'], $calendario)?->format('Y-m-d H:i:s'),
                    'prazo_solucao' => Chamados::prazo($agora, $sla['solucao_min'], $calendario)?->format('Y-m-d H:i:s'),
                    'created_at' => $agora->format('Y-m-d H:i:s'),
                ]);
                foreach ($campos as $campo) {
                    $valor = $validacao['valores'][$campo['id']] ?? null;
                    Database::insert('chamado_respostas', [
                        'chamado_id' => $id, 'campo_id' => $campo['id'], 'rotulo' => $campo['rotulo'],
                        'valor' => is_array($valor) ? json_encode($valor, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) : $valor,
                    ]);
                }
                $this->salvarAnexos($files, $id, null, $u['id'], $salvos);
                foreach ($validacao['arquivos'] as $campoId => $uploads) {
                    $this->salvarAnexos($uploads, $id, null, $u['id'], $salvos, $campoId);
                }
                Audit::log('chamados', $id, 'criar', ['area_id' => $areaId, 'categoria_id' => $categoriaId]);
                Notificacoes::chamado('criado', Database::one('SELECT * FROM chamados WHERE id = ?', [$id]), ['usuario_id' => $u['id']]);
            });
        } catch (Throwable $e) {
            $this->limparAnexos($salvos);
            if ($e instanceof InvalidArgumentException) { $this->falha($e->getMessage(), $voltar); }
            throw $e;
        }
        $this->ok(t('Chamado aberto.'), '/chamados/' . $id);
    }

    public function meus(): void { $this->listar(false); }
    public function fila(): void { $this->listar(true); }

    private function listar(bool $fila): void
    {
        $u = $this->habilitado($fila ? 'chamados.atender' : 'chamados.abrir');
        $where = []; $params = [];
        if (!$fila) { $where[] = 'c.solicitante_id = ?'; $params[] = $u['id']; }
        elseif ($u['papel'] !== 'admin') {
            $where[] = 'EXISTS (SELECT 1 FROM servico_area_membros m WHERE m.area_id = c.area_id AND m.usuario_id = ?)';
            $params[] = $u['id'];
        }
        $filtros = [];
        foreach (['area_id', 'status', 'categoria_id', 'prioridade', 'atendente_id', 'prazo', 'q', 'ordem', 'visao'] as $key) { $filtros[$key] = Http::str($key); }
        $filtros['q'] = mb_substr($filtros['q'], 0, 200);
        if ($fila && $filtros['visao'] === '') { $filtros['visao'] = 'abertos'; }
        if ($filtros['visao'] === 'abertos' && !isset(Chamados::STATUS[$filtros['status']])) { $where[] = "c.status NOT IN ('solucionado','fechado')"; }
        if ($filtros['atendente_id'] === 'none') { $where[] = 'c.atendente_id IS NULL'; }
        if ($filtros['atendente_id'] === 'me') { $where[] = 'c.atendente_id = ?'; $params[] = $u['id']; }
        if ($filtros['q'] !== '') {
            $where[] = "(CAST(c.numero AS CHAR) = ? OR c.titulo LIKE ? ESCAPE '!' OR c.beneficiario_nome LIKE ? ESCAPE '!' OR EXISTS (SELECT 1 FROM usuarios solicitante WHERE solicitante.id = c.solicitante_id AND solicitante.nome LIKE ? ESCAPE '!'))";
            $busca = '%' . strtr($filtros['q'], ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
            array_push($params, ltrim($filtros['q'], '#'), $busca, $busca, $busca);
        }
        foreach (['area_id', 'categoria_id', 'atendente_id'] as $key) {
            if (Uuid::valid($filtros[$key])) { $where[] = 'c.' . $key . ' = ?'; $params[] = $filtros[$key]; }
        }
        if (isset(Chamados::STATUS[$filtros['status']])) { $where[] = 'c.status = ?'; $params[] = $filtros['status']; }
        if (isset(Chamados::PRIORIDADES[$filtros['prioridade']])) { $where[] = 'c.prioridade = ?'; $params[] = (int) $filtros['prioridade']; }
        // Calendários e feriados variam por chamado. O filtro usa a mesma regra dos selos,
        // antes da paginação; lê lotes pequenos para não carregar a fila inteira em memória.
        $sqlWhere = $where ? implode(' AND ', $where) : '1 = 1';
        [$pagina, $limite, $offset] = $this->paginacao();
        $consulta = 'SELECT c.*, a.nome AS area_nome, cat.nome AS categoria_nome, u.nome AS atendente_nome, s.nome AS solicitante_nome FROM chamados c JOIN servico_areas a ON a.id = c.area_id JOIN servico_categorias cat ON cat.id = c.categoria_id LEFT JOIN usuarios u ON u.id = c.atendente_id LEFT JOIN usuarios s ON s.id = c.solicitante_id WHERE ' . $sqlWhere . ($filtros['ordem'] === 'prioridade' ? ' ORDER BY c.prioridade, c.created_at, c.numero' : ' ORDER BY c.created_at DESC, c.numero DESC');
        if (isset(Chamados::PRAZOS[$filtros['prazo']]) || $filtros['prazo'] === 'alerta' || $filtros['ordem'] === 'prazo') {
            $agora = new DateTimeImmutable();
            $total = 0; $lista = []; $cursor = 0; $ordenaveis = [];
            do {
                $lote = Database::all($consulta . ' LIMIT 200 OFFSET ' . $cursor, $params);
                foreach ($lote as $item) {
                    if (isset(Chamados::PRAZOS[$filtros['prazo']]) && Chamados::situacaoSolucao($item, $agora) !== $filtros['prazo']) { continue; }
                    if ($filtros['prazo'] === 'alerta' && (in_array($item['status'], ['solucionado', 'fechado'], true) || (Chamados::situacaoSolucao($item, $agora) === 'no_prazo' && (!empty($item['respondido_em']) || Chamados::situacaoResposta($item, $agora) === 'no_prazo')))) { continue; }
                    if ($filtros['ordem'] === 'prazo') { $ordenaveis[] = ['id' => $item['id'], 'prazo' => Chamados::proximoPrazo($item, $agora)?->getTimestamp() ?? PHP_INT_MAX, 'numero' => $item['numero']]; }
                    elseif ($total >= $offset && count($lista) < $limite) { $lista[] = $item; }
                    $total++;
                }
                $cursor += 200;
            } while (count($lote) === 200);
            if ($filtros['ordem'] === 'prazo') {
                usort($ordenaveis, static fn ($a, $b) => [$a['prazo'], $a['numero']] <=> [$b['prazo'], $b['numero']]);
                $ids = array_column(array_slice($ordenaveis, $offset, $limite), 'id');
                if ($ids) {
                    $porId = array_column(Database::all('SELECT c.*, a.nome AS area_nome, cat.nome AS categoria_nome, u.nome AS atendente_nome, s.nome AS solicitante_nome FROM chamados c JOIN servico_areas a ON a.id = c.area_id JOIN servico_categorias cat ON cat.id = c.categoria_id LEFT JOIN usuarios u ON u.id = c.atendente_id LEFT JOIN usuarios s ON s.id = c.solicitante_id WHERE ' . $sqlWhere . ' AND c.id IN (' . Database::in($ids) . ')', [...$params, ...$ids]), null, 'id');
                    foreach ($ids as $id) { if (isset($porId[$id])) { $lista[] = $porId[$id]; } }
                }
            }
        } else {
            $total = (int) Database::value('SELECT COUNT(*) FROM chamados c WHERE ' . $sqlWhere, $params);
            $lista = Database::all($consulta . ' LIMIT ' . $limite . ' OFFSET ' . $offset, $params);
        }
        $escopo = $u['papel'] === 'admin' ? '1 = 1' : 'EXISTS (SELECT 1 FROM servico_area_membros m WHERE m.area_id = a.id AND m.usuario_id = ?)';
        $escopoParams = $u['papel'] === 'admin' ? [] : [$u['id']];
        if (!$fila) { $escopo = 'a.ativo = 1'; $escopoParams = []; }
        $areas = Database::all('SELECT a.id, a.nome FROM servico_areas a WHERE ' . $escopo . ' ORDER BY a.ordem, a.nome', $escopoParams);
        $categorias = []; $membros = [];
        if (in_array($filtros['area_id'], array_column($areas, 'id'), true)) {
            $categorias = Chamados::categoriasEmArvore(Database::all('SELECT id, nome, parent_id FROM servico_categorias WHERE area_id = ? ORDER BY ordem, nome', [$filtros['area_id']]));
            if ($fila) { $membros = $this->membros($filtros['area_id']); }
        }
        $satisfacaoAreas = $fila ? Database::all('SELECT a.id, a.nome, AVG(s.nota) AS media, COUNT(s.chamado_id) AS avaliacoes FROM servico_areas a LEFT JOIN chamados c ON c.area_id = a.id LEFT JOIN chamado_satisfacao s ON s.chamado_id = c.id WHERE ' . $escopo . (Uuid::valid($filtros['area_id']) ? ' AND a.id = ?' : '') . ' GROUP BY a.id, a.nome ORDER BY a.nome', [...$escopoParams, ...(Uuid::valid($filtros['area_id']) ? [$filtros['area_id']] : [])]) : [];
        $this->view('chamados/index', compact('lista', 'filtros', 'fila', 'areas', 'categorias', 'membros', 'total', 'pagina', 'limite', 'satisfacaoAreas') + ['titulo' => $fila ? t('Fila de atendimento') : t('Meus chamados')]);
    }

    private function membros(string $areaId): array
    {
        return Database::all('SELECT u.id, u.nome FROM usuarios u JOIN servico_area_membros m ON m.usuario_id = u.id WHERE m.area_id = ? AND u.ativo = 1 ORDER BY u.nome', [$areaId]);
    }

    public function detalhe(string $id): void
    {
        $u = $this->habilitado();
        $c = $this->chamado($id, $u);
        $atende = $this->atende($c['area_id'], $u);
        $info = Database::one('SELECT a.nome AS area_nome, cat.nome AS categoria_nome, s.nome AS solicitante_nome, u.nome AS atendente_nome FROM chamados c JOIN servico_areas a ON a.id = c.area_id JOIN servico_categorias cat ON cat.id = c.categoria_id LEFT JOIN usuarios s ON s.id = c.solicitante_id LEFT JOIN usuarios u ON u.id = c.atendente_id WHERE c.id = ?', [$id]);
        $c += $info;
        $eventos = Database::all('SELECT ac.*, u.nome AS usuario_nome FROM chamado_acompanhamentos ac LEFT JOIN usuarios u ON u.id = ac.usuario_id WHERE ac.chamado_id = ?' . ($atende ? '' : " AND ac.tipo <> 'interno'") . ' ORDER BY ac.created_at, ac.id', [$id]);
        $anexos = Database::all('SELECT a.id, a.nome_arquivo, a.tamanho_bytes, a.acompanhamento_id, a.campo_id FROM chamado_anexos a LEFT JOIN chamado_acompanhamentos ac ON ac.id = a.acompanhamento_id WHERE a.chamado_id = ?' . ($atende ? '' : " AND (ac.id IS NULL OR ac.tipo <> 'interno')") . ' ORDER BY a.created_at, a.id', [$id]);
        $respostas = Database::all('SELECT campo_id, rotulo, valor FROM chamado_respostas WHERE chamado_id = ? ORDER BY created_at, campo_id', [$id]);
        $membros = $atende ? $this->membros($c['area_id']) : [];
        $destinos = $atende ? Database::all('SELECT cat.id, cat.area_id, cat.nome, a.nome AS area_nome FROM servico_categorias cat JOIN servico_areas a ON a.id = cat.area_id WHERE cat.ativo = 1 AND a.ativo = 1 AND EXISTS (SELECT 1 FROM servico_area_membros m JOIN usuarios u ON u.id = m.usuario_id AND u.ativo = 1 WHERE m.area_id = a.id) ORDER BY a.nome, cat.nome') : [];
        $oldTransferencia = Session::get('_old', []);
        $destinoCategoriaId = Http::str('destino_categoria_id', (string) ($oldTransferencia['destino_categoria_id'] ?? ''));
        $destinoSelecionado = array_column($destinos, null, 'id')[$destinoCategoriaId] ?? null;
        $camposDestino = $destinoSelecionado ? FormularioDinamico::pendentes(FormularioDinamico::campos($destinoSelecionado['area_id'], $destinoCategoriaId), array_column($respostas, 'campo_id')) : [];
        $satisfacao = Database::one('SELECT nota, comentario, created_at FROM chamado_satisfacao WHERE chamado_id = ?', [$id]);
        $this->view('chamados/detalhe', compact('c', 'atende', 'eventos', 'anexos', 'respostas', 'membros', 'u', 'satisfacao', 'destinos', 'destinoCategoriaId', 'destinoSelecionado', 'camposDestino') + ['titulo' => t('Chamado #{numero}', ['numero' => $c['numero']])]);
    }

    private function evento(string $id, ?string $usuarioId, string $tipo, string $texto): string
    {
        $eventoId = Uuid::v4();
        Database::insert('chamado_acompanhamentos', ['id' => $eventoId, 'chamado_id' => $id, 'usuario_id' => $usuarioId, 'tipo' => $tipo, 'texto' => $texto]);
        return $eventoId;
    }

    public function acompanhar(string $id): void
    {
        $u = $this->habilitado();
        $this->chamado($id, $u);
        $texto = Http::str('acompanhamento_texto');
        $tipo = Http::str('tipo', 'comentario');
        $files = Upload::files('anexos');
        $voltar = '/chamados/' . $id;
        if ($texto === '' || mb_strlen($texto) > 20000 || !in_array($tipo, ['comentario', 'interno'], true)) {
            $this->falha(t('Informe um comentário válido de até 20.000 caracteres.'), $voltar);
        }
        $erros = Upload::validar($files, true);
        if ($erros) { $this->falha(implode(' ', $erros), $voltar); }
        $salvos = [];
        try {
            Database::transaction(function () use ($id, $u, $tipo, $texto, $files, &$salvos): void {
                $c = $this->chamado($id, $u, true);
                $atende = $this->atende($c['area_id'], $u);
                if ($tipo === 'interno' && !$atende) { Http::abort(403, t('Apenas a equipe da área pode atender este chamado.')); }
                if ($c['status'] === 'fechado') { throw new InvalidArgumentException(t('Chamado fechado não aceita alterações.')); }
                $eventoId = $this->evento($id, $u['id'], $tipo, $texto);
                $this->salvarAnexos($files, $id, $eventoId, $u['id'], $salvos);
                if ($atende && $tipo === 'comentario' && $c['solicitante_id'] !== $u['id'] && !$c['respondido_em']) {
                    Database::update('chamados', ['respondido_em' => date('Y-m-d H:i:s')], 'id = ?', [$id]);
                }
                Audit::log('chamados', $id, 'acompanhar', ['tipo' => $tipo]);
                if ($tipo === 'comentario') {
                    Notificacoes::chamado('comentario', $c, ['usuario_id' => $u['id'], 'texto' => $texto]);
                }
            });
        } catch (Throwable $e) {
            $this->limparAnexos($salvos);
            if ($e instanceof InvalidArgumentException) { $this->falha($e->getMessage(), $voltar); }
            throw $e;
        }
        $this->ok(t('Acompanhamento registrado.'), $voltar);
    }

    public function atribuir(string $id): void
    {
        $u = $this->habilitado('chamados.atender');
        $this->chamado($id, $u);
        $atendenteId = Http::str('atendente_id');
        if (Http::str('a_mim') === '1') { $atendenteId = $u['id']; }
        try {
            Database::transaction(function () use ($id, $u, $atendenteId): void {
                $c = $this->chamado($id, $u, true);
                if (!$this->atende($c['area_id'], $u)) { Http::abort(403, t('Apenas a equipe da área pode atender este chamado.')); }
                if (in_array($c['status'], ['solucionado', 'fechado'], true)) { throw new InvalidArgumentException(t('Reabra o chamado antes de atribuir.')); }
                $membro = Uuid::valid($atendenteId) ? Database::one('SELECT u.id FROM usuarios u JOIN servico_area_membros m ON m.usuario_id = u.id WHERE m.area_id = ? AND u.id = ? AND u.ativo = 1 FOR UPDATE', [$c['area_id'], $atendenteId]) : null;
                if (!$membro && !($u['papel'] === 'admin' && $atendenteId === $u['id'])) { throw new InvalidArgumentException(t('Escolha um membro ativo da área.')); }
                $mudancas = Chamados::mudancasAtribuicao($c, $atendenteId, new DateTimeImmutable());
                if (!$mudancas) { return; }
                if ($c['status'] === 'novo') { $this->evento($id, $u['id'], 'status', 'atribuido'); }
                Database::update('chamados', $mudancas, 'id = ?', [$id]);
                // O nome fica no histórico mesmo se a conta for renomeada depois.
                $nome = (string) Database::value('SELECT nome FROM usuarios WHERE id = ?', [$atendenteId]);
                $this->evento($id, $u['id'], 'atribuicao', $nome);
                Audit::log('chamados', $id, 'atribuir', ['de' => $c['atendente_id'], 'para' => $atendenteId]);
                Notificacoes::chamado('atribuido', array_replace($c, $mudancas), ['usuario_id' => $u['id']]);
            });
        } catch (InvalidArgumentException $e) { $this->falha($e->getMessage(), '/chamados/' . $id); }
        $this->ok(t('Atendente atualizado.'), '/chamados/' . $id);
    }

    public function status(string $id): void
    {
        // Reutiliza o POST protegido existente; não exige alteração nas rotas da equipe.
        if (Http::str('acao') === 'transferir') { $this->transferir($id); return; }
        if (Http::str('acao') === 'avaliar') { $this->avaliar($id); return; }
        $u = $this->habilitado();
        $this->chamado($id, $u);
        $destino = Http::str('status');
        $texto = Http::str('status_texto');
        $files = Upload::files('anexos');
        $erros = Upload::validar($files, true);
        if ($erros) { $this->falha(implode(' ', $erros), '/chamados/' . $id); }
        $salvos = [];
        try {
            Database::transaction(function () use ($id, $u, $destino, $texto, $files, &$salvos): void {
                $c = $this->chamado($id, $u, true);
                $atende = $this->atende($c['area_id'], $u);
                $solicitante = $c['solicitante_id'] === $u['id'];
                $aprovacao = $c['status'] === 'solucionado' && in_array($destino, ['fechado', 'atribuido'], true) && $solicitante;
                if ((!$atende && !$aprovacao) || ($destino === 'fechado' && !$aprovacao)) {
                    Http::abort(403, t('Esta ação exige a equipe da área ou a aprovação do solicitante.'));
                }
                if (mb_strlen($texto) > 20000 || (($destino === 'solucionado' || ($c['status'] === 'solucionado' && $destino === 'atribuido')) && $texto === '')) {
                    throw new InvalidArgumentException(t('Descreva a solução ou o motivo da reabertura.'));
                }
                $agora = new DateTimeImmutable();
                $mudancas = Chamados::mudarStatus($c, $destino, $agora);
                if ($destino === 'solucionado' && !$c['respondido_em']) { $mudancas['respondido_em'] = $agora->format('Y-m-d H:i:s'); }
                Database::update('chamados', $mudancas, 'id = ?', [$id]);
                $eventoId = $texto !== '' ? $this->evento($id, $u['id'], $destino === 'solucionado' ? 'solucao' : 'comentario', $texto) : null;
                $this->salvarAnexos($files, $id, $eventoId, $u['id'], $salvos);
                $this->evento($id, $u['id'], 'status', $destino);
                Audit::log('chamados', $id, match ($destino) { 'solucionado' => 'solucionar', 'fechado' => 'fechar', default => 'mudar_status' }, ['de' => $c['status'], 'para' => $destino]);
                if (in_array($destino, ['solucionado', 'fechado'], true)) {
                    Notificacoes::chamado($destino, array_replace($c, $mudancas), ['usuario_id' => $u['id']]);
                } elseif ($texto !== '') {
                    Notificacoes::chamado('comentario', array_replace($c, $mudancas), ['usuario_id' => $u['id'], 'texto' => $texto]);
                }
            });
        } catch (Throwable $e) {
            $this->limparAnexos($salvos);
            if ($e instanceof InvalidArgumentException) { $this->falha($e->getMessage(), '/chamados/' . $id); }
            throw $e;
        }
        $this->ok($destino === 'fechado' ? t('Solução aprovada. Avalie o atendimento abaixo.') : t('Status atualizado.'), '/chamados/' . $id);
    }

    private function transferir(string $id): void
    {
        $u = $this->habilitado('chamados.atender');
        $categoriaId = Http::str('destino_categoria_id');
        $motivo = Http::str('transferencia_motivo');
        $salvos = [];
        try {
            Database::transaction(function () use ($id, $u, $categoriaId, $motivo, &$salvos): void {
                $c = $this->chamado($id, $u, true);
                if (!$this->atende($c['area_id'], $u)) { Http::abort(403); }
                if (!Uuid::valid($categoriaId) || $motivo === '' || mb_strlen($motivo) > 2000) {
                    throw new InvalidArgumentException(t('Selecione o destino e informe o motivo da transferência (até 2.000 caracteres).'));
                }
                $categoria = Database::one('SELECT cat.*, a.nome AS area_nome FROM servico_categorias cat JOIN servico_areas a ON a.id = cat.area_id WHERE cat.id = ? AND cat.ativo = 1 AND a.ativo = 1 AND EXISTS (SELECT 1 FROM servico_area_membros m JOIN usuarios u ON u.id = m.usuario_id AND u.ativo = 1 WHERE m.area_id = a.id) FOR UPDATE', [$categoriaId]);
                if (!$categoria || $categoriaId === $c['categoria_id']) { throw new InvalidArgumentException(t('Escolha outra categoria ativa com equipe disponível.')); }
                $respondidos = array_column(Database::all('SELECT campo_id FROM chamado_respostas WHERE chamado_id = ?', [$id]), 'campo_id');
                $campos = FormularioDinamico::pendentes(FormularioDinamico::campos($categoria['area_id'], $categoriaId), $respondidos);
                $validacao = FormularioDinamico::validar($campos, $_POST, $_FILES);
                $arquivos = [];
                foreach ($validacao['arquivos'] as $uploads) { array_push($arquivos, ...$uploads); }
                $erros = [...$validacao['erros'], ...Upload::validar($arquivos, true)];
                if ($erros) { throw new InvalidArgumentException(implode(' ', $erros)); }
                $origem = Database::one('SELECT a.nome AS area_nome, cat.nome FROM servico_categorias cat JOIN servico_areas a ON a.id = cat.area_id WHERE cat.id = ?', [$c['categoria_id']]);
                $area = Database::one('SELECT * FROM servico_areas WHERE id = ? FOR UPDATE', [$categoria['area_id']]);
                $mudancas = Chamados::transferir($c, $categoria, new DateTimeImmutable(), $area);
                Database::update('chamados', $mudancas, 'id = ?', [$id]);
                foreach ($campos as $campo) {
                    Database::insert('chamado_respostas', ['chamado_id' => $id, 'campo_id' => $campo['id'], 'rotulo' => $campo['rotulo'], 'valor' => $validacao['valores'][$campo['id']] ?? null]);
                }
                foreach ($validacao['arquivos'] as $campoId => $uploads) { $this->salvarAnexos($uploads, $id, null, $u['id'], $salvos, $campoId); }
                $this->evento($id, $u['id'], 'comentario', t('Transferência: {origem} → {destino}. Motivo: {motivo}', [
                    'origem' => $origem['area_nome'] . ' / ' . $origem['nome'],
                    'destino' => $categoria['area_nome'] . ' / ' . $categoria['nome'], 'motivo' => $motivo,
                ]));
                $this->evento($id, $u['id'], 'status', 'novo');
                Audit::log('chamados', $id, 'transferir', ['area_origem' => $c['area_id'], 'categoria_origem' => $c['categoria_id'], 'area_destino' => $categoria['area_id'], 'categoria_destino' => $categoriaId]);
                Notificacoes::chamado('criado', array_replace($c, $mudancas), ['usuario_id' => $u['id']]);
            });
        } catch (Throwable $e) {
            $this->limparAnexos($salvos);
            if ($e instanceof InvalidArgumentException) { $this->falha($e->getMessage(), '/chamados/' . $id); }
            throw $e;
        }
        $this->ok(t('Chamado transferido. Os prazos da nova categoria começam na transferência.'), '/chamados/fila');
    }

    private function avaliar(string $id): void
    {
        $u = $this->habilitado();
        try {
            Database::transaction(function () use ($id, $u): void {
                $c = $this->chamado($id, $u, true);
                $jaAvaliou = (bool) Database::value('SELECT 1 FROM chamado_satisfacao WHERE chamado_id = ?', [$id]);
                if (!Chamados::podeAvaliar($c, $u['id'], $jaAvaliou)) {
                    throw new InvalidArgumentException(t('Somente o solicitante pode avaliar um chamado fechado, uma única vez.'));
                }
                $dados = Chamados::validarSatisfacao(Http::input('avaliacao_nota'), Http::str('avaliacao_comentario'));
                Database::insert('chamado_satisfacao', $dados + ['chamado_id' => $id, 'respondido_por' => $u['id']]);
                Audit::log('chamados', $id, 'avaliar', ['nota' => $dados['nota']]);
            });
        } catch (InvalidArgumentException $e) { $this->falha($e->getMessage(), '/chamados/' . $id); }
        $this->ok(t('Avaliação registrada. Obrigado!'), '/chamados/' . $id);
    }

    private function salvarAnexos(array $files, string $id, ?string $eventoId, string $usuarioId, array &$salvos, ?string $campoId = null): void
    {
        foreach ($files as $file) {
            $meta = Upload::salvar($file, true);
            $salvos[] = $meta['storage_path'];
            Database::insert('chamado_anexos', $meta + ['id' => Uuid::v4(), 'chamado_id' => $id, 'acompanhamento_id' => $eventoId, 'campo_id' => $campoId, 'criado_por' => $usuarioId]);
        }
    }

    private function limparAnexos(array $salvos): void
    {
        foreach ($salvos as $rel) { $path = Upload::caminho($rel); if ($path) { @unlink($path); } }
    }

    public function anexo(string $id): void
    {
        $u = $this->habilitado();
        $a = Database::one('SELECT a.*, ac.tipo FROM chamado_anexos a LEFT JOIN chamado_acompanhamentos ac ON ac.id = a.acompanhamento_id WHERE a.id = ?', [$this->uuidOu404($id)]);
        if (!$a) { Http::abort(404); }
        $c = $this->chamado($a['chamado_id'], $u);
        if ($a['tipo'] === 'interno' && !$this->atende($c['area_id'], $u)) { Http::abort(404); }
        $path = Upload::caminho($a['storage_path']);
        if (!$path || !isset(Upload::MIMES_CHAMADOS[$a['mime_type']])) { Http::abort(404); }
        header('Content-Type: ' . $a['mime_type']);
        header('Content-Length: ' . filesize($path));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        header('Content-Disposition: attachment; filename="anexo.' . Upload::MIMES_CHAMADOS[$a['mime_type']] . '"; filename*=UTF-8\'\'' . rawurlencode($a['nome_arquivo']));
        readfile($path);
    }
}
