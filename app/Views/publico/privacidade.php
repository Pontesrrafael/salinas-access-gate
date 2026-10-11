<?php /** @var string $org @var string $cnpj @var string $encNome @var string $encEmail @var int $retencao @var int $fotosMeses */ ?>
<article class="texto-legal">
  <h1 class="titulo-centro">Aviso de privacidade — controle de acesso</h1>
  <p><strong><?= e($org) ?></strong><?= $cnpj ? ' (CNPJ ' . e($cnpj) . ')' : '' ?> é a controladora dos dados pessoais tratados neste sistema de controle de acesso, conforme a Lei Geral de Proteção de Dados (Lei 13.709/2018).</p>

  <h2>Para que usamos seus dados</h2>
  <ul>
    <li>Identificar e autorizar a entrada e a saída de pessoas e veículos nas nossas unidades.</li>
    <li>Garantir a segurança das pessoas e do patrimônio e saber quem está em cada local em uma emergência (lista de evacuação).</li>
    <li>Cumprir as normas de segurança e saúde do trabalho (integração, ASO e treinamentos de NRs) para atividades nas áreas operacionais.</li>
  </ul>

  <h2>Quais dados</h2>
  <p>Nome, documento (CPF, guardado criptografado), empresa, função, telefone/e-mail de contato, placa do veículo, data e hora de entrada e saída, local visitado, responsável interno, documentos de segurança (tipo e validade) e fotos tiradas na portaria (os metadados de localização das fotos são removidos).</p>

  <h2>Base legal</h2>
  <p>Cumprimento de obrigação legal e regulatória (art. 7º, II — normas de segurança e saúde do trabalho) e legítimo interesse na segurança das pessoas e do patrimônio (art. 7º, IX). A confirmação de ciência no formulário registra que você leu este aviso; ela não é a base que autoriza o tratamento.</p>

  <h2>Com quem compartilhamos</h2>
  <p>Somente com as equipes internas de portaria, segurança patrimonial, SSMA e TI, com o responsável pela sua visita e com autoridades quando houver obrigação legal. Não vendemos nem cedemos seus dados.</p>

  <h2>Por quanto tempo</h2>
  <ul>
    <li>Fotos da portaria: apagadas após <strong><?= (int) $fotosMeses ?> meses</strong>.</li>
    <li>Cadastro de visitantes e terceiros: anonimizado após <strong><?= (int) $retencao ?> meses</strong> sem novo acesso. O histórico de entradas e saídas é mantido sem identificar a pessoa.</li>
    <li>Colaboradores: pelo prazo das obrigações trabalhistas e de segurança do trabalho.</li>
  </ul>

  <h2>Como protegemos</h2>
  <p>Acesso restrito por perfil, CPF criptografado, registros de auditoria que não podem ser alterados, backups criptografados e sistema disponível apenas na rede interna.</p>

  <h2>Seus direitos</h2>
  <p>Você pode pedir confirmação e acesso aos seus dados, correção, anonimização ou eliminação do que for desnecessário e informações sobre compartilhamento (art. 18). Responderemos em até 15 dias.</p>
  <p><strong>Encarregado (DPO):</strong> <?= $encNome !== '' ? e($encNome) : 'Encarregado de Proteção de Dados' ?><?= $encEmail !== '' ? ' — <a href="mailto:' . e($encEmail) . '">' . e($encEmail) . '</a>' : ' — contato pela portaria ou pelo RH' ?>.</p>
  <p class="dica">Horário de Mato Grosso – UTC-4 · Atualizado em <?= e(date('d/m/Y')) ?></p>
</article>
