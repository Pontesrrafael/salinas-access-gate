<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Http;
use App\Core\Uuid;
use App\Services\Chamados;
use App\Services\FormularioDinamico;
use InvalidArgumentException;

/**
 * Administração das áreas de suporte, membros, árvore de categorias/SLAs e construtor de formulários dinâmicos.
 */
final class ServicosConfigController extends Controller
{
    public const ATALHOS_SLA = [
        15    => '15 min',
        30    => '30 min',
        60    => '1 h',
        120   => '2 h',
        240   => '4 h',
        480   => '8 h',
        1440  => '24 h',
        4320  => '72 h',
        7200  => '5 d',
        10080 => '7 d',
        21600 => '15 d',
        43200 => '30 d',
    ];

    public const UNIDADES_SLA = [
        'min' => 'Minutos',
        'h'   => 'Horas',
        'd'   => 'Dias',
    ];

    public const MAX_SLA_MINUTOS = 525600; // 365 dias

    /**
     * Escala única alinhada ao motor Chamados::URGENCIAS (1 = Muito alta … 5 = Muito baixa).
     */
    public const URGENCIAS = [
        1 => '1 — Muito alta',
        2 => '2 — Alta',
        3 => '3 — Média',
        4 => '4 — Baixa',
        5 => '5 — Muito baixa',
    ];

    public const PAPEIS_MEMBRO = [
        'atendente' => 'Atendente',
        'gestor'    => 'Gestor',
    ];

    /**
     * Paleta fixa de 8 cores com contraste WCAG AA (>= 4.5:1) sobre fundo branco (#FFFFFF).
     *
     * @var list<string>
     */
    public const PALETA_CORES = [
        '#024B37', // Verde institucional Salinas (Facilities — contraste 10.17:1)
        '#B54708', // Laranja (SSMA — contraste 5.44:1)
        '#3E7B27', // Verde-folha (Meio Ambiente — contraste 5.16:1)
        '#1D4ED8', // Azul (Suprimentos — contraste 6.70:1)
        '#7E22CE', // Roxo (contraste 6.98:1)
        '#B91C1C', // Vermelho (contraste 6.48:1)
        '#0F766E', // Verde-petróleo (contraste 5.47:1)
        '#BE185D', // Magenta escuro (contraste 6.04:1)
    ];

    public const CORES_AREA = self::PALETA_CORES;

    /**
     * Rótulos de urgência numerados a partir de Chamados::URGENCIAS.
     *
     * @return array<int, string>
     */
    public static function urgencias(): array
    {
        $lista = [];
        foreach (Chamados::URGENCIAS as $nivel => $rotulo) {
            $lista[(int) $nivel] = $nivel . ' — ' . t($rotulo);
        }
        return $lista;
    }

    /**
     * Sugere automaticamente a primeira cor da paleta fixa de 8 cores ainda não usada pelas áreas informadas.
     * Aceita lista de linhas (`['cor' => '#RRGGBB']`) ou lista simples de strings `#RRGGBB`.
     *
     * @param array<int, array<string, mixed>|string> $areas
     */
    public static function sugerirCorArea(array $areas = []): string
    {
        $usadas = [];
        foreach ($areas as $item) {
            $cor = is_array($item) ? ($item['cor'] ?? '') : $item;
            if (is_string($cor) && preg_match('/^#[0-9A-Fa-f]{6}$/D', trim($cor))) {
                $usadas[strtoupper(trim($cor))] = true;
            }
        }
        foreach (self::PALETA_CORES as $corPaleta) {
            $hex = strtoupper($corPaleta);
            if (!isset($usadas[$hex])) {
                return $hex;
            }
        }
        return self::PALETA_CORES[0];
    }

    /**
     * Valida estritamente uma cor no formato hexadecimal #RRGGBB e a normaliza em maiúsculas.
     */
    public static function validarCor(mixed $cor): string
    {
        if (!is_string($cor)) {
            throw new InvalidArgumentException(t('Informe uma cor válida no formato #RRGGBB.'));
        }
        $limpa = strtoupper(trim($cor));
        if (!preg_match('/^#[0-9A-F]{6}$/D', $limpa)) {
            throw new InvalidArgumentException(t('Informe uma cor válida no formato #RRGGBB.'));
        }
        return $limpa;
    }

