<?php
$catalogo = App\Services\Tutoriais::permitidos();
foreach ($catalogo as &$moduloAjuda) { $moduloAjuda['route'] = url($moduloAjuda['route']); }
unset($moduloAjuda);
$tutorialConfig = ['user' => $u['id'], 'current' => $tutorialAtual['id'] ?? null, 'catalog' => $catalogo, 'home' => url('/tutoriais')];
?>
<script type="application/json" id="tutorial-config" nonce="<?= e(App\Core\Http::cspNonce()) ?>"><?= json_encode($tutorialConfig, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) ?></script>
<dialog id="tutorial-help" class="tutorial-help" aria-labelledby="tutorial-help-title">
  <header class="tutorial-cabecalho"><h2 id="tutorial-help-title">Como usar</h2><button type="button" class="btn btn-secundario" data-tutorial-close>Fechar</button></header>
  <div id="tutorial-help-body" class="tutorial-help-body"></div>
  <footer class="tutorial-rodape"><label><input type="checkbox" id="tutorial-suggestions"> Sugerir ajuda na primeira visita</label><a href="<?= e(url('/tutoriais')) ?>">Central de tutoriais</a></footer>
</dialog>
<dialog id="tutorial-tour" class="tutorial-tour" aria-labelledby="tutorial-tour-title" aria-describedby="tutorial-tour-text">
  <div id="tutorial-highlight" aria-hidden="true"></div>
  <section id="tutorial-balloon" class="tutorial-balloon">
    <p id="tutorial-counter" class="dica" aria-live="polite"></p>
    <h2 id="tutorial-tour-title"></h2><p id="tutorial-tour-text"></p>
    <div class="tutorial-botoes"><button type="button" class="btn btn-secundario" id="tutorial-prev">Anterior</button><button type="button" class="btn btn-ouro" id="tutorial-next">Próximo</button><button type="button" class="btn btn-secundario" id="tutorial-skip">Pular tour</button></div>
  </section>
</dialog>
<aside id="tutorial-invite" class="tutorial-invite" aria-label="Sugestão de ajuda" hidden>
  <p>Primeira visita a esta versão do tutorial? Conheça os controles ou consulte as instruções.</p>
  <div class="tutorial-botoes"><button class="btn btn-ouro" type="button" id="tutorial-invite-tour">Fazer o tour</button><button class="btn btn-secundario" type="button" id="tutorial-invite-help">Ver passo a passo</button><button class="btn btn-secundario" type="button" id="tutorial-invite-dismiss">Dispensar</button></div>
</aside>
