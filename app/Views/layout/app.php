<?php
/** @var string $content */
$u = usuario();
$path = App\Core\Http::path();
$tutorialAtual = App\Services\Tutoriais::daTela($path);
$ativo = static fn (string $p): string => ($path === $p || str_starts_with($path, $p . '/')) ? ' ativo' : '';
$menu = [
    'Ajuda' => [
        ['/tutoriais', 'Tutoriais', 'relatorio', null],
    ],
    'Operação' => [
        ['/painel', 'Painel', 'painel', null],
        ['/indicadores', 'Indicadores', 'grafico', 'indicadores.ver'],
        ['/portaria', 'Portaria', 'portaria', 'portaria.operar'],
        ['/solicitacoes', 'Solicitações', 'solicit', 'solicitacoes.ver'],
        ['/ssma', 'Verificação SSMA', 'escudo', 'ssma.verificar'],
        ['/presenca', 'Quem está no site', 'pessoas', 'registros.ver'],
    ],
    'Serviços' => [
        ['/chamados/abrir', 'Abrir chamado', 'mais', 'chamados.abrir'],
        ['/chamados/meus', 'Meus chamados', 'solicit', 'chamados.abrir'],
        ['/chamados/fila', 'Fila de atendimento', 'pessoas', 'chamados.atender'],
    ],
    'Cadastros' => [
        ['/pessoas', 'Pessoas', 'pessoas', 'pessoas.ver'],
        ['/veiculos', 'Veículos', 'veiculo', 'veiculos.ver'],
        ['/infleet', 'Infleet', 'veiculo', 'infleet.ver'],
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
<html lang="<?= e(App\Core\I18n::current()) ?>"<?= tema_attr() ?>>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
  <meta name="base-url" content="<?= e(url('/')) ?>">
  <title><?= e(t($titulo ?? 'Painel') . ' — Salinas Access Gate') ?></title>
  <link rel="icon" href="<?= e(asset('img/favicon-32.png')) ?>" type="image/png" sizes="32x32">
  <link rel="icon" href="<?= e(asset('img/favicon-192.png')) ?>" type="image/png" sizes="192x192">
  <link rel="apple-touch-icon" href="<?= e(asset('img/apple-touch-icon.png')) ?>">
  <meta name="theme-color" content="#024B37">
  <meta name="color-scheme" content="light dark">
  <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>" nonce="<?= e(App\Core\Http::cspNonce()) ?>">
  <link rel="stylesheet" href="<?= e(asset('css/tutoriais.css')) ?>">
  <?php foreach ($styles ?? [] as $style): ?><link rel="stylesheet" href="<?= e(asset($style)) ?>" nonce="<?= e(App\Core\Http::cspNonce()) ?>"><?php endforeach; ?>
</head>
<body<?= !empty($modoTv) ? ' class="modo-tv"' : '' ?>>
<input type="checkbox" id="menu-toggle" class="menu-toggle" aria-hidden="true">
<aside class="sidebar">
  <div class="sidebar-logo">
    <img class="logo-claro" src="<?= e(asset('img/logo-horizontal.png')) ?>" alt="Salinas Gold Mineração" width="168" height="56">
    <img class="logo-escuro" src="<?= e(asset('img/logo-horizontal-branco.png')) ?>" alt="Salinas Gold Mineração" width="168" height="56">
  </div>
  <nav>
    <?php foreach ($menu as $grupo => $itens):
        $visiveis = array_filter($itens, fn ($i) => $i[3] === null || can($i[3]));
        if (!$visiveis) { continue; } ?>
      <div class="nav-grupo"><?= e(t($grupo)) ?></div>
      <?php foreach ($visiveis as [$href, $label, $ic]): ?>
        <a class="nav-item<?= $ativo($href) ?>" href="<?= e(url($href)) ?>"><?= icon($ic) ?><span><?= e(t($label)) ?></span></a>
      <?php endforeach; ?>
    <?php endforeach; ?>
  </nav>
  <div class="sidebar-rodape">
    <div class="usuario-nome"><?= e($u['nome'] ?? '') ?></div>
    <div class="usuario-papel"><?= e(papel_label($u['papel'] ?? null)) ?><?= ($u['seguranca_patrimonial'] ?? 0) ? ' · ' . e(t('Seg. Patrimonial')) : '' ?></div>
    <?= botao_tema() ?>
    <?php if (($u['auth_origem'] ?? 'local') === 'local'): ?>
      <a class="nav-item<?= $ativo('/conta/mfa') ?>" href="<?= e(url('/conta/mfa')) ?>"><?= icon('escudo') ?><span><?= e(($u['mfa_ativo'] ?? 0) ? t('Duas etapas') : t('Duas etapas (desligada)')) ?></span></a>
    <?php endif; ?>
    <form method="post" action="<?= e(url('/logout')) ?>">
      <?= csrf_field() ?>
      <button type="submit" class="nav-item nav-sair"><?= icon('sair') ?><span><?= e(t('Sair')) ?></span></button>
    </form>
  </div>
</aside>
<label for="menu-toggle" class="menu-overlay" aria-hidden="true"></label>
<div class="principal">
  <header class="topo">
    <label for="menu-toggle" class="btn-menu" aria-label="<?= e(t('Abrir menu')) ?>"><?= icon('menu') ?></label>
    <div class="topo-titulo">
      <h1><?= e(t($titulo ?? '')) ?></h1>
      <?php if (!empty($descricao)): ?><p><?= e(t($descricao)) ?></p><?php endif; ?>
    </div>
    <div class="topo-acoes"<?= !empty($acoes) ? ' data-tour="acoes"' : '' ?>>
      <?php if ($tutorialAtual): ?><button type="button" class="btn btn-secundario" data-tutorial-open="<?= e($tutorialAtual['id']) ?>"><?= e(t('Como usar')) ?></button><?php endif; ?>
      <?= $acoes ?? '' /* HTML da aplicação */ ?>
      <?= seletor_idioma() ?>
    </div>
  </header>
  <main class="conteudo" data-tour="conteudo">
    <?php foreach ($flash ?? [] as $f): ?>
      <div class="alerta alerta-<?= e($f['tipo']) ?>" role="status"><?= e(t($f['mensagem'])) ?></div>
    <?php endforeach; ?>
    <?= $content ?>
  </main>
  <footer class="rodape">Salinas Access Gate · Salinas Gold Mineração LTDA · <?= e(t('Horário de Mato Grosso – UTC-4')) ?></footer>
</div>
<div id="toast" class="toast" role="status" aria-live="polite"></div>
<?= App\Core\View::partial('tutoriais/ajuda', ['tutorialAtual' => $tutorialAtual, 'u' => $u]) ?>
<?= scripts_idioma() ?>
<script src="<?= e(asset('js/app.js')) ?>" nonce="<?= e(App\Core\Http::cspNonce()) ?>"></script>
<script src="<?= e(asset('js/tutoriais.js')) ?>" nonce="<?= e(App\Core\Http::cspNonce()) ?>"></script>
<?php foreach ($scripts ?? [] as $script): ?><script src="<?= e(asset($script)) ?>" nonce="<?= e(App\Core\Http::cspNonce()) ?>"></script><?php endforeach; ?>
</body>
</html>
