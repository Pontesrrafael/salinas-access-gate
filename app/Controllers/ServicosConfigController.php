<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Http;
use App\Core\Uuid;
use App\Services\FormularioDinamico;

/**
 * Administração das áreas de suporte, membros, árvore de categorias/SLAs e construtor de formulários dinâmicos.
 */
final class ServicosConfigController extends Controller
{
    public const ATALHOS_SLA = [
        15   => '15 min',
        30   => '30 min',
        60   => '1 h',
        120  => '2 h',
        240  => '4 h',
        480  => '8 h',
        1440 => '24 h',
        4320 => '72 h',
    ];

    public const URGENCIAS = [
        1 => '1 — Muito Baixa',
        2 => '2 — Baixa',
        3 => '3 — Média',
        4 => '4 — Alta',
        5 => '5 — Muito Alta',
    ];

    public const PAPEIS_MEMBRO = [
        'atendente' => 'Atendente',
        'gestor'    => 'Gestor',
    ];

    public function index(): void
    {
        Auth::authorize('chamados.configurar');

        $areas = Database::all(
            'SELECT a.*,
                    (SELECT COUNT(*) FROM servico_area_membros m WHERE m.area_id = a.id) AS total_membros,
                    (SELECT COUNT(*) FROM servico_categorias c WHERE c.area_id = a.id) AS total_categorias,
                    (SELECT COUNT(*) FROM servico_campos f WHERE f.area_id = a.id) AS total_campos
               FROM servico_areas a
              ORDER BY a.ativo DESC, a.ordem ASC, a.nome ASC'
        );

        $areaId = Http::str('area');
        $areaAtual = null;
        foreach ($areas as $a) {
            if ($a['id'] === $areaId) {
                $areaAtual = $a;
                break;
            }
        }
        if ($areaAtual === null && $areas !== []) {
            $areaAtual = $areas[0];
            $areaId = (string) $areaAtual['id'];
        }

        $usuarios = Database::all('SELECT id, nome, email, papel FROM usuarios WHERE ativo = 1 ORDER BY nome');
        $membros = [];
        $categorias = [];
        $campos = [];

        if ($areaAtual !== null) {
            $membros = Database::all(
                'SELECT m.area_id, m.usuario_id, m.papel, u.nome, u.email, u.papel AS usuario_papel
                   FROM servico_area_membros m
                   JOIN usuarios u ON u.id = m.usuario_id
                  WHERE m.area_id = ?
                  ORDER BY m.papel DESC, u.nome ASC',
                [$areaId]
            );

            $categoriasRaw = Database::all(
                'SELECT * FROM servico_categorias WHERE area_id = ? ORDER BY ordem ASC, nome ASC',
                [$areaId]
            );
            $categorias = self::arvoreCategorias($categoriasRaw);

            $camposRaw = Database::all(
                'SELECT f.*, c.nome AS categoria_nome
                   FROM servico_campos f
                   LEFT JOIN servico_categorias c ON c.id = f.categoria_id
                  WHERE f.area_id = ?
                  ORDER BY CASE WHEN f.categoria_id IS NULL THEN 0 ELSE 1 END, c.nome ASC, f.ordem ASC, f.rotulo ASC',
                [$areaId]
            );
            foreach ($camposRaw as $f) {
                $f['opcoes_lista'] = FormularioDinamico::decodificarOpcoes($f['opcoes'] ?? null);
                $campos[] = $f;
            }
        }

        $this->view('servicos-config/index', [
            'titulo'          => 'Configuração de Chamados',
            'descricao'       => 'Áreas de suporte, equipes, categorias com SLA e formulários dinâmicos',
            'areas'           => $areas,
            'areaAtual'       => $areaAtual,
            'usuarios'        => $usuarios,
            'membros'         => $membros,
            'categorias'      => $categorias,
            'campos'          => $campos,
            'editarArea'      => Http::str('editar_area'),
            'editarCategoria' => Http::str('editar_categoria'),
            'editarCampo'     => Http::str('editar_campo'),
        ]);
    }