    /**
     * Normaliza a cor para exibição segura em atributos SVG fill, usando #024B37 se inválida.
     */
    public static function normalizarCor(mixed $cor): string
    {
        if (is_string($cor) && preg_match('/^#[0-9A-Fa-f]{6}$/D', trim($cor))) {
            return strtoupper(trim($cor));
        }
        return '#024B37';
    }

    private function isAdminConfig(): bool
    {
        return Auth::can('chamados.configurar');
    }

    private function autorizarConfiguracao(?string $areaId = null): array
    {
        $u = $this->isAdminConfig()
            ? Auth::authorize('chamados.configurar')
            : Auth::authorize('chamados.configurar_area');

        if ($areaId !== null && !Auth::gereArea($areaId)) {
            Http::abort(403, t('Seu perfil não tem permissão para gerenciar esta área.'));
        }

        return $u;
    }

    public function index(): void
    {
        $this->autorizarConfiguracao();
        $isAdmin = $this->isAdminConfig();
        $areasGeridas = Auth::areasGeridas();

        $sqlAreas = 'SELECT a.*,
                            (SELECT COUNT(*) FROM servico_area_membros m WHERE m.area_id = a.id) AS total_membros,
                            (SELECT COUNT(*) FROM servico_categorias c WHERE c.area_id = a.id) AS total_categorias,
                            (SELECT COUNT(*) FROM servico_campos f WHERE f.area_id = a.id) AS total_campos
                       FROM servico_areas a';
        $ordemAreas = ' ORDER BY a.ativo DESC, a.ordem ASC, a.nome ASC';

        if ($areasGeridas === null) {
            $areas = Database::all($sqlAreas . $ordemAreas);
        } elseif ($areasGeridas === []) {
            $areas = [];
        } else {
            $idsAreas = array_values($areasGeridas);
            $areas = Database::all(
                $sqlAreas . ' WHERE a.id IN (' . Database::in($idsAreas) . ')' . $ordemAreas,
                $idsAreas
            );
        }
        $areas = array_values(array_filter($areas, static fn (array $a): bool => Auth::gereArea((string) $a['id'])));

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

        $editarArea = Http::str('editar_area');
        if ($editarArea !== '' && !Auth::gereArea($editarArea)) {
            $editarArea = '';
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

            $chamadosAbertosMembros = Database::all(
                "SELECT id, numero, titulo, status, atendente_id
                   FROM chamados
                  WHERE area_id = ?
                    AND atendente_id IS NOT NULL
                    AND status NOT IN ('solucionado', 'fechado')
                  ORDER BY numero ASC",
                [$areaId]
            );
            $porAtendente = [];
            foreach ($chamadosAbertosMembros as $ch) {
                $porAtendente[(string) $ch['atendente_id']][] = $ch;
            }
            foreach ($membros as &$m) {
                $abertos = $porAtendente[(string) $m['usuario_id']] ?? [];
                $m['chamados_abertos'] = count($abertos);
                $m['chamados_lista'] = $abertos;
            }
            unset($m);

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
            'titulo'          => t('Áreas e formulários'),
            'descricao'       => t('Áreas de suporte, equipes, categorias com SLA e formulários dinâmicos'),
            'isAdmin'         => $isAdmin,
            'areas'           => $areas,
            'areaAtual'       => $areaAtual,
            'corSugerida'     => self::sugerirCorArea($areas),
            'paletaCores'     => self::PALETA_CORES,
            'usuarios'        => $usuarios,
            'membros'         => $membros,
            'categorias'      => $categorias,
            'campos'          => $campos,
            'editarArea'      => $editarArea,
            'editarCategoria' => Http::str('editar_categoria'),
            'editarCampo'     => Http::str('editar_campo'),
        ]);
    }

    public function salvarArea(?string $id = null): void
    {
        if ($id === null) {
            Auth::authorize('chamados.configurar');
        } else {
            $this->uuidOu404($id);
            $this->autorizarConfiguracao($id);
        }

        $isAdmin = $this->isAdminConfig();
        $existente = null;
        if ($id !== null) {
            $existente = Database::one('SELECT * FROM servico_areas WHERE id = ?', [$id]);
            if ($existente === null) {
                Http::abort(404, t('Área não encontrada.'));
            }
        }

        if (!$isAdmin && $existente !== null) {
            if (array_key_exists('ativo', $_POST) && (int) Http::bool('ativo') !== (int) $existente['ativo']) {
                Http::abort(403, t('Apenas o Admin/TI pode ativar ou desativar áreas.'));
            }
            $nome = Http::nstr('nome', 120) ?? (string) $existente['nome'];
            $slug = (string) $existente['slug'];
            $cor = (string) $existente['cor'];
            $ordem = (int) ($existente['ordem'] ?? 0);
            $descricao = array_key_exists('descricao', $_POST)
                ? Http::nstr('descricao', 1000)
                : ($existente['descricao'] ?? null);
        } else {
            $nome = Http::nstr('nome', 120);
            if ($nome === null) {
                $this->falha(t('Informe o nome da área.'), '/servicos/config');
            }

            $slugInformado = Http::str('slug');
            $slug = self::gerarSlug($slugInformado !== '' ? $slugInformado : $nome);
            if ($slug === '') {
                $this->falha(t('Informe um identificador (slug) válido para a área.'), '/servicos/config');
            }

            $corInformada = $_POST['cor'] ?? null;
            if (($corInformada === null || $corInformada === '') && $id === null) {
                $corBruta = self::sugerirCorArea(Database::all('SELECT cor FROM servico_areas'));
            } else {
                $corBruta = is_string($corInformada) ? $corInformada : '';
            }

            try {
                $cor = self::validarCor($corBruta);
            } catch (InvalidArgumentException $e) {
                $this->falha($e->getMessage(), '/servicos/config');
            }

            $ordem = max(0, min(9999, (int) Http::str('ordem', '0')));
            $descricao = Http::nstr('descricao', 1000);
        }

        if (Database::value('SELECT 1 FROM servico_areas WHERE (nome = ? OR slug = ?) AND id <> ? LIMIT 1', [$nome, $slug, $id ?? ''])) {
            $this->falha(t('Já existe uma área com este nome ou slug.'), '/servicos/config');
        }

        $d = [
            'nome'      => $nome,
            'slug'      => $slug,
            'descricao' => $descricao,
            'cor'       => $cor,
            'ordem'     => $ordem,
        ];

        try {
            $d += self::dadosSla($_POST);
            // Uma alteração na área também pode invalidar prazos parcialmente herdados.
            if ($id !== null) {
                foreach (Database::all('SELECT * FROM servico_categorias WHERE area_id = ?', [$id]) as $categoria) {
                    self::validarSlaEfetivo($categoria, $d);
                }
            }
        } catch (InvalidArgumentException $e) {
            $this->falha($e->getMessage(), '/servicos/config');
        }

        if ($id === null) {
            $id = Uuid::v4();
            $d['ativo'] = 1;
            Database::insert('servico_areas', ['id' => $id, ...$d]);
            Audit::log('servico_areas', $id, 'INSERT', $d);
            $this->ok(t('Área de atendimento cadastrada.'), '/servicos/config', ['area' => $id]);
        }

        $d['ativo'] = $isAdmin ? (Http::bool('ativo') ? 1 : 0) : (int) ($existente['ativo'] ?? 1);
        Database::update('servico_areas', $d, 'id = ?', [$id]);
        Audit::log('servico_areas', $id, 'UPDATE', $d);
        $this->ok(t('Área de atendimento atualizada.'), '/servicos/config', ['area' => $id]);
    }

    public function adicionarMembro(string $id): void
    {
        $this->salvarMembro($id);
    }

    public function salvarMembro(string $id): void
    {
        $this->uuidOu404($id);
        $this->autorizarConfiguracao($id);
        $isAdmin = $this->isAdminConfig();

        if (!Database::value('SELECT 1 FROM servico_areas WHERE id = ?', [$id])) {
            Http::abort(404, t('Área não encontrada.'));
        }

        $usuarioId = Http::str('usuario_id');
        $papel = Http::str('papel', 'atendente');
        $voltar = '/servicos/config?area=' . urlencode($id);

        if (!Uuid::valid($usuarioId) || !array_key_exists($papel, self::PAPEIS_MEMBRO)) {
            $this->falha(t('Selecione um usuário ativo e o papel na área.'), $voltar);
        }
        if (!Database::value('SELECT 1 FROM usuarios WHERE id = ? AND ativo = 1', [$usuarioId])) {
            $this->falha(t('Usuário inválido ou inativo.'), $voltar);
        }

        $membroAtual = Database::one(
            'SELECT area_id, usuario_id, papel FROM servico_area_membros WHERE area_id = ? AND usuario_id = ?',
            [$id, $usuarioId]
        );
        if ($membroAtual !== null && !Auth::gereArea((string) $membroAtual['area_id'])) {
            Http::abort(403, t('Seu perfil não tem permissão para gerenciar esta área.'));
        }
        if (!$isAdmin && ($papel !== 'atendente' || ($membroAtual['papel'] ?? null) === 'gestor')) {
            Http::abort(403, t('Apenas o Admin/TI pode definir ou alterar gestores de área.'));
        }

        if ($membroAtual !== null) {
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

        $this->ok(t('Membro da área salvo.'), '/servicos/config', ['area' => $id]);
    }

    public function removerMembro(string $id, ?string $usuarioId = null): void
    {
        $this->uuidOu404($id);
        $this->autorizarConfiguracao($id);
        $isAdmin = $this->isAdminConfig();

        $uid = $usuarioId ?? Http::str('usuario_id');
        $this->uuidOu404($uid);

        $membro = Database::one(
            'SELECT area_id, usuario_id, papel FROM servico_area_membros WHERE area_id = ? AND usuario_id = ?',
            [$id, $uid]
        );
        $areaAfetada = $membro ? (string) $membro['area_id'] : $id;
        if (!Auth::gereArea($areaAfetada)) {
            Http::abort(403, t('Seu perfil não tem permissão para gerenciar esta área.'));
        }
        if (!$isAdmin && ($membro['papel'] ?? null) === 'gestor') {
            Http::abort(403, t('Apenas o Admin/TI pode remover um gestor da área.'));
        }

        $numerosDevolvidos = [];
        Database::transaction(static function () use ($areaAfetada, $uid, &$numerosDevolvidos): void {
            $abertos = Database::all(
                "SELECT id, numero, status
                   FROM chamados
                  WHERE area_id = ?
                    AND atendente_id = ?
                    AND status NOT IN ('solucionado', 'fechado')
                  ORDER BY numero ASC
                    FOR UPDATE",
                [$areaAfetada, $uid]
            );

            foreach ($abertos as $ch) {
                $cid = (string) $ch['id'];
                $statusAnterior = (string) $ch['status'];
                $novoStatus = $statusAnterior === 'atribuido' ? 'novo' : $statusAnterior;

                Database::update(
                    'chamados',
                    ['atendente_id' => null, 'status' => $novoStatus],
                    'id = ?',
                    [$cid]
                );

                if ($novoStatus !== $statusAnterior) {
                    Database::insert('chamado_acompanhamentos', [
                        'id'         => Uuid::v4(),
                        'chamado_id' => $cid,
                        'usuario_id' => Auth::id(),
                        'tipo'       => 'status',
                        'texto'      => $novoStatus,
                    ]);
                }

                $numerosDevolvidos[] = '#' . $ch['numero'];
                Audit::log('chamados', $cid, 'devolver_triagem', [
                    'area_id'            => $areaAfetada,
                    'atendente_removido' => $uid,
                    'status_anterior'    => $statusAnterior,
                    'status_novo'        => $novoStatus,
                ]);
            }

            Database::run('DELETE FROM servico_area_membros WHERE area_id = ? AND usuario_id = ?', [$areaAfetada, $uid]);
            Audit::log('servico_area_membros', $areaAfetada, 'DELETE', [
                'usuario_id'          => $uid,
                'chamados_devolvidos' => $numerosDevolvidos,
            ]);
        });

        if ($numerosDevolvidos !== []) {
            $this->ok(
                t('Membro removido da área. Chamados devolvidos à triagem: {chamados}.', ['chamados' => implode(', ', $numerosDevolvidos)]),
                '/servicos/config',
                ['area' => $areaAfetada]
            );
        }

        $this->ok(t('Membro removido da área.'), '/servicos/config', ['area' => $areaAfetada]);
    }

    public function salvarCategoria(?string $id = null): void
    {
        $this->autorizarConfiguracao();

        $existente = null;
        if ($id !== null) {
            $this->uuidOu404($id);
            $existente = Database::one('SELECT * FROM servico_categorias WHERE id = ?', [$id]);
            if ($existente === null) {
                Http::abort(404, t('Categoria não encontrada.'));
            }
        }

        $areaId = $existente ? (string) $existente['area_id'] : Http::str('area_id');
        $this->uuidOu404($areaId);
        if (!Auth::gereArea($areaId)) {
            Http::abort(403, t('Seu perfil não tem permissão para gerenciar esta área.'));
        }
        $area = Database::one('SELECT * FROM servico_areas WHERE id = ?', [$areaId]);
        if ($area === null) {
            Http::abort(404, t('Área não encontrada.'));
        }

        $voltar = '/servicos/config?area=' . urlencode($areaId);
        $nome = Http::nstr('nome', 150);
        if ($nome === null) {
            $this->falha(t('Informe o nome da categoria.'), $voltar);
        }

        $parentId = Http::str('parent_id');
        if ($parentId === '') {
            $parentId = null;
        } else {
            if (!Uuid::valid($parentId)) {
                $this->falha(t('Categoria pai inválida.'), $voltar);
            }
            $pai = Database::one('SELECT id, area_id FROM servico_categorias WHERE id = ?', [$parentId]);
            if (!$pai || $pai['area_id'] !== $areaId) {
                $this->falha(t('A categoria pai deve pertencer à mesma área.'), $voltar);
            }
            if ($id !== null) {
                $todas = Database::all('SELECT id, parent_id FROM servico_categorias WHERE area_id = ?', [$areaId]);
                if (self::criaCicloCategoria($todas, $id, $parentId)) {
                    $this->falha(t('Uma categoria não pode ser filha de si mesma ou de uma subcategoria sua.'), $voltar);
                }
            }
        }

        try {
            $sla = self::dadosSla($_POST);
            self::validarSlaEfetivo($sla, $area);
        } catch (InvalidArgumentException $e) {
            $this->falha($e->getMessage(), $voltar);
        }
        $urgencia = max(1, min(5, (int) Http::str('urgencia_padrao', '3')));
        $ordem = max(0, min(9999, (int) Http::str('ordem', '0')));

        $d = [
            'area_id'                => $areaId,
            'parent_id'              => $parentId,
            'nome'                   => $nome,
            'descricao'              => Http::nstr('descricao', 1000),
            ...$sla,
            'urgencia_padrao'        => $urgencia,
            'ordem'                  => $ordem,
        ];

        if ($id === null) {
            $id = Uuid::v4();
            $d['ativo'] = 1;
            Database::insert('servico_categorias', ['id' => $id, ...$d]);
            Audit::log('servico_categorias', $id, 'INSERT', $d);
            $this->ok(t('Categoria cadastrada.'), '/servicos/config', ['area' => $areaId]);
        }

        $d['ativo'] = Http::bool('ativo') ? 1 : 0;
        Database::update('servico_categorias', $d, 'id = ?', [$id]);
        Audit::log('servico_categorias', $id, 'UPDATE', $d);
        $this->ok(t('Categoria atualizada.'), '/servicos/config', ['area' => $areaId]);
    }

    public function salvarCampo(?string $id = null): void
    {
        $this->autorizarConfiguracao();

        $existente = null;
        if ($id !== null) {
            $this->uuidOu404($id);
            $existente = Database::one('SELECT * FROM servico_campos WHERE id = ?', [$id]);
            if ($existente === null) {
                Http::abort(404, t('Campo não encontrado.'));
            }
        }

        $areaId = $existente ? (string) $existente['area_id'] : Http::str('area_id');
        $this->uuidOu404($areaId);
        if (!Auth::gereArea($areaId)) {
            Http::abort(403, t('Seu perfil não tem permissão para gerenciar esta área.'));
        }
        if (!Database::value('SELECT 1 FROM servico_areas WHERE id = ?', [$areaId])) {
            Http::abort(404, t('Área não encontrada.'));
        }

        $voltar = '/servicos/config?area=' . urlencode($areaId);
        $rotulo = Http::nstr('rotulo', 200);
        $tipo = Http::str('tipo', 'texto');
        if ($rotulo === null || !array_key_exists($tipo, FormularioDinamico::TIPOS)) {
            $this->falha(t('Informe o rótulo e um tipo válido para o campo.'), $voltar);
        }

        $categoriaId = Http::str('categoria_id');
        if ($categoriaId === '') {
            $categoriaId = null;
        } else {
            if (!Uuid::valid($categoriaId) || !Database::value('SELECT 1 FROM servico_categorias WHERE id = ? AND area_id = ?', [$categoriaId, $areaId])) {
                $this->falha(t('A categoria selecionada para o campo é inválida.'), $voltar);
            }
        }

        $opcoesJson = null;
        if (in_array($tipo, ['lista', 'multipla'], true)) {
            $listaOpcoes = FormularioDinamico::decodificarOpcoes(Http::str('opcoes'));
            if ($listaOpcoes === []) {
                $this->falha(t('Informe ao menos uma opção (uma por linha) para campos de lista ou múltipla escolha.'), $voltar);
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
            $this->ok(t('Campo adicionado ao formulário.'), '/servicos/config', ['area' => $areaId]);
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
        $this->ok(t('Campo atualizado.'), '/servicos/config', ['area' => $areaId]);
    }

    public function moverCampo(string $id): void
    {
        $this->autorizarConfiguracao();
        $this->uuidOu404($id);

        $campo = Database::one('SELECT * FROM servico_campos WHERE id = ?', [$id]);
        if ($campo === null) {
            Http::abort(404, t('Campo não encontrado.'));
        }

        $areaId = (string) $campo['area_id'];
        if (!Auth::gereArea($areaId)) {
            Http::abort(403, t('Seu perfil não tem permissão para gerenciar esta área.'));
        }
        $direcao = Http::str('direcao');
        if (!in_array($direcao, ['cima', 'baixo'], true)) {
            $this->falha(t('Direção de movimentação inválida.'), '/servicos/config?area=' . urlencode($areaId));
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

        $this->ok(t('Ordem dos campos atualizada.'), '/servicos/config', ['area' => $areaId]);
    }

    public function alternarCampo(string $id): void
    {
        $this->autorizarConfiguracao();
        $this->uuidOu404($id);

        $campo = Database::one('SELECT id, area_id, rotulo, ativo FROM servico_campos WHERE id = ?', [$id]);
        if ($campo === null) {
            Http::abort(404, t('Campo não encontrado.'));
        }

        $areaId = (string) $campo['area_id'];
        if (!Auth::gereArea($areaId)) {
            Http::abort(403, t('Seu perfil não tem permissão para gerenciar esta área.'));
        }

        $novoAtivo = (int) $campo['ativo'] === 1 ? 0 : 1;
        Database::update('servico_campos', ['ativo' => $novoAtivo], 'id = ?', [$id]);
        Audit::log('servico_campos', $id, 'UPDATE', ['rotulo' => $campo['rotulo'], 'ativo' => $novoAtivo]);

        $msg = $novoAtivo === 1 ? t('Campo ativado.') : t('Campo desativado.');
        $this->ok($msg, '/servicos/config', ['area' => $areaId]);
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
        if ($minutos >= 1440 && $minutos % 1440 === 0) {
            return intdiv($minutos, 1440) . ' d';
        }
        $h = intdiv($minutos, 60);
        $m = $minutos % 60;
        return $m === 0 ? $h . ' h' : $h . ' h ' . $m . ' min';
    }

    /**
     * Decompõe os minutos salvos em valor e unidade (min, h, d) para pré-preenchimento do campo personalizado.
     *
     * @return array{valor:string, unidade:string}
     */
    public static function decomporSla(?int $minutos): array
    {
        if ($minutos === null || $minutos <= 0) {
            return ['valor' => '', 'unidade' => 'min'];
        }
        if ($minutos >= 1440 && $minutos % 1440 === 0) {
            return ['valor' => (string) intdiv($minutos, 1440), 'unidade' => 'd'];
        }
        if ($minutos >= 60 && $minutos % 60 === 0) {
            return ['valor' => (string) intdiv($minutos, 60), 'unidade' => 'h'];
        }
        return ['valor' => (string) $minutos, 'unidade' => 'min'];
    }

    public static function converterParaMinutos(mixed $valor, mixed $unidade = 'min'): ?int
    {
        if ($valor === null || $valor === '') {
            return null;
        }
        if (is_string($valor) && trim($valor) === '') {
            return null;
        }
        return Chamados::converterParaMinutos($valor, $unidade);
    }

    /** Validação estrita compartilhada pelos cadastros de área e categoria. */
    public static function dadosSla(array $entrada): array
    {
        $dados = [];
        foreach (['resposta', 'solucao'] as $tipo) {
            $manual = $entrada['sla_' . $tipo . '_valor'] ?? $entrada['sla_' . $tipo . '_min'] ?? '';
            $unidade = $entrada['sla_' . $tipo . '_unidade'] ?? 'min';
            $atalho = $entrada['sla_' . $tipo . '_atalho'] ?? '';
            if ((!is_string($manual) && !is_int($manual)) || (!is_string($atalho) && !is_int($atalho)) || !is_string($unidade)) {
                throw new InvalidArgumentException(t('SLA inválido; informe um prazo entre 1 minuto e 365 dias.'));
            }
            $manualStr = trim((string) $manual);
            $atalhoStr = trim((string) $atalho);
            if ($manualStr !== '') {
                $dados['sla_' . $tipo . '_min'] = Chamados::converterParaMinutos($manualStr, $unidade);
            } elseif ($atalhoStr !== '') {
                $dados['sla_' . $tipo . '_min'] = Chamados::converterParaMinutos($atalhoStr, 'min');
            } else {
                $dados['sla_' . $tipo . '_min'] = null;
            }
        }
        $regime = $entrada['usar_horario_comercial'] ?? '';
        if (!in_array($regime, ['', '0', '1'], true)) {
            throw new InvalidArgumentException(t('Selecione um regime de SLA válido.'));
        }
        $dados['usar_horario_comercial'] = $regime === '' ? null : (int) $regime;
        self::validarSlaEfetivo($dados, []);
        return $dados;
    }

    public static function validarSlaEfetivo(array $categoria, array $area): void
    {
        // O calendário não interfere na comparação de durações.
        $sla = Chamados::slaEfetivo(array_replace($categoria, ['usar_horario_comercial' => 0]), $area);
        if ($sla['resposta_min'] !== null && $sla['solucao_min'] !== null && $sla['solucao_min'] < $sla['resposta_min']) {
            throw new InvalidArgumentException(t('O SLA de solução deve ser maior ou igual ao de resposta, incluindo valores herdados.'));
        }
    }
}
