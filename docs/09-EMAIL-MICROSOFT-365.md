# E-mail pelo Microsoft 365 no Salinas Access Gate

Guia para a TI da Salinas Gold. Revisado em 28/10/2026 com as fontes Microsoft
ao final. Requer PHP 8.2+, curl com CA confiável, openssl e saída HTTPS/443.
Não usa Composer. Nenhuma configuração deste guia foi aplicada ao tenant pelos testes locais.

## Transporte e compatibilidade

`EMAIL_TRANSPORTE=smtp` mantém o SMTP existente, inclusive o MIME com texto e HTML.
`EMAIL_TRANSPORTE=graph` envia pela API Graph com autenticação de aplicativo,
sem usuário conectado. A caixa remetente vem de `SMTP_FROM_EMAIL` nos dois modos.
No Graph, o corpo é HTML; quando só existe texto, o serviço converte com escape.
O campo `saveToSentItems` é falso. HTTP 202 significa aceitação para processamento,
e não confirmação da entrega. Consulte o rastreamento de mensagens do Exchange.
Veja [sendMail](https://learn.microsoft.com/en-us/graph/api/user-sendmail?view=graph-rest-1.0).

As notificações continuam dependendo de `email_ativo=1` nas configurações e do
agendamento de `php bin/console.php enviar-emails` a cada cinco minutos.
O comando `email-testar` é um envio explícito do operador e funciona mesmo com
notificações desligadas, sem consultar banco nem consumir a fila.

## Registrar o aplicativo e a credencial

1. No Microsoft Entra ID, abra **Registros de aplicativo → Novo registro**.
   Use um aplicativo exclusivo do Access Gate, somente deste diretório.
   Não precisa de URI de redirecionamento para client credentials.
2. Anote o ID do diretório (tenant) e o ID do aplicativo (client), ambos GUIDs.
   Em **Aplicativos empresariais**, anote também o **ID do objeto** do service
   principal. Ele difere do ID do objeto do registro do aplicativo.
3. Em **Certificados e segredos**, crie um segredo com validade controlada.
   Copie seu **valor**, não o identificador, para o cofre e a configuração local.
   Defina responsável e alerta de renovação. Não envie segredos por chamados.
4. Para Graph, a capacidade necessária é `Mail.Send` de aplicativo. Conceda-a
   pela função **Application Mail.Send** com escopo no Exchange, conforme abaixo.
   **Não mantenha um consentimento amplo de Microsoft Graph / Application /
   Mail.Send no Entra em paralelo**: as concessões se somam, e o RBAC não reduz
   uma autorização ampla já concedida. Revise as permissões efetivas existentes
   antes de revogar qualquer concessão de um aplicativo compartilhado.

O token é solicitado em `https://login.microsoftonline.com/{tenant}/oauth2/v2.0/token`
com `grant_type=client_credentials` e `scope=https://graph.microsoft.com/.default`.
O cache vive apenas no processo, separado por recurso e credencial, com margem
de 60 segundos. Cada nova execução do cron obtém seu próprio token; nenhum token
é gravado em disco. Veja [client credentials](https://learn.microsoft.com/en-us/entra/identity-platform/v2-oauth2-client-creds-grant-flow).

## Caixa compartilhada e autorização restrita

Crie ou confirme a caixa compartilhada `noreply@salinasgold.com.br` no Exchange
Online. Mantenha seu login interativo bloqueado e atribua acesso humano só quando
necessário. A execução do app usa sua própria credencial.

Um administrador autorizado executa os exemplos abaixo no Exchange Online
PowerShell, substituindo os identificadores. Estes comandos são instruções de
implantação e não fazem parte da execução do aplicativo.

```powershell
Connect-ExchangeOnline
$appId = '<ID-do-aplicativo-client>'
$enterpriseObjectId = '<ID-do-objeto-em-Aplicativos-empresariais>'
New-ServicePrincipal -AppId $appId -ObjectId $enterpriseObjectId -DisplayName 'Access Gate e-mail'
New-ManagementScope -Name 'AccessGate-Noreply' -RecipientRestrictionFilter "PrimarySmtpAddress -eq 'noreply@salinasgold.com.br'"
New-ManagementRoleAssignment -Name 'AccessGate-MailSend' -Role 'Application Mail.Send' -App $enterpriseObjectId -CustomResourceScope 'AccessGate-Noreply'
Test-ServicePrincipalAuthorization -Identity $enterpriseObjectId -Resource 'noreply@salinasgold.com.br'
Test-ServicePrincipalAuthorization -Identity $enterpriseObjectId -Resource '<outra-caixa-real-fora-do-escopo>'
```

Confira `InScope=True` somente na caixa remetente e `False` na outra caixa.
Se o principal já existir, reutilize-o após conferir AppId e ObjectId.
O teste RBAC não inclui permissões concedidas separadamente pelo Entra: revise
também seus consentimentos e valide uma operação fora do escopo em homologação.
Aguarde propagação das permissões antes de concluir que há erro de configuração.
Fonte: [RBAC para aplicativos](https://learn.microsoft.com/en-us/exchange/permissions-exo/application-rbac).

## Configurar o app.env do servidor

No ambiente que fornece as variáveis ao PHP, configure:

```dotenv
EMAIL_TRANSPORTE=graph
MS_TENANT_ID=<GUID-do-tenant>
MS_CLIENT_ID=<GUID-do-aplicativo>
MS_CLIENT_SECRET=<valor-do-segredo-no-cofre>
MS_CLIENT_CERT_PATH=
MS_CLIENT_CERT_KEY_PATH=
SMTP_FROM_EMAIL=noreply@salinasgold.com.br
SMTP_FROM_NAME="Salinas Access Gate"
SMTP_TIMEOUT=10
SMTP_TLS_VERIFY=true
```

O carregador do projeto lê `.env` na raiz. Se a hospedagem usa `app.env`, injete
suas variáveis no processo PHP/cron ou disponibilize o arquivo como `.env` fora
da pasta pública, seguindo o padrão do servidor. Variáveis de um arquivo `.env`
existente têm prioridade sobre o ambiente. Confira a configuração de ambos os
processos, web e cron, sem imprimir os valores sensíveis.

Graph e o endpoint de token sempre verificam TLS; `SMTP_TLS_VERIFY` não desliga
essa verificação. Use o pacote de CAs do sistema ou configure `curl.cainfo` no
PHP. Os endpoints são fixos da nuvem pública Microsoft; não há redirecionamento
HTTP nem configuração de endpoint arbitrário. Nuvens soberanas não estão cobertas.

### Alternativa com certificado

Cadastre o certificado público em **Certificados e segredos → Certificados**.
Guarde o certificado PEM e a chave privada RSA PEM (mínimo 2048 bits, sem senha)
fora da pasta pública e do repositório; conceda leitura apenas ao usuário do PHP
e do cron. Configure `MS_CLIENT_CERT_PATH` e `MS_CLIENT_CERT_KEY_PATH` com caminhos
absolutos. Os dois caminhos têm prioridade sobre o segredo; uma configuração
incompleta falha, sem retornar silenciosamente ao segredo.

O cliente implementa assertion **RS256/x5t**, com audience do endpoint de token,
`jti` aleatório e validade de cinco minutos; verifica validade e correspondência
entre certificado e chave. Mantenha o relógio do servidor sincronizado.
A [orientação atual para certificados](https://learn.microsoft.com/en-us/entra/identity-platform/certificate-credentials)
recomenda PS256/x5t#S256. O fluxo RS256 desta entrega tem assinatura validada
localmente, mas precisa de homologação no tenant antes da adoção; use o segredo
como caminho inicial se essa compatibilidade não estiver confirmada.
Renove o certificado com sobreposição controlada e reinicie workers persistentes
para esvaziar seu cache após uma rotação. Não há suporte a PFX ou chave cifrada.

## Alternativa SMTP com OAuth2

Em um aplicativo dedicado, adicione **Office 365 Exchange Online → Application →
SMTP.SendAsApp** e conceda consentimento de administrador. Registre seu service
principal no Exchange com os IDs corretos. Restrinja o acesso à caixa necessária:

```powershell
$sp = Get-ServicePrincipal -Identity '<ID-do-objeto-em-Aplicativos-empresariais>'
Add-MailboxPermission -Identity 'noreply@salinasgold.com.br' -User $sp.Identity -AccessRights FullAccess
Add-RecipientPermission -Identity 'noreply@salinasgold.com.br' -Trustee $sp.Identity -AccessRights SendAs
Get-CASMailbox -Identity 'noreply@salinasgold.com.br' | Format-List SmtpClientAuthenticationDisabled
```

Essas permissões são próprias do fluxo SMTP app-only e não substituem o RBAC
do Graph. Avalie a política do tenant antes de habilitar SMTP AUTH somente nessa
caixa; não desabilite os padrões de segurança do tenant para contornar um bloqueio.
Confira os requisitos oficiais de [SMTP OAuth app-only](https://learn.microsoft.com/en-us/exchange/client-developer/legacy-protocols/how-to-authenticate-an-imap-pop-smtp-application-by-using-oauth)
e de [SMTP AUTH](https://learn.microsoft.com/en-us/exchange/clients-and-mobile-in-exchange-online/authenticated-client-smtp-submission).

```dotenv
EMAIL_TRANSPORTE=smtp
EMAIL_SMTP_AUTH=oauth
SMTP_HOST=smtp.office365.com
SMTP_PORT=587
SMTP_SECURITY=tls
SMTP_TLS_VERIFY=true
SMTP_USER=noreply@salinasgold.com.br
SMTP_PASS=
SMTP_FROM_EMAIL=noreply@salinasgold.com.br
```

Mantenha `MS_TENANT_ID`, `MS_CLIENT_ID` e a credencial como no Graph. O token SMTP
usa `https://outlook.office365.com/.default`. A conversa usa STARTTLS seguido de
`AUTH XOAUTH2`, com formato SASL `user=<caixa>\x01auth=Bearer <token>\x01\x01`
codificado em Base64. Não há fallback automático para senha. OAuth recusa TLS
desligado ou certificado sem verificação. `EMAIL_SMTP_AUTH=login` preserva o modo
legado para outros provedores; prefira autenticação moderna no Microsoft 365.

## Autenticação do domínio e teste operacional

- **SPF:** mantenha um único TXT SPF para o domínio. Inclua as fontes legítimas
  existentes; se Microsoft 365 for a única fonte, o exemplo é
  `v=spf1 include:spf.protection.outlook.com -all`. Confira o limite de consultas
  DNS. [Configurar SPF](https://learn.microsoft.com/en-us/defender-office-365/email-authentication-spf-configure).
- **DKIM:** obtenha os dois destinos CNAME específicos do domínio no portal
  Microsoft e habilite a assinatura após a propagação. Não copie destinos de
  outro tenant. [Configurar DKIM](https://learn.microsoft.com/en-us/defender-office-365/email-authentication-dkim-configure).
- **DMARC:** publique `_dmarc` com caixa de relatórios administrada pela TI,
  valide alinhamento e fontes legítimas antes de avançar de monitoramento para
  quarentena/rejeição. [Configurar DMARC](https://learn.microsoft.com/en-us/defender-office-365/email-authentication-dmarc-configure).

Execute com o mesmo PHP, usuário e configuração do cron:

```console
php bin/console.php email-testar --para=destinatario-autorizado@salinasgold.com.br
php bin/console.php enviar-emails
```

O segundo comando processa mensagens reais já pendentes; execute-o apenas na
janela autorizada. Confirme recebimento interno/externo, remetente, links, idioma,
SPF/DKIM/DMARC nos cabeçalhos e rastreamento no Exchange. Depois, teste um chamado
controlado com notificações ligadas e destinatário autorizado. O diagnóstico
retorna código 0 quando o serviço aceita a mensagem e 1 em caso de falha.

## Falhas, operação e retorno ao transporte anterior

| Resposta | Comportamento e verificação |
| --- | --- |
| 401 Graph | Renova o token uma vez; se persistir, devolve falha à fila. |
| 403 | Confira permissão, caixa e escopo; a fila limita o número de tentativas. |
| 429 | Reagenda com o maior valor entre backoff e Retry-After, em segundos ou data HTTP. |
| 5xx / rede | Reaproveita o backoff e o limite de cinco tentativas existentes. |
| SMTP OAuth | Confira SMTP.SendAsApp, caixa, política SMTP AUTH, TLS e escopo Outlook. |

As esperas existentes são 1, 5, 15, 60 e 120 minutos; após cinco tentativas o item
não é selecionado novamente. Não há espera bloqueante dentro do worker. A fila
continua revalidando o acesso do destinatário antes do envio. Um timeout após
aceitação pode gerar duplicidade em nova tentativa: a fila não promete entrega
exatamente uma vez. Corpos HTTP não são registrados; segredo, assertion, token e
AUTH codificado são removidos dos diagnósticos conhecidos. Não habilite logs de
HTTP com cabeçalhos Authorization em produção.
Veja [throttling e Retry-After](https://learn.microsoft.com/en-us/graph/throttling).

Para retornar, restaure `EMAIL_TRANSPORTE=smtp` e a configuração anterior validada,
reinicie workers persistentes e valide o diagnóstico antes de retomar o cron.
Essa troca não apaga fila nem altera preferências dos usuários.

## Pendências

- Homologar credencial, escopo positivo/negativo e entrega real no tenant Salinas Gold.
- Conferir caixa compartilhada, DNS, CAs, relógio, cron e permissões dos arquivos no servidor.
- Gerente integra traduções de `codex-textos.json`; esta etapa não precisa de rotas novas.

## Pontos de melhoria

- Evoluir assertion para PS256/x5t#S256 conforme a orientação atual da Microsoft.
- Alertar para expiração da credencial, falhas esgotadas e crescimento da fila.
- Avaliar coordenação de throttling entre workers e processo seguro de reenvio manual.