    public function salvarArea(?string $id = null): void
    {
        Auth::authorize('chamados.configurar');

        $nome = Http::nstr('nome', 120);
        if ($nome === null) {
            $this->falha('Informe o nome da área.', '/servicos/config');
        }

        $slugInformado = Http::str('slug');
        $slug = self::gerarSlug($slugInformado !== '' ? $slugInformado : $nome);
        if ($slug === '') {
            $this->falha('Informe um identificador (slug) válido para a área.', '/servicos/config');
        }

        $corBruta = Http::str('cor', '#024B37');
        $cor = preg_match('/^#[0-9A-Fa-f]{6}$/', $corBruta) ? strtoupper($corBruta) : '#024B37';
        $ordem = max(0, min(9999, (int) Http::str('ordem', '0')));
        $descricao = Http::nstr('descricao', 1000);

        if (Database::value('SELECT 1 FROM servico_areas WHERE (nome = ? OR slug = ?) AND id <> ? LIMIT 1', [$nome, $slug, $id ?? ''])) {
            $this->falha('Já existe uma área com este nome ou slug.', '/servicos/config');
        }

        $d = [
            'nome'      => $nome,
            'slug'      => $slug,
            'descricao' => $descricao,
            'cor'       => $cor,
            'ordem'     => $ordem,
        ];

        if ($id === null) {
            $id = Uuid::v4();
            $d['ativo'] = 1;
            Database::insert('servico_areas', ['id' => $id, ...$d]);
            Audit::log('servico_areas', $id, 'INSERT', $d);
            $this->ok('Área de atendimento cadastrada.', '/servicos/config', ['area' => $id]);
        }

        $this->uuidOu404($id);
        if (!Database::value('SELECT 1 FROM servico_areas WHERE id = ?', [$id])) {
            Http::abort(404, 'Área não encontrada.');
        }
        $d['ativo'] = Http::bool('ativo') ? 1 : 0;
        Database::update('servico_areas', $d, 'id = ?', [$id]);
        Audit::log('servico_areas', $id, 'UPDATE', $d);
        $this->ok('Área de atendimento atualizada.', '/servicos/config', ['area' => $id]);
    }

    public function adicionarMembro(string $id): void
    {
        $this->salvarMembro($id);
    }

    public function salvarMembro(string $id): void
    {
        Auth::authorize('chamados.configurar');
        $this->uuidOu404($id);

        if (!Database::value('SELECT 1 FROM servico_areas WHERE id = ?', [$id])) {
            Http::abort(404, 'Área não encontrada.');
        }

        $usuarioId = Http::str('usuario_id');
        $papel = Http::str('papel', 'atendente');
        $voltar = '/servicos/config?area=' . urlencode($id);

        if (!Uuid::valid($usuarioId) || !array_key_exists($papel, self::PAPEIS_MEMBRO)) {
            $this->falha('Selecione um usuário ativo e o papel na área.', $voltar);
        }
        if (!Database::value('SELECT 1 FROM usuarios WHERE id = ? AND ativo = 1', [$usuarioId])) {
            $this->falha('Usuário inválido ou inativo.', $voltar);
        }

        $existe = Database::value(
            'SELECT 1 FROM servico_area_membros WHERE area_id = ? AND usuario_id = ?',
            [$id, $usuarioId]
        );
        if ($existe) {
            Database::update(
                'servico_area_membros',
                ['papel' => $papel],
                'area_id = ? AND usuario_id = ?',
                [$id, $usuarioId]
            );
            Audit::log('servico_area_membros', $id, 'UPDATE', ['usuario_id' => $usuarioId, 'papel' => $papel]);
        } else {
            Database::insert('servico_area_membros', [
                'area_id'    => $id,
                'usuario_id' => $usuarioId,
                'papel'      => $papel,
            ]);
            Audit::log('servico_area_membros', $id, 'INSERT', ['usuario_id' => $usuarioId, 'papel' => $papel]);
        }

        $this->ok('Membro da área salvo.', '/servicos/config', ['area' => $id]);
    }

    public function removerMembro(string $id, ?string $usuarioId = null): void
    {
        Auth::authorize('chamados.configurar');
        $this->uuidOu404($id);

        $uid = $usuarioId ?? Http::str('usuario_id');
        $this->uuidOu404($uid);

        Database::run('DELETE FROM servico_area_membros WHERE area_id = ? AND usuario_id = ?', [$id, $uid]);
        Audit::log('servico_area_membros', $id, 'DELETE', ['usuario_id' => $uid]);
        $this->ok('Membro removido da área.', '/servicos/config', ['area' => $id]);
    }

