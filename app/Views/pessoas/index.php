<?php
$acoes = '<a class="btn btn-secundario" href="' . e(url('/pessoas/exportar', array_filter($filtro))) . '">' . icon('download') . ' Exportar CSV</a>';
if (can('pessoas.criar')) {
    $acoes .= '<a class="btn btn-ouro" href="' . e(url('/pessoas/nova')) . '">' . icon('mais') . ' Nova pessoa</a>';
}
slot('acoes', $acoes);
?>
<section class="cartao">
  <form method="get" action="<?= e(url('/pessoas')) ?>" class="filtros">
    <label class="campo"><span>Buscar</span><input type="search" name="q" value="<?= e($filtro['q']) ?>" placeholder="Nome, matrícula, empresa, e-mail ou CPF completo"></label>
    <label class="campo"><span>Categoria</span>
      <select name="categoria"><option value="">Todas</option>
        <?php foreach (CATEGORIAS as $k => $v): ?><option value="<?= e($k) ?>"<?= selected($filtro['categoria'], $k) ?>><?= e($v) ?>s</option><?php endforeach; ?>
      </select></label>
    <label class="campo"><span>Situação</span>
      <select name="situacao">
        <option value="ativos"<?= selected($filtro['situacao'], 'ativos') ?>>Ativos</option>
        <option value="inativos"<?= selected($filtro['situacao'], 'inativos') ?>>Inativos</option>
        <option value="todos"<?= selected($filtro['situacao'], 'todos') ?>>Todos</option>
      </select></label>
    <button class="btn btn-secundario" type="submit"><?= icon('busca', 'icon icon-sm') ?> Filtrar</button>
  </form>
  <p class="dica">Busca por CPF exige os 11 dígitos (o CPF é armazenado criptografado).</p>
</section>

<section class="cartao sem-padding">
  <?php if (!$rows): ?>
    <p class="mudo vazio">Nenhuma pessoa encontrada.</p>
  <?php else: ?>
  <div class="tabela-rolagem">
    <table class="tabela">
      <thead><tr><th>Nome</th><th class="ocultar-sm">Categoria</th><th class="ocultar-md">Empresa</th><th class="ocultar-md">Contato</th><th class="ocultar-lg">Documentos</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $p): ?>
        <tr>
          <td>
            <a href="<?= e(url('/pessoas/' . $p['id'])) ?>"><strong><?= e($p['nome']) ?></strong></a>
            <?php if ($p['usuario_id']): ?><?= badge('Usuário do sistema', 'info') ?><?php endif; ?>
            <?php if (!(int) $p['ativo']): ?><?= badge('Inativo', 'perigo') ?><?php endif; ?>
            <span class="sub"><?= e($p['cpf_exibicao'] ?? 'Sem CPF') ?><?= $p['matricula'] ? ' · ' . e($p['matricula']) : '' ?></span>
          </td>
          <td class="ocultar-sm"><?= badge(CATEGORIAS[$p['categoria']] ?? $p['categoria'], 'neutro') ?></td>
          <td class="ocultar-md mudo"><?= e($p['empresa'] ?? '—') ?></td>
          <td class="ocultar-md"><span class="sub"><?= e($p['telefone_principal'] ?? '—') ?></span><span class="sub"><?= e($p['email_principal'] ?? '') ?></span></td>
          <td class="ocultar-lg">
            <?php foreach ($p['documentos'] as $d): $st = status_documento($d['validade']); ?>
              <?= badge(TIPOS_DOC[$d['tipo']] ?? $d['tipo'], $st === 'vencido' ? 'perigo' : ($st === 'proximo_vencimento' ? 'aviso' : 'sucesso')) ?>
            <?php endforeach; ?>
            <?= $p['documentos'] ? '' : '<span class="mudo">—</span>' ?>
          </td>
          <td class="acoes"><a class="btn btn-fantasma btn-sm" href="<?= e(url('/pessoas/' . $p['id'])) ?>"><?= icon('editar', 'icon icon-sm') ?> Abrir</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="paginacao">
    <span><?= (int) $total ?> pessoa(s) · página <?= $pagina ?> de <?= $paginas ?></span>
    <?php if ($pagina > 1): ?><a class="btn btn-secundario btn-sm" href="<?= e(url('/pessoas', [...array_filter($filtro), 'pagina' => $pagina - 1])) ?>">Anterior</a><?php endif; ?>
    <?php if ($pagina < $paginas): ?><a class="btn btn-secundario btn-sm" href="<?= e(url('/pessoas', [...array_filter($filtro), 'pagina' => $pagina + 1])) ?>">Próxima</a><?php endif; ?>
  </div>
  <?php endif; ?>
</section>
