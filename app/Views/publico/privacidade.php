<?php
/**
 * Aviso de privacidade (LGPD arts. 9º e 41). Texto base: revise com o Encarregado/Jurídico.
 * @var array $cfg  configurações (org_nome, org_cnpj, encarregado_*, retencao_*)
 */
$org = $cfg['org_nome'] ?? '' ?: 'Salinas Gold Mineração LTDA';
$encNome = $cfg['encarregado_nome'] ?? '';
$encEmail = $cfg['encarregado_email'] ?? '';
$n = static fn (string $k, int $padrao): int => (int) ($cfg[$k] ?? $padrao);
?>
<h1 class="titulo-central"><?= e(t('Aviso de Privacidade')) ?></h1>
<p class="subtitulo-central"><?= e(t('Como tratamos seus dados no controle de acesso às nossas unidades.')) ?></p>

<div class="texto-legal">
  <h2><?= e(t('Quem trata seus dados')) ?></h2>
  <p><strong><?= e($org) ?></strong><?= !empty($cfg['org_cnpj']) ? ' (CNPJ ' . e($cfg['org_cnpj']) . ')' : '' ?> <?= e(t('é a controladora dos dados.')) ?>
    <?= e(t('Encarregado pelo tratamento de dados pessoais (DPO):')) ?>
    <?php if ($encNome !== '' || $encEmail !== ''): ?>
      <strong><?= e($encNome ?: t('Encarregado')) ?></strong><?php if ($encEmail !== ''): ?> — <a href="mailto:<?= e($encEmail) ?>"><?= e($encEmail) ?></a><?php endif; ?>.
    <?php else: ?>
      <em><?= e(t('contato a ser publicado pela empresa')) ?></em>.
    <?php endif; ?></p>

  <h2><?= e(t('Quais dados')) ?></h2>
  <ul>
    <li><?= e(t('Identificação: nome, CPF (guardado cifrado), RG ou matrícula, empresa e função.')) ?></li>
    <li><?= e(t('Contato: telefone, WhatsApp e e-mail.')) ?></li>
    <li><?= e(t('Acesso: data, hora, local e sentido de cada entrada e saída, destino, responsável interno, veículo e placa.')) ?></li>
    <li><?= e(t('Fotos tiradas na portaria (pessoa, veículo, placa ou documento). Não usamos reconhecimento facial.')) ?></li>
    <li><?= e(t('Tipo, número e validade de documentos de segurança (ASO, NRs, integração, CNH). O conteúdo do exame médico não é registrado.')) ?></li>
    <li><?= e(t('Para motoristas de veículos rastreados: percursos de GPS da frota.')) ?></li>
  </ul>

  <h2><?= e(t('Para que e com qual base legal')) ?></h2>
  <ul>
    <li><?= e(t('Controle de acesso e segurança patrimonial — legítimo interesse (LGPD, art. 7º, IX).')) ?></li>
    <li><?= e(t('Saúde e segurança no trabalho em mineração (NR-22, verificação SSMA, lista de evacuação) — cumprimento de obrigação legal (art. 7º, II) e proteção da vida e da integridade física (art. 7º, VII).')) ?></li>
    <li><?= e(t('Registros de auditoria — cumprimento de obrigação legal e exercício regular de direitos (art. 7º, II e VI).')) ?></li>
  </ul>
  <p><?= e(t('Não pedimos consentimento porque o tratamento é necessário para entrar nas unidades. Os dados não são usados para outras finalidades nem vendidos.')) ?></p>

  <h2><?= e(t('Com quem compartilhamos')) ?></h2>
  <ul>
    <li><?= e(t('Cloudflare (verificação anti-robô do formulário público).')) ?></li>
    <li><?= e(t('Infleet e Google Maps (somente veículos e motoristas da frota rastreada) — podem armazenar dados fora do Brasil, com cláusulas contratuais de proteção.')) ?></li>
    <li><?= e(t('Autoridades, quando exigido por lei ou ordem judicial.')) ?></li>
  </ul>

  <h2><?= e(t('Por quanto tempo')) ?></h2>
  <ul>
    <li><?= e(t('Fotos da portaria: {n} dias.', ['n' => $n('retencao_fotos_dias', 180)])) ?></li>
    <li><?= e(t('Solicitações de acesso: {n} meses.', ['n' => $n('retencao_solicitacoes_meses', 12)])) ?></li>
    <li><?= e(t('Registros de entrada e saída: {n} meses com observações; depois ficam só os dados mínimos.', ['n' => $n('retencao_registros_meses', 24)])) ?></li>
    <li><?= e(t('Cadastro de visitantes e terceiros sem acesso: {n} meses; depois é anonimizado.', ['n' => $n('retencao_cadastros_meses', 24)])) ?></li>
    <li><?= e(t('Chamados de serviço: {n} meses após o fechamento; depois anexos e textos pessoais são removidos.', ['n' => $n('retencao_chamados_meses', 24)])) ?></li>
    <li><?= e(t('Trilha de auditoria: {n} meses.', ['n' => $n('retencao_auditoria_meses', 60)])) ?></li>
  </ul>

  <h2><?= e(t('Seus direitos')) ?></h2>
  <p><?= e(t('Você pode pedir confirmação, acesso, correção, anonimização, informação sobre compartilhamento e revisão do tratamento (LGPD, art. 18) ao Encarregado. Também pode reclamar à Agência Nacional de Proteção de Dados (ANPD).')) ?></p>

  <h2><?= e(t('Segurança')) ?></h2>
  <p><?= e(t('CPF cifrado, acesso por perfil, autenticação em dois fatores para administradores, trilha de auditoria imutável e backups cifrados.')) ?></p>
</div>
<p class="nota-central"><a href="<?= e(url('/solicitar-acesso')) ?>"><?= e(t('Voltar à solicitação de acesso')) ?></a> · <a href="<?= e(url('/login')) ?>"><?= e(t('Entrar')) ?></a></p>