    public function salvarCategoria(?string $id = null): void
    {
        Auth::authorize('chamados.configurar');

        $existente = null;
        if ($id !== null) {
            $this->uuidOu404($id);
            $existente = Database::one('SELECT * FROM servico_categorias WHERE id = ?', [$id]);
            if ($existente === null) {
                Http::abort(404, 'Categoria não encontrada.');
            }
        }

        $areaId = $existente ? (string) $existente['area_id'] : Http::str('area_id');
        $this->uuidOu404($areaId);
        if (!Database::value('SELECT 1 FROM servico_areas WHERE id = ?', [$areaId])) {
            Http::abort(404, 'Área não encontrada.');
        }

        $voltar = '/servicos/config?area=' . urlencode($areaId);
        $nome = Http::nstr('nome', 150);
        if ($nome === null) {
            $this->falha('Informe o nome da categoria.', $voltar);
        }

        $parentId = Http::str('parent_id');
        if ($parentId === '') {
            $parentId = null;
        } else {
            if (!Uuid::valid($parentId)) {
                $this->falha('Categoria pai inválida.', $voltar);
            }
            $pai = Database::one('SELECT id, area_id FROM servico_categorias WHERE id = ?', [$parentId]);
            if (!$pai || $pai['area_id'] !== $areaId) {
                $this->falha('A categoria pai deve pertencer à mesma área.', $voltar);
            }
            if ($id !== null) {
                $todas = Database::all('SELECT id, parent_id FROM servico_categorias WHERE area_id = ?', [$areaId]);
                if (self::criaCicloCategoria($todas, $id, $parentId)) {
                    $this->falha('Uma categoria não pode ser filha de si mesma ou de uma subcategoria sua.', $voltar);
                }
            }
        }

        $slaResposta = self::resolverSlaMin('sla_resposta_min', 'sla_resposta_atalho');
        $slaSolucao = self::resolverSlaMin('sla_solucao_min', 'sla_solucao_atalho');
        $urgencia = max(1, min(5, (int) Http::str('urgencia_padrao', '3')));
        $ordem = max(0, min(9999, (int) Http::str('ordem', '0')));

        $d = [
            'area_id'          => $areaId,
            'parent_id'        => $parentId,
            'nome'             => $nome,
            'descricao'        => Http::nstr('descricao', 1000),
            'sla_resposta_min' => $slaResposta,
            'sla_solucao_min'  => $slaSolucao,
            'urgencia_padrao'  => $urgencia,
            'ordem'            => $ordem,
        ];

        if ($id === null) {
            $id = Uuid::v4();
            $d['ativo'] = 1;
            Database::insert('servico_categorias', ['id' => $id, ...$d]);
            Audit::log('servico_categorias', $id, 'INSERT', $d);
            $this->ok('Categoria cadastrada.', '/servicos/config', ['area' => $areaId]);
        }

        $d['ativo'] = Http::bool('ativo') ? 1 : 0;
        Database::update('servico_categorias', $d, 'id = ?', [$id]);
        Audit::log('servico_categorias', $id, 'UPDATE', $d);
        $this->ok('Categoria atualizada.', '/servicos/config', ['area' => $areaId]);
    }

