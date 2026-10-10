# Integração com Active Directory (LDAP)

**Documento:** MD-TI-SIS-CBA-004 · Rev. 01 · Salinas Access Gate

A integração é **opcional** e vem **desligada**: sem AD, todos os usuários são locais e o sistema funciona normalmente.
Ela pode ser ligada e desligada pela tela **Configurações → Active Directory / LDAP** (seção 3), sem editar arquivos nem reiniciar.
Quando está ligada, cada usuário pode ser:

| Tipo | Como entra | Senha |
|---|---|---|
| **Conta local** | e-mail **ou só o usuário** (`rafael.pontes` para `rafael.pontes@salinasgold.com.br`) + senha do sistema | bcrypt no banco, com troca obrigatória no 1º acesso |
| **Active Directory** | usuário de rede (`rafael.pontes`, `SALINASGOLD\rafael.pontes` ou e-mail) | validada no AD a cada login. **Nenhuma senha de rede é gravada** |

> Mantenha sempre **um admin local** como conta de contingência. Ele entra mesmo com o AD fora do ar.

## 1. Como funciona

1. A **conta de serviço** (somente leitura) faz bind por LDAPS e localiza o usuário pelo `objectGUID`, que não muda se a conta trocar de OU ou de nome.
2. A senha digitada é validada com um bind usando o DN do próprio usuário. Senha vazia é recusada para impedir bind anônimo.
3. Os grupos (inclusive **aninhados**) definem o perfil e a flag Segurança Patrimonial.
4. A cada login, e no cron diário, o sistema atualiza nome, e-mail, matrícula (`employeeID`) e DN, além do perfil se `LDAP_SYNC_ROLES=true`. **Conta desativada ou removida no AD** perde o acesso e as sessões abertas são encerradas.
5. Se a matrícula do AD bater com um colaborador cadastrado em Pessoas, o usuário é **vinculado automaticamente** a essa pessoa. Ela passa a aparecer como responsável interno padrão na portaria.

## 2. Preparação no AD (PowerShell, como Domain Admin)

```powershell
Import-Module ActiveDirectory
$ou = "OU=Grupos,DC=salinasgold,DC=local"   # ajuste para a sua estrutura

# Conta de serviço somente leitura (sem logon interativo, senha forte e que não expira)
New-ADUser -Name "svc-accessgate" -SamAccountName "svc-accessgate" -Path "OU=Servicos,DC=salinasgold,DC=local" `
  -AccountPassword (Read-Host -AsSecureString "Senha") -Enabled $true -PasswordNeverExpires $true `
  -CannotChangePassword $true -Description "Leitura LDAP - Salinas Access Gate"

# Grupos de acesso (um por perfil + Segurança Patrimonial)
"GG-AccessGate-Admin","GG-AccessGate-Supervisor","GG-AccessGate-SSMA","GG-AccessGate-Porteiro","GG-AccessGate-SegPatrimonial" |
  ForEach-Object { New-ADGroup -Name $_ -GroupScope Global -GroupCategory Security -Path $ou }

# Exemplos de membros (grupos aninhados também funcionam)
Add-ADGroupMember GG-AccessGate-Admin -Members rafael.pontes
Add-ADGroupMember GG-AccessGate-Porteiro -Members "GG-Portaria-Mina"

# Matrícula para o vínculo automático com Pessoas
Set-ADUser rafael.pontes -EmployeeID "1001"
```

> Negue o logon local e por RDP à `svc-accessgate` via GPO (*Deny log on locally / through RDS*).

**LDAPS (porta 636):** o DC precisa de um certificado de servidor emitido pela CA interna (AD CS, modelo *Domain Controller Authentication* ou *Kerberos Authentication*).
Exporte o certificado **da CA raiz** em Base-64 (`.cer`/`.pem`) para configurar `LDAP_CA_CERT`.

## 3. Configuração

### 3.1 Pela tela (recomendado)

Em **Configurações → Active Directory / LDAP** (perfil admin):

1. Marque **Usar o Active Directory para login**. Os campos aparecem.
2. Preencha:
   - **Conexão:** servidores (`ldaps://dc01... ldaps://dc02...`), porta (0 = padrão), StartTLS, verificação do certificado e tempo limite.
   - **Certificado da CA interna:** cole o PEM. Ele é validado e gravado em `storage/certs/ldap-ca.pem`, que entra no backup.
   - **Diretório:** Base DN, OU dos usuários, DN da conta de serviço e senha da conta de serviço.
   - **Perfis:** provisionamento automático, perfil pelos grupos, grupos aninhados e os 5 grupos.
3. Deixe **Testar a conexão ao salvar** marcado e clique em **Salvar LDAP**. O resultado (tempo, contas encontradas, grupos encontrados) aparece no próprio cartão.

**Regras de segurança da tela:**
- A senha da conta de serviço é gravada **cifrada** no banco (libsodium, chave derivada da `CPF_ENCRYPTION_KEY`; se essa chave for trocada, digite a senha de novo). Ela nunca é exibida e nunca vai para a auditoria. Deixar o campo **em branco mantém a senha atual**.
- Os dados são validados antes de gravar: formato de servidor e DNs. LDAP sem criptografia é recusado, salvo com `LDAP_ALLOW_INSECURE=true` no `.env`. O provisionamento automático exige ao menos um grupo de perfil.
- Toda alteração gera o registro `LDAP_CONFIG` na Auditoria, com os parâmetros e sem a senha.
- **Desligar** mantém os parâmetros gravados, para religar depois com um clique. As contas locais continuam entrando, inclusive com o AD fora do ar.
- **Precedência:** o que foi salvo na tela vale sobre o `.env`. O rodapé do cartão indica a origem atual (*tela* ou *.env*).

