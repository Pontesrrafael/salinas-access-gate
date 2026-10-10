<?php
/** @var string $content */
$u = usuario();
$path = App\Core\Http::path();
$ativo = static fn (string $p): string => ($path === $p || str_starts_with($path, $p . '/')) ? ' ativo' : '';
$menu = [
    'Operação' => [
        ['/painel', 'Painel', 'painel', null],
        ['/indicadores', 'Indicadores', 'grafico', 'indicadores.ver'],
        ['/portaria', 'Portaria', 'portaria', 'portaria.operar'],
        ['/solicitacoes', 'Solicitações', 'solicit', 'solicitacoes.ver'],
        ['/ssma', 'Verificação SSMA', 'escudo', 'ssma.verificar'],
        ['/presenca', 'Quem está no site', 'pessoas', 'registros.ver'],
    ],
    'Cadastros' => [
        ['/pessoas', 'Pessoas', 'pessoas', 'pessoas.ver'],
        ['/veiculos', 'Veículos', 'veiculo', 'veiculos.ver'],
        ['/locais', 'Locais', 'local', 'locais.gerir'],
    ],
    'Gestão' => [
        ['/relatorios', 'Relatórios', 'relatorio', 'relatorios.ver'],
        ['/auditoria', 'Auditoria', 'auditoria', 'auditoria.ver'],
        ['/usuarios', 'Usuários', 'usuarios', 'usuarios.gerir'],
        ['/configuracoes', 'Configurações', 'config', 'configuracoes.gerir'],
    ],
];
?><!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
  <meta name="base-url" content="<?= e(url('/')) ?>">
  <title><?= e(($titulo ?? 'Painel') . ' — Salinas Access Gate') ?></title>
  <link rel="icon" href="<?= e(asset('img/favicon-32.png')) ?>" type="image/png" sizes="32x32">
  <link rel="icon" href="<?= e(asset('img/favicon-192.png')) ?>" type="image/png" sizes="192x192">
  <link rel="apple-touch-icon" href="<?= e(asset('img/apple-touch-icon.png')) ?>">
  <meta name="theme-color" content="#024B37">
  <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body<?= !empty($modoTv) ? ' class="modo-tv"' : '' ?>>
<input type="checkbox" id="menu-toggle" class="menu-toggle" aria-hidden="true">
<aside class="sidebar">
  <div class="sidebar-logo">
    <img src="<?= e(asset('img/logo-horizontal.png')) ?>" alt="Salinas Gold Mineração" width="168" height="56">
  </div>
  <nav>
    <?php foreach ($menu as $grupo => $itens):
        $visiveis = array_filter($itens, fn ($i) => $i[3] === null || can($i[3]));
        if (!$visiveis) { continue; } ?>
      <div class="nav-grupo"><?= e($grupo) ?></div>
      <?php foreach ($visiveis as [$href, $label, $ic]): ?>
        <a class="nav-item<?= $ativo($href) ?>" href="<?= e(url($href)) ?>"><?= icon($ic) ?><span><?= e($label) ?></span></a>
      <?php endforeach; ?>
    <?php endforeach; ?>
  </nav>
  <div class="sidebar-rodape">
    <div class="usuario-nome"><?= e($u['nome'] ?? '') ?></div>
    <div class="usuario-papel"><?= e(papel_label($u['papel'] ?? null)) ?><?= ($u['seguranca_patrimonial'] ?? 0) ? ' · Seg. Patrimonial' : '' ?></div>
    <form method="post" action="<?= e(url('/logout')) ?>">
      <?= csrf_field() ?>
      <button type="submit" class="nav-item nav-sair"><?= icon('sair') ?><span>Sair</span></button>
    </form>
  </div>
</aside>
<label for="menu-toggle" class="menu-overlay" aria-hidden="true"></label>
<div class="principal">
  <header class="topo">
    <label for="menu-toggle" class="btn-menu" aria-label="Abrir menu"><?= icon('menu') ?></label>
    <div class="topo-titulo">
      <h1><?= e($titulo ?? '') ?></h1>
      <?php if (!empty($descricao)): ?><p><?= e($descricao) ?></p><?php endif; ?>
    </div>
    <?php if (!empty($acoes)): ?><div class="topo-acoes"><?= $acoes /* HTML gerado pelo controller/view com e() */ ?></div><?php endif; ?>
  </header>
  <main class="conteudo">
    <?php foreach ($flash ?? [] as $f): ?>
      <div class="alerta alerta-<?= e($f['tipo']) ?>" role="status"><?= e($f['mensagem']) ?></div>
    <?php endforeach; ?>
    <?= $content ?>
  </main>
  <footer class="rodape">Salinas Access Gate · Salinas Gold Mineração LTDA · Horário de Mato Grosso – UTC-4</footer>
</div>
<div id="toast" class="toast" role="status" aria-live="polite"></div>
<script src="<?= e(asset('js/app.js')) ?>"></script>
</body>
</html>