    public function salvarCampo(?string $id = null): void
    {
        Auth::authorize('chamados.configurar');

        $existente = null;
        if ($id !== null) {
            $this->uuidOu404($id);
            $existente = Database::one('SELECT * FROM servico_campos WHERE id = ?', [$id]);
            if ($existente === null) {
                Http::abort(404, 'Campo não encontrado.');
            }
        }

        $areaId = $existente ? (string) $existente['area_id'] : Http::str('area_id');
        $this->uuidOu404($areaId);
        if (!Database::value('SELECT 1 FROM servico_areas WHERE id = ?', [$areaId])) {
            Http::abort(404, 'Área não encontrada.');
        }

        $voltar = '/servicos/config?area=' . urlencode($areaId);
        $rotulo = Http::nstr('rotulo', 200);
        $tipo = Http::str('tipo', 'texto');
        if ($rotulo === null || !array_key_exists($tipo, FormularioDinamico::TIPOS)) {
            $this->falha('Informe o rótulo e um tipo válido para o campo.', $voltar);
        }

        $categoriaId = Http::str('categoria_id');
        if ($categoriaId === '') {
            $categoriaId = null;
        } else {
            if (!Uuid::valid($categoriaId) || !Database::value('SELECT 1 FROM servico_categorias WHERE id = ? AND area_id = ?', [$categoriaId, $areaId])) {
                $this->falha('A categoria selecionada para o campo é inválida.', $voltar);
            }
        }

        $opcoesJson = null;
        if (in_array($tipo, ['lista', 'multipla'], true)) {
            $listaOpcoes = FormularioDinamico::decodificarOpcoes(Http::str('opcoes'));
            if ($listaOpcoes === []) {
                $this->falha('Informe ao menos uma opção (uma por linha) para campos de lista ou múltipla escolha.', $voltar);
            }
            $opcoesJson = json_encode($listaOpcoes, JSON_UNESCAPED_UNICODE);
        }

        $obrigatorio = Http::bool('obrigatorio') ? 1 : 0;
        $ajuda = Http::nstr('ajuda', 1000);

        if ($id === null) {
            $maxOrdem = (int) Database::value(
                'SELECT COALESCE(MAX(ordem), 0) FROM servico_campos WHERE area_id = ? AND categoria_id <=> ?',
                [$areaId, $categoriaId]
            );
            $ordem = $maxOrdem + 10;
            $id = Uuid::v4();
            $d = [
                'id'           => $id,
                'area_id'      => $areaId,
                'categoria_id' => $categoriaId,
                'rotulo'       => $rotulo,
                'ajuda'        => $ajuda,
                'tipo'         => $tipo,
                'opcoes'       => $opcoesJson,
                'obrigatorio'  => $obrigatorio,
                'ordem'        => $ordem,
                'ativo'        => 1,
            ];
            Database::insert('servico_campos', $d);
            Audit::log('servico_campos', $id, 'INSERT', $d);
            $this->ok('Campo adicionado ao formulário.', '/servicos/config', ['area' => $areaId]);
        }

        $d = [
            'categoria_id' => $categoriaId,
            'rotulo'       => $rotulo,
            'ajuda'        => $ajuda,
            'tipo'         => $tipo,
            'opcoes'       => $opcoesJson,
            'obrigatorio'  => $obrigatorio,
            'ativo'        => Http::bool('ativo') ? 1 : 0,
        ];
        Database::update('servico_campos', $d, 'id = ?', [$id]);
        Audit::log('servico_campos', $id, 'UPDATE', $d);
        $this->ok('Campo atualizado.', '/servicos/config', ['area' => $areaId]);
    }

    public function moverCampo(string $id): void
    {
        Auth::authorize('chamados.configurar');
        $this->uuidOu404($id);

        $campo = Database::one('SELECT * FROM servico_campos WHERE id = ?', [$id]);
        if ($campo === null) {
            Http::abort(404, 'Campo não encontrado.');
        }

        $areaId = (string) $campo['area_id'];
        $direcao = Http::str('direcao');
        if (!in_array($direcao, ['cima', 'baixo'], true)) {
            $this->falha('Direção de movimentação inválida.', '/servicos/config?area=' . urlencode($areaId));
        }

        $irmaos = Database::all(
            'SELECT id, ordem FROM servico_campos WHERE area_id = ? AND categoria_id <=> ? ORDER BY ordem ASC, rotulo ASC',
            [$areaId, $campo['categoria_id']]
        );

        $idx = null;
        foreach ($irmaos as $i => $row) {
            if ($row['id'] === $id) {
                $idx = $i;
                break;
            }
        }

        if ($idx !== null) {
            $alvo = $direcao === 'cima' ? $idx - 1 : $idx + 1;
            if (isset($irmaos[$alvo])) {
                $tmp = $irmaos[$idx];
                $irmaos[$idx] = $irmaos[$alvo];
                $irmaos[$alvo] = $tmp;

                Database::transaction(static function () use ($irmaos): void {
                    foreach ($irmaos as $pos => $item) {
                        Database::update('servico_campos', ['ordem' => ($pos + 1) * 10], 'id = ?', [$item['id']]);
                    }
                });
                Audit::log('servico_campos', $id, 'MOVE', ['direcao' => $direcao]);
            }
        }

        $this->ok('Ordem dos campos atualizada.', '/servicos/config', ['area' => $areaId]);
    }