### 3.2 Pelo `.env` do servidor (alternativa / padrão)

Os valores abaixo valem enquanto nada for salvo na tela. Atributos avançados (`LDAP_USER_FILTER`, `LDAP_ATTR_*`, `LDAP_ALLOW_INSECURE`) existem só no `.env`.

```ini
LDAP_ENABLED=true
LDAP_HOST=ldaps://dc01.salinasgold.local ldaps://dc02.salinasgold.local
LDAP_TLS_VERIFY=true
LDAP_CA_CERT=/home/accessgate/certs/ca-salinas.pem      # Windows: C:/certs/ca-salinas.pem
LDAP_BASE_DN=DC=salinasgold,DC=local
LDAP_USERS_BASE_DN=OU=Salinas,DC=salinasgold,DC=local   # opcional: limita a busca
LDAP_BIND_DN=CN=svc-accessgate,OU=Servicos,DC=salinasgold,DC=local
LDAP_BIND_PASSWORD=<senha da conta de serviço>
LDAP_AUTO_PROVISION=false   # true = cria no 1º login quem está em grupo mapeado
LDAP_SYNC_ROLES=false       # true = perfil sempre segue os grupos do AD
LDAP_GROUP_ADMIN=CN=GG-AccessGate-Admin,OU=Grupos,DC=salinasgold,DC=local
LDAP_GROUP_SUPERVISOR=CN=GG-AccessGate-Supervisor,OU=Grupos,DC=salinasgold,DC=local
LDAP_GROUP_SSMA=CN=GG-AccessGate-SSMA,OU=Grupos,DC=salinasgold,DC=local
LDAP_GROUP_PORTEIRO=CN=GG-AccessGate-Porteiro,OU=Grupos,DC=salinasgold,DC=local
LDAP_GROUP_SEG_PATRIMONIAL=CN=GG-AccessGate-SegPatrimonial,OU=Grupos,DC=salinasgold,DC=local
```

| Modo | Configuração | Quando usar |
|---|---|---|
| **Vínculo manual** (recomendado no início) | `AUTO_PROVISION=false`, `SYNC_ROLES=false` | O admin escolhe quem tem acesso e qual perfil. O AD só valida a senha e desliga quem sair |
| **Gestão pelo AD** | `AUTO_PROVISION=true`, `SYNC_ROLES=true` | O acesso é concedido e retirado só pelos grupos do AD, integrado ao processo de admissão e desligamento do RH |

**Pacotes:**
- Ubuntu: `apt install php8.3-ldap && systemctl reload php8.3-fpm`
- XAMPP: no `php.ini`, troque `;extension=ldap` por `extension=ldap`. O `INSTALAR-E-INICIAR.bat` já faz isso.

O servidor precisa resolver o nome do DC por DNS (o nome deve constar no certificado) e alcançar a porta **636/TCP**. Libere essa porta no Sophos, da DMZ/VM para os DCs.

## 4. Testes

```bash
php bin/console.php ldap-testar            # bind, contagem de contas e existência dos grupos
php bin/console.php ldap-buscar rafael     # pesquisa contas
php bin/console.php verificar              # inclui o status do AD
```

Também é possível testar pela tela **Configurações → Active Directory / LDAP → Testar conexão**, ou deixar marcado *Testar a conexão ao salvar*.

## 5. Uso no dia a dia

- **Novo usuário AD:** *Usuários → Novo* → **Active Directory** → busque por nome, usuário de rede, e-mail ou matrícula → defina perfil e áreas → **Criar**. Nome, e-mail e matrícula vêm do AD.
- **Converter conta local:** edite o usuário, troque para Active Directory e selecione a conta. Pela CLI: `php bin/console.php ldap-vincular --email=fulano@salinasgold.com.br --login=fulano`.
- **Voltar para local:** marque "Conta local". O sistema gera uma senha temporária com troca obrigatória.
- **Sincronizar na hora:** botão *Sincronizar agora com o AD* na ficha do usuário.

## 6. Cron (desligamentos)

```cron
15 0 * * *   php /home/accessgate/sag/bin/console.php ldap-sincronizar >> /home/accessgate/sag/storage/logs/cron.log 2>&1
```

O comando desativa no Access Gate as contas desativadas ou removidas no AD. Se `SYNC_ROLES=true`, desativa também quem foi retirado dos grupos. O sistema nunca desativa automaticamente o último admin ativo.
O código de saída é **2** quando há erro de comunicação, o que permite monitorar pelo Zabbix.

## 7. Problemas comuns

| Mensagem | Causa e solução |
|---|---|
| `Can't contact LDAP server ... certificado TLS` | O host está errado, a porta 636 está bloqueada, a CA não confere ou o nome usado em `LDAP_HOST` não está no certificado do DC. Teste com `openssl s_client -connect dc01.salinasgold.local:636 -showcerts` |
| `Invalid credentials` na conta de serviço | Senha incorreta ou expirada, ou conta bloqueada |
| "Usuário de rede ainda não cadastrado" | Com `AUTO_PROVISION=false`, o admin precisa vincular o usuário antes |
| "não pertence a nenhum grupo autorizado" | Inclua a pessoa no grupo `GG-AccessGate-*` correspondente |
| "Conexão LDAP sem criptografia bloqueada" | Use `ldaps://`, ou `LDAP_STARTTLS=true` na porta 389 |
| AD fora do ar | Os usuários do AD recebem um aviso amigável. Os admins locais entram normalmente |

**Validação:** testado contra um controlador de domínio Samba AD (LDAPS, `objectGUID`, `userAccountControl`, grupos aninhados com `LDAP_MATCHING_RULE_IN_CHAIN`). Foram 36 cenários automatizados, mais os testes de AD fora do ar, de CA inválida e de bloqueio de LDAP sem TLS.
