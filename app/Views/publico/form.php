<h1 class="titulo-central">Solicitação de acesso</h1>
<p class="subtitulo-central">Visitantes e terceiros — preencha para que a área responsável autorize sua entrada.</p>
<?php if ($fixo): ?>
  <div class="alerta alerta-info">Área: <strong><?= e($fixo['area_nome'] ?? '—') ?></strong> · Data prevista: <strong><?= e(fmt_data($fixo['data_prevista'])) ?></strong><?= $fixo['local_nome'] ? ' · Local: ' . e($fixo['local_nome']) : '' ?></div>
<?php endif; ?>
<form method="post" action="<?= e($token ? url('/solicitar-acesso/' . $token) : url('/solicitar-acesso')) ?>" class="form-pilha">
  <?= csrf_field() ?>
  <div class="grade-2 compacta">
    <label class="campo campo-largo"><span>Nome completo <span class="obrigatorio">*</span></span><input name="visitante_nome" required maxlength="200" value="<?= e(old($old, 'visitante_nome')) ?>" autocomplete="name"></label>
    <label class="campo"><span>CPF</span><input name="visitante_doc" maxlength="14" inputmode="numeric" data-mascara="cpf" value="<?= e(old($old, 'visitante_doc')) ?>"></label>
    <label class="campo"><span>Empresa</span><input name="empresa" maxlength="150" value="<?= e(old($old, 'empresa')) ?>" autocomplete="organization"></label>
    <label class="campo"><span>Telefone / WhatsApp</span><input name="contato_telefone" maxlength="30" data-mascara="telefone" value="<?= e(old($old, 'contato_telefone')) ?>" autocomplete="tel"></label>
    <label class="campo"><span>E-mail</span><input type="email" name="contato_email" maxlength="190" value="<?= e(old($old, 'contato_email')) ?>" autocomplete="email"></label>
    <label class="campo"><span>Você é</span><select name="tipo"><option value="visitante">Visitante</option><option value="terceiro"<?= selected(old($old, 'tipo'), 'terceiro') ?>>Prestador de serviço (terceiro)</option></select></label>
    <?php if (!$fixo): ?>
      <label class="campo"><span>Área que irá visitar <span class="obrigatorio">*</span></span>
        <select name="area_id" required><option value="">Selecione</option>
          <?php foreach ($areas as $a): ?><option value="<?= e($a['id']) ?>"<?= selected(old($old, 'area_id'), $a['id']) ?>><?= e($a['nome']) ?></option><?php endforeach; ?>
        </select></label>
      <label class="campo"><span>Data prevista <span class="obrigatorio">*</span></span><input type="date" name="data_prevista" required min="<?= e(hoje()) ?>" value="<?= e(old($old, 'data_prevista')) ?>"></label>
    <?php endif; ?>
    <label class="campo campo-largo"><span>Motivo da visita / atividade</span><textarea name="motivo" rows="3" maxlength="2000"><?= e(old($old, 'motivo')) ?></textarea></label>
    <label class="checkbox caixa-risco campo-largo"><input type="checkbox" name="atividade_risco" value="1"<?= checked(old($old, 'atividade_risco')) ?>>
      <span><strong>Vou executar atividade de risco</strong> (trabalho em altura, espaço confinado, elétrica, área de mina etc.)<br><small>Será exigida verificação do SSMA: ASO válido, documento de identificação e autorização.</small></span></label>
    <label class="checkbox campo-largo"><input type="checkbox" name="consentimento_lgpd" value="1" required>
      <span>Li o <a href="<?= e(url('/privacidade')) ?>" target="_blank" rel="noopener">aviso de privacidade</a> e estou ciente de que meus dados serão usados para controle de acesso e segurança, conforme a LGPD (Lei 13.709/2018). <span class="obrigatorio">*</span></span></label>
  </div>
  <div class="hp" aria-hidden="true"><label>Não preencha<input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
  <?php if ($turnstile): ?><div class="cf-turnstile" data-sitekey="<?= e($turnstile) ?>"></div><?php endif; ?>
  <button class="btn btn-ouro btn-bloco" type="submit">Enviar solicitação</button>
</form>