    public function alternarCampo(string $id): void
    {
        Auth::authorize('chamados.configurar');
        $this->uuidOu404($id);

        $campo = Database::one('SELECT id, area_id, rotulo, ativo FROM servico_campos WHERE id = ?', [$id]);
        if ($campo === null) {
            Http::abort(404, 'Campo não encontrado.');
        }

        $novoAtivo = (int) $campo['ativo'] === 1 ? 0 : 1;
        Database::update('servico_campos', ['ativo' => $novoAtivo], 'id = ?', [$id]);
        Audit::log('servico_campos', $id, 'UPDATE', ['rotulo' => $campo['rotulo'], 'ativo' => $novoAtivo]);

        $msg = $novoAtivo === 1 ? 'Campo ativado.' : 'Campo desativado.';
        $this->ok($msg, '/servicos/config', ['area' => (string) $campo['area_id']]);
    }

    /**
     * Organiza categorias em árvore (DFS) calculando 'nivel' (0..N) e 'caminho' ('Pai › Filho').
     *
     * @param array<int, array<string, mixed>> $categorias
     * @return array<int, array<string, mixed>>
     */
    public static function arvoreCategorias(array $categorias): array
    {
        $porId = [];
        $filhosPorPai = [];
        foreach ($categorias as $c) {
            $cid = (string) $c['id'];
            $porId[$cid] = $c;
        }
        foreach ($categorias as $c) {
            $pid = isset($c['parent_id']) && $c['parent_id'] !== '' ? (string) $c['parent_id'] : '';
            if ($pid !== '' && !isset($porId[$pid])) {
                $pid = '';
            }
            $filhosPorPai[$pid][] = $c;
        }

        $resultado = [];
        $visitados = [];

        $percorrer = static function (string $paiId, int $nivel, string $prefixo) use (&$percorrer, &$filhosPorPai, &$resultado, &$visitados): void {
            foreach ($filhosPorPai[$paiId] ?? [] as $item) {
                $id = (string) $item['id'];
                if (isset($visitados[$id])) {
                    continue;
                }
                $visitados[$id] = true;
                $nome = (string) ($item['nome'] ?? '');
                $caminho = $prefixo === '' ? $nome : $prefixo . ' › ' . $nome;
                $item['nivel'] = $nivel;
                $item['caminho'] = $caminho;
                $resultado[] = $item;
                $percorrer($id, $nivel + 1, $caminho);
            }
        };

        $percorrer('', 0, '');
        return $resultado;
    }

    /**
     * Verifica se atribuir $novoParentId a $categoriaId cria um ciclo na hierarquia.
     *
     * @param array<int, array<string, mixed>> $categorias
     */
    public static function criaCicloCategoria(array $categorias, string $categoriaId, ?string $novoParentId): bool
    {
        if ($novoParentId === null || $novoParentId === '') {
            return false;
        }
        if ($categoriaId === $novoParentId) {
            return true;
        }
        $pais = [];
        foreach ($categorias as $c) {
            $pais[(string) $c['id']] = isset($c['parent_id']) && $c['parent_id'] !== '' ? (string) $c['parent_id'] : null;
        }
        $atual = $novoParentId;
        $passos = 0;
        while ($atual !== null && $passos <= count($pais) + 1) {
            if ($atual === $categoriaId) {
                return true;
            }
            $atual = $pais[$atual] ?? null;
            $passos++;
        }
        return false;
    }

    public static function gerarSlug(string $texto): string
    {
        $s = mb_strtolower(trim($texto), 'UTF-8');
        $mapa = [
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c', 'ñ' => 'n',
        ];
        $s = strtr($s, $mapa);
        $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
        return mb_substr(trim($s, '-'), 0, 60);
    }

    public static function formatarSla(?int $minutos): string
    {
        if ($minutos === null || $minutos <= 0) {
            return '—';
        }
        if ($minutos < 60) {
            return $minutos . ' min';
        }
        $h = intdiv($minutos, 60);
        $m = $minutos % 60;
        return $m === 0 ? $h . ' h' : $h . ' h ' . $m . ' min';
    }

    private static function resolverSlaMin(string $campoMin, string $campoAtalho): ?int
    {
        $manual = Http::str($campoMin);
        if ($manual !== '' && is_numeric($manual)) {
            $val = (int) $manual;
            return $val > 0 ? min(100800, $val) : null;
        }
        $atalho = Http::str($campoAtalho);
        if ($atalho !== '' && is_numeric($atalho)) {
            $val = (int) $atalho;
            return $val > 0 ? min(100800, $val) : null;
        }
        return null;
    }
}
