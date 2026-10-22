<section class="cartao">
  <h2>Por onde começar</h2>
  <p>Confira os cadastros e responsáveis, prepare a solicitação, registre a movimentação e acompanhe a presença. Os recursos abaixo respeitam as permissões da sua conta.</p>
  <p>Os tours não realizam operações e sua conclusão não comprova treinamento formal ou aceite de política.</p>
  <label class="campo">Buscar tutorial <input type="search" id="tutorial-search" placeholder="Nome ou finalidade do módulo" autocomplete="off"></label>
</section>
<div class="tutorial-catalogo">
<?php foreach ($modulos as $m): ?>
  <article class="cartao" data-tutorial-card>
    <h2><?= icon($m['icon']) ?> <?= e($m['title']) ?></h2>
    <p><?= e($m['summary']) ?></p>
    <p class="dica" data-tutorial-status="<?= e($m['id']) ?>">Não iniciado</p>
    <div class="tutorial-botoes">
      <?php if ($m['id'] !== 'registros'): ?><a class="btn btn-secundario" href="<?= e(url($m['route'])) ?>">Abrir módulo</a><?php endif; ?>
      <button class="btn btn-ouro" type="button" data-tutorial-open="<?= e($m['id']) ?>">Como usar</button>
    </div>
  </article>
<?php endforeach; ?>
</div>
<p id="tutorial-empty" hidden role="status">Nenhum tutorial corresponde à busca.</p>
