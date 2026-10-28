<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\{Audit, Auth, Database, Http, Session, Uuid};
use App\Services\{Chamados, FormularioDinamico, Settings, Upload};
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
        return Database::one('SELECT c.* FROM servico_categorias c JOIN servico_areas a ON a.id = c.area_id WHERE c.id = ? AND c.area_id = ? AND c.ativo = 1 AND a.ativo = 1', [$categoriaId, $areaId]);
    }

    public function abrir(): void
    {
        $this->habilitado();
        $old = Session::pullOld();
        $areaId = Http::str('area_id', (string) ($old['area_id'] ?? ''));
        $categoriaId = Http::str('categoria_id', (string) ($old['categoria_id'] ?? ''));
        $areas = Database::all('SELECT id, nome, descricao FROM servico_areas WHERE ativo = 1 ORDER BY ordem, nome');
        if (!in_array($areaId, array_column($areas, 'id'), true)) { $areaId = ''; }
        $categorias = $areaId ? Chamados::categoriasEmArvore(Database::all('SELECT * FROM servico_categorias WHERE area_id = ? AND ativo = 1 ORDER BY ordem, nome', [$areaId])) : [];
        $categoria = $this->categoria($areaId, $categoriaId);
        if (!$categoria) { $categoriaId = ''; }
        $campos = $categoria ? FormularioDinamico::campos($areaId, $categoriaId) : [];
        $this->view('chamados/abrir', compact('areas', 'areaId', 'categorias', 'categoriaId', 'categoria', 'campos', 'old') + ['titulo' => t('Abrir chamado')]);
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
        $erros = [...$validacao['erros'], ...Upload::validar($files, true)];
        if ($erros) { $this->falha(implode(' ', $erros), $voltar); }
        $id = Uuid::v4();
        $salvos = [];
        try {
            Database::transaction(function () use ($u, $categoria, $categoriaId, $areaId, $titulo, $descricao, $paraVoce, $beneficiario, $urgencia, $impacto, $files, $validacao, $campos, $id, &$salvos): void {
                // Bloqueia o cadastro enquanto registra a demanda (não aceita uma categoria desativada entre GET e POST).
                $ativa = Database::one('SELECT c.id FROM servico_categorias c JOIN servico_areas a ON a.id = c.area_id WHERE c.id = ? AND c.area_id = ? AND c.ativo = 1 AND a.ativo = 1 FOR UPDATE', [$categoriaId, $areaId]);
                if (!$ativa) { throw new InvalidArgumentException(t('Selecione uma área e uma categoria ativas.')); }
                $agora = new DateTimeImmutable();
                Database::insert('chamados', [
                    'id' => $id, 'area_id' => $areaId, 'categoria_id' => $categoriaId,
                    'titulo' => $titulo, 'descricao' => $descricao ?: null, 'solicitante_id' => $u['id'],
                    'beneficiario_nome' => $paraVoce === '0' ? $beneficiario : null,
                    'recorrente' => Http::bool('recorrente') ? 1 : 0, 'urgencia' => $urgencia, 'impacto' => $impacto,
                    'prioridade' => Chamados::prioridade($urgencia, $impacto),
                    'prazo_resposta' => Chamados::prazo($agora, isset($categoria['sla_resposta_min']) ? (int) $categoria['sla_resposta_min'] : null)?->format('Y-m-d H:i:s'),
                    'prazo_solucao' => Chamados::prazo($agora, isset($categoria['sla_solucao_min']) ? (int) $categoria['sla_solucao_min'] : null)?->format('Y-m-d H:i:s'),
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
                Audit::log('chamados', $id, 'criar', ['area_id' => $areaId, 'categoria_id' => $categoriaId]);
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
        foreach (['area_id', 'status', 'categoria_id', 'prioridade', 'atendente_id', 'prazo'] as $key) { $filtros[$key] = Http::str($key); }
        foreach (['area_id', 'categoria_id', 'atendente_id'] as $key) {
            if (Uuid::valid($filtros[$key])) { $where[] = 'c.' . $key . ' = ?'; $params[] = $filtros[$key]; }
        }
        if (isset(Chamados::STATUS[$filtros['status']])) { $where[] = 'c.status = ?'; $params[] = $filtros['status']; }
        if (isset(Chamados::PRIORIDADES[$filtros['prioridade']])) { $where[] = 'c.prioridade = ?'; $params[] = (int) $filtros['prioridade']; }
        // Mesmo cálculo do PHP: solução congela quando pendente e encerra ao solucionar.
        $restante = '(TIMESTAMPDIFF(SECOND, COALESCE(c.solucionado_em, c.fechado_em, NOW()), c.prazo_solucao) + c.segundos_pendente + IF(c.status = \'pendente\', GREATEST(0, TIMESTAMPDIFF(SECOND, c.pendente_desde, NOW())), 0))';
        $janela = 'GREATEST(0, TIMESTAMPDIFF(SECOND, c.created_at, c.prazo_solucao)) * 0.2';
        if ($filtros['prazo'] === 'vencido') { $where[] = 'c.prazo_solucao IS NOT NULL AND ' . $restante . ' < 0'; }
        if ($filtros['prazo'] === 'vencendo') { $where[] = 'c.prazo_solucao IS NOT NULL AND ' . $restante . ' BETWEEN 0 AND ' . $janela; }
        if ($filtros['prazo'] === 'no_prazo') { $where[] = '(c.prazo_solucao IS NULL OR ' . $restante . ' > ' . $janela . ')'; }
        $sqlWhere = $where ? implode(' AND ', $where) : '1 = 1';
        [$pagina, $limite, $offset] = $this->paginacao();
        $total = (int) Database::value('SELECT COUNT(*) FROM chamados c WHERE ' . $sqlWhere, $params);
        $lista = Database::all('SELECT c.*, a.nome AS area_nome, cat.nome AS categoria_nome, u.nome AS atendente_nome FROM chamados c JOIN servico_areas a ON a.id = c.area_id JOIN servico_categorias cat ON cat.id = c.categoria_id LEFT JOIN usuarios u ON u.id = c.atendente_id WHERE ' . $sqlWhere . ' ORDER BY c.created_at DESC, c.numero DESC LIMIT ' . $limite . ' OFFSET ' . $offset, $params);
        $escopo = $u['papel'] === 'admin' ? '1 = 1' : 'EXISTS (SELECT 1 FROM servico_area_membros m WHERE m.area_id = a.id AND m.usuario_id = ?)';
        $escopoParams = $u['papel'] === 'admin' ? [] : [$u['id']];
        if (!$fila) { $escopo = 'a.ativo = 1'; $escopoParams = []; }
        $areas = Database::all('SELECT a.id, a.nome FROM servico_areas a WHERE ' . $escopo . ' ORDER BY a.ordem, a.nome', $escopoParams);
        $categorias = []; $membros = [];
        if (in_array($filtros['area_id'], array_column($areas, 'id'), true)) {
            $categorias = Chamados::categoriasEmArvore(Database::all('SELECT id, nome, parent_id FROM servico_categorias WHERE area_id = ? ORDER BY ordem, nome', [$filtros['area_id']]));
            if ($fila) { $membros = $this->membros($filtros['area_id']); }
        }
        $this->view('chamados/index', compact('lista', 'filtros', 'fila', 'areas', 'categorias', 'membros', 'total', 'pagina', 'limite') + ['titulo' => $fila ? t('Fila de atendimento') : t('Meus chamados')]);
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
        $info = Database::one('SELECT a.nome AS area_nome, cat.nome AS categoria_nome, s.nome AS solicitante_nome, u.nome AS atendente_nome FROM chamados c JOIN servico_areas a ON a.id = c.area_id JOIN servico_categorias cat ON cat.id = c.categoria_id JOIN usuarios s ON s.id = c.solicitante_id LEFT JOIN usuarios u ON u.id = c.atendente_id WHERE c.id = ?', [$id]);
        $c += $info;
        $eventos = Database::all('SELECT ac.*, u.nome AS usuario_nome FROM chamado_acompanhamentos ac LEFT JOIN usuarios u ON u.id = ac.usuario_id WHERE ac.chamado_id = ?' . ($atende ? '' : " AND ac.tipo <> 'interno'") . ' ORDER BY ac.created_at, ac.id', [$id]);
        $anexos = Database::all('SELECT a.id, a.nome_arquivo, a.tamanho_bytes, a.acompanhamento_id FROM chamado_anexos a LEFT JOIN chamado_acompanhamentos ac ON ac.id = a.acompanhamento_id WHERE a.chamado_id = ?' . ($atende ? '' : " AND (ac.id IS NULL OR ac.tipo <> 'interno')") . ' ORDER BY a.created_at, a.id', [$id]);
        $respostas = Database::all('SELECT rotulo, valor FROM chamado_respostas WHERE chamado_id = ? ORDER BY created_at, campo_id', [$id]);
        $membros = $atende ? $this->membros($c['area_id']) : [];
        $this->view('chamados/detalhe', compact('c', 'atende', 'eventos', 'anexos', 'respostas', 'membros', 'u') + ['titulo' => t('Chamado #{numero}', ['numero' => $c['numero']])]);
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
        $texto = Http::str('texto');
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
                if (!$membro) { throw new InvalidArgumentException(t('Escolha um membro ativo da área.')); }
                if ($c['atendente_id'] === $atendenteId) { return; }
                $mudancas = ['atendente_id' => $atendenteId];
                if ($c['status'] === 'novo') {
                    $mudancas += Chamados::mudarStatus($c, 'atribuido', new DateTimeImmutable());
                    $this->evento($id, $u['id'], 'status', 'atribuido');
                }
                Database::update('chamados', $mudancas, 'id = ?', [$id]);
                // O nome fica no histórico mesmo se a conta for renomeada depois.
                $nome = (string) Database::value('SELECT nome FROM usuarios WHERE id = ?', [$atendenteId]);
                $this->evento($id, $u['id'], 'atribuicao', $nome);
                Audit::log('chamados', $id, 'atribuir', ['de' => $c['atendente_id'], 'para' => $atendenteId]);
            });
        } catch (InvalidArgumentException $e) { $this->falha($e->getMessage(), '/chamados/' . $id); }
        $this->ok(t('Atendente atualizado.'), '/chamados/' . $id);
    }

    public function status(string $id): void
    {
        $u = $this->habilitado();
        $this->chamado($id, $u);
        $destino = Http::str('status');
        $texto = Http::str('texto');
        try {
            Database::transaction(function () use ($id, $u, $destino, $texto): void {
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
                if ($texto !== '') { $this->evento($id, $u['id'], $destino === 'solucionado' ? 'solucao' : 'comentario', $texto); }
                $this->evento($id, $u['id'], 'status', $destino);
                Audit::log('chamados', $id, match ($destino) { 'solucionado' => 'solucionar', 'fechado' => 'fechar', default => 'mudar_status' }, ['de' => $c['status'], 'para' => $destino]);
            });
        } catch (InvalidArgumentException $e) { $this->falha($e->getMessage(), '/chamados/' . $id); }
        $this->ok(t('Status atualizado.'), '/chamados/' . $id);
    }

    private function salvarAnexos(array $files, string $id, ?string $eventoId, string $usuarioId, array &$salvos): void
    {
        foreach ($files as $file) {
            $meta = Upload::salvar($file, true);
            $salvos[] = $meta['storage_path'];
            Database::insert('chamado_anexos', $meta + ['id' => Uuid::v4(), 'chamado_id' => $id, 'acompanhamento_id' => $eventoId, 'criado_por' => $usuarioId]);
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
