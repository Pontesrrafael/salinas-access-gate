<?php /** Layout para telas sem menu (login, formulário público, erros). @var string $content */ ?><!doctype html>
<html lang="pt-BR"<?= tema_attr() ?>>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="<?= e(session_status() === PHP_SESSION_ACTIVE ? csrf_token() : '') ?>">
  <meta name="base-url" content="<?= e(url('/')) ?>">
  <title><?= e(($titulo ?? 'Acesso') . ' — Salinas Access Gate') ?></title>
  <link rel="icon" href="<?= e(asset('img/favicon-32.png')) ?>" type="image/png" sizes="32x32">
  <link rel="icon" href="<?= e(asset('img/favicon-192.png')) ?>" type="image/png" sizes="192x192">
  <link rel="apple-touch-icon" href="<?= e(asset('img/apple-touch-icon.png')) ?>">
  <meta name="theme-color" content="#024B37">
  <meta name="color-scheme" content="light dark">
  <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
  <?php if (!empty($turnstile)): ?><script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script><?php endif; ?>
</head>
<body class="tela-simples">
  <div class="seletor-tema"><?= botao_tema('btn btn-sm') ?></div>
  <main class="cartao-central<?= !empty($largo) ? ' largo' : '' ?>">
    <div class="logo-central<?= !empty($logoVertical) ? ' vertical' : '' ?>"><img class="logo-claro" src="<?= e(asset(!empty($logoVertical) ? 'img/logo-vertical.png' : 'img/logo-horizontal.png')) ?>" alt="Salinas Gold Mineração"><img class="logo-escuro" src="<?= e(asset('img/logo-horizontal-branco.png')) ?>" alt="Salinas Gold Mineração"></div>
    <?php foreach ($flash ?? [] as $f): ?>
      <div class="alerta alerta-<?= e($f['tipo']) ?>" role="status"><?= e($f['mensagem']) ?></div>
    <?php endforeach; ?>
    <?= $content ?>
  </main>
  <p class="rodape-simples">Salinas Access Gate · Salinas Gold Mineração LTDA</p>
  <script src="<?= e(asset('js/app.js')) ?>"></script>
</body>
</html>
