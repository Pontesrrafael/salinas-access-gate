<h1 class="titulo-central"><?= e(t('Solicitação de acesso')) ?></h1>
<p class="subtitulo-central"><?= e(t('Visitantes e terceiros — preencha para que a área responsável autorize sua entrada.')) ?></p>
<?php if ($fixo): ?>
  <div class="alerta alerta-info"><?= e(t('Área:')) ?> <strong><?= e($fixo['area_nome'] ?? '—') ?></strong> <?= e(t('· Data prevista:')) ?> <strong><?= e(fmt_data($fixo['data_prevista'])) ?></strong><?= $fixo['local_nome'] ? ' · Local: ' . e($fixo['local_nome']) : '' ?></div>
<?php endif; ?>
<form method="post" action="<?= e($token ? url('/solicitar-acesso/' . $token) : url('/solicitar-acesso')) ?>" class="form-pilha">
  <?= csrf_field() ?>
  <div class="grade-2 compacta">
    <label class="campo campo-largo"><span><?= e(t('Nome completo')) ?> <span class="obrigatorio">*</span></span><input name="visitante_nome" required maxlength="200" value="<?= e(old($old, 'visitante_nome')) ?>" autocomplete="name"></label>
    <label class="campo"><span><?= e(t('CPF')) ?></span><input name="visitante_doc" maxlength="14" inputmode="numeric" data-mascara="cpf" value="<?= e(old($old, 'visitante_doc')) ?>"></label>
    <label class="campo"><span><?= e(t('Empresa')) ?></span><input name="empresa" maxlength="150" value="<?= e(old($old, 'empresa')) ?>" autocomplete="organization"></label>
    <label class="campo"><span><?= e(t('Telefone / WhatsApp')) ?></span><input name="contato_telefone" maxlength="30" data-mascara="telefone" value="<?= e(old($old, 'contato_telefone')) ?>" autocomplete="tel"></label>
    <label class="campo"><span><?= e(t('E-mail')) ?></span><input type="email" name="contato_email" maxlength="190" value="<?= e(old($old, 'contato_email')) ?>" autocomplete="email"></label>
    <label class="campo"><span><?= e(t('Você é')) ?></span><select name="tipo"><option value="visitante"><?= e(t('Visitante')) ?></option><option value="terceiro"<?= selected(old($old, 'tipo'), 'terceiro') ?>><?= e(t('Prestador de serviço (terceiro)')) ?></option></select></label>
    <?php if (!$fixo): ?>
      <label class="campo"><span><?= e(t('Área que irá visitar')) ?> <span class="obrigatorio">*</span></span>
        <select name="area_id" required><option value=""><?= e(t('Selecione')) ?></option>
          <?php foreach ($areas as $a): ?><option value="<?= e($a['id']) ?>"<?= selected(old($old, 'area_id'), $a['id']) ?>><?= e($a['nome']) ?></option><?php endforeach; ?>
        </select></label>
      <label class="campo"><span><?= e(t('Data prevista')) ?> <span class="obrigatorio">*</span></span><input type="date" name="data_prevista" required min="<?= e(hoje()) ?>" value="<?= e(old($old, 'data_prevista')) ?>"></label>
    <?php endif; ?>
    <label class="campo campo-largo"><span><?= e(t('Motivo da visita / atividade')) ?></span><textarea name="motivo" rows="3" maxlength="2000"><?= e(old($old, 'motivo')) ?></textarea></label>
    <label class="checkbox caixa-risco campo-largo"><input type="checkbox" name="atividade_risco" value="1"<?= checked(old($old, 'atividade_risco')) ?>>
      <span><strong><?= e(t('Vou executar atividade de risco')) ?></strong> <?= e(t('(trabalho em altura, espaço confinado, elétrica, área de mina etc.)')) ?><br><small><?= e(t('Será exigida verificação do SSMA: ASO válido, documento de identificação e autorização.')) ?></small></span></label>
    <label class="checkbox campo-largo"><input type="checkbox" name="consentimento_lgpd" value="1" required>
      <span><?= e(t('Li e estou ciente do Aviso de Privacidade: meus dados serão tratados para controle de acesso e segurança, conforme a LGPD (Lei 13.709/2018).')) ?> <a href="<?= e(url('/privacidade')) ?>" target="_blank" rel="noopener"><?= e(t('Ler o aviso')) ?></a> <span class="obrigatorio">*</span></span></label>
  </div>
  <div class="hp" aria-hidden="true"><label><?= e(t('Não preencha')) ?><input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
  <?php if ($turnstile): ?><div class="cf-turnstile" data-sitekey="<?= e($turnstile) ?>"></div><?php endif; ?>
  <button class="btn btn-ouro btn-bloco" type="submit"><?= e(t('Enviar solicitação')) ?></button>
</form>
