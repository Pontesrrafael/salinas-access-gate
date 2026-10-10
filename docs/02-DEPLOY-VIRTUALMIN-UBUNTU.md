# Runbook — Deploy no Virtualmin (Ubuntu)

**Documento:** MD-TI-SIS-CBA-002 · Rev. 00 · Salinas Access Gate
**Domínio:** `accessgate.mycode.app.br`
**Ambiente:** Ubuntu 22.04/24.04 LTS · Webmin/Virtualmin · Apache + PHP-FPM · MariaDB
**Responsável:** Analista de TI · **Horário de referência:** Horário de Mato Grosso – UTC-4

---

## 0. Checklist pré-deploy

- [ ] Homologação local concluída (roteiro do documento MD-TI-SIS-CBA-001, item 6)
- [ ] DNS `accessgate.mycode.app.br` → IP público do servidor (registro A), propagado
- [ ] Portas 80/443 liberadas no firewall/Sophos para o servidor
- [ ] Chaves `CPF_ENCRYPTION_KEY` e `CPF_INDEX_KEY` guardadas em cofre (as mesmas da importação, se houver dados)
- [ ] Janela de manutenção comunicada às portarias (se houver migração do Lovable)

## 1. Pacotes do servidor (SSH como root)

```bash
apt update
apt install -y php8.3-fpm php8.3-mysql php8.3-mbstring php8.3-curl php8.3-intl php8.3-ldap   # Ubuntu 24.04 (ldap: opcional, p/ AD)
# Ubuntu 22.04 traz PHP 8.1 (sem suporte desde 01/2026): use Ubuntu 24.04 ou o PPA ondrej/php (php8.4-*)
php -m | grep -Ei "sodium|pdo_mysql|fileinfo|mbstring"   # sodium e fileinfo já vêm no php-common
timedatectl set-timezone America/Cuiaba                  # cron e logs no Horário de Mato Grosso – UTC-4
```

## 2. Criar o Virtual Server

Em **Virtualmin → Create Virtual Server**:

| Campo | Valor |
|---|---|
| Domain name | `accessgate.mycode.app.br` |
| Administration username | `accessgate` |
| Features | Apache website · SSL website · MariaDB database |
| PHP execution mode | **FPM**, versão 8.2 ou superior; prefira 8.4 (*Web Configuration → PHP Options*) |

O Virtualmin cria o usuário Linux `accessgate`, a pasta `/home/accessgate/public_html` e um banco `accessgate`.
O usuário do banco é `accessgate`, com a mesma senha do servidor virtual.

## 3. Publicar o código

```bash
su - accessgate
mkdir -p ~/sag && cd ~/sag
# Opção A (repositório Git): git clone <url-do-repositorio-php> .
# Opção B (arquivo): envie salinas-access-gate.zip por SFTP e extraia:
#   unzip ~/salinas-access-gate.zip -d ~/sag && mv ~/sag/salinas-access-gate/* ~/sag/salinas-access-gate/.[!.]* ~/sag/ 2>/dev/null
```

Para o DocumentRoot apontar para `~/sag/public`, a forma mais simples é trocar `public_html` por um link simbólico:

```bash
mv ~/public_html ~/public_html.original
ln -s ~/sag/public ~/public_html
```

A alternativa é alterar o diretório em *Virtualmin → Web Configuration → Website Options → Website documents directory* para `sag/public`.

> Nunca aponte o DocumentRoot para `~/sag`: `.env`, `storage/` e `app/` não podem ficar acessíveis pela web.

## 4. Configurar `.env`, permissões e banco

```bash
cd ~/sag
cp .env.example .env && chmod 600 .env
nano .env
```

```ini
APP_ENV=production
APP_DEBUG=false
APP_URL=https://accessgate.mycode.app.br
DB_HOST=localhost
DB_DATABASE=accessgate
DB_USERNAME=accessgate
DB_PASSWORD=<senha do servidor virtual>
SESSION_SECURE_COOKIE=true
# Se houver proxy reverso/Cloudflare na frente: TRUSTED_PROXIES=<IP do proxy>
```

```bash
# Chaves: use as MESMAS chaves do ambiente local se for importar dados já cifrados.
#   - Ambiente novo:       php bin/console.php gerar-chaves --gravar
#   - Copiar do local:     cole CPF_ENCRYPTION_KEY e CPF_INDEX_KEY no .env
chmod -R u=rwX,g=,o= storage
php bin/console.php migrar
php bin/console.php criar-admin --email=ti@salinasgold.com.br --nome="TI Salinas"
php bin/console.php verificar
```

> **Triggers:** o usuário de banco do Virtualmin recebe `ALL PRIVILEGES` no seu banco, o que inclui `TRIGGER`. Se o MariaDB estiver com *binary log* ligado e o `migrar` falhar na criação das triggers, rode como root: `SET GLOBAL log_bin_trust_function_creators = 1;` (e persista em `/etc/mysql/mariadb.conf.d/50-server.cnf`).

## 5. SSL (Let's Encrypt) e HTTPS obrigatório

1. Em *Virtualmin → Server Configuration → SSL Certificate → Let's Encrypt*, peça o certificado para `accessgate.mycode.app.br`. A renovação é automática.
2. Em *Web Configuration → Website Redirects*, ative o redirecionamento HTTP → HTTPS.
3. Teste: `curl -I https://accessgate.mycode.app.br/saude` deve retornar `200` com o cabeçalho `Strict-Transport-Security`.

## 6. Limites de PHP

O arquivo `public/.user.ini` já define `upload_max_filesize = 8M`, `post_max_size = 72M` e `max_file_uploads = 20` (até 8 fotos de 8 MB por registro).
Ele é lido pelo PHP-FPM em até 5 minutos. Para aplicar na hora: `systemctl reload php8.3-fpm`.

## 7. Tarefas agendadas

Em *Virtualmin → System Settings → Scheduled Cron Jobs* (usuário `accessgate`):

```cron
5 0 * * *   php /home/accessgate/sag/bin/console.php expirar-solicitacoes >> /home/accessgate/sag/storage/logs/cron.log 2>&1
15 0 * * *  php /home/accessgate/sag/bin/console.php ldap-sincronizar     >> /home/accessgate/sag/storage/logs/cron.log 2>&1   # só se LDAP_ENABLED=true
```

Integração com o AD (opcional): veja o documento MD-TI-SIS-CBA-004 (`docs/04-INTEGRACAO-AD-LDAP.md`).

## 8. Backup

| Item | Como | Retenção sugerida |
|---|---|---|
| Banco + arquivos | *Virtualmin → Backup and Restore → Scheduled Backups*: domínio `accessgate`, diário, destino externo (NAS/SFTP/S3) | 30 diários + 12 mensais |
| Dump lógico extra | `mysqldump --single-transaction --routines --triggers accessgate \| gzip > ~/backup/sag-$(date +\%F).sql.gz` no cron | 14 dias |
| Fotos | `~/sag/storage/uploads` (incluso no backup do Virtualmin) | igual ao banco |
| **Chaves do CPF** | cópia do `.env` em cofre de senhas, **fora do servidor** | permanente |

> Restaurar o banco sem as chaves `CPF_*` deixa os CPFs ilegíveis. Teste a restauração a cada trimestre.

## 9. Monitoramento (Zabbix)

- **Web scenario:** `GET https://accessgate.mycode.app.br/saude`. Espera HTTP `200` e a string `"status":"ok"`. Intervalo de 1 minuto. Alerta depois de 3 falhas.
- **Certificado:** item `web.certificate.get` ou template *Website certificate by Zabbix agent 2*, com alerta em até 14 dias para vencer.
- **Logs:** `~/sag/storage/logs/app.log` (erros com código de referência) e `~/logs/` do Apache (gerenciados pelo Virtualmin).

## 10. Endurecimento (recomendado)

1. **Privilégios mínimos no banco:** crie um usuário só para a aplicação e aplique `database/grants.sql`. Com isso a aplicação fica sem UPDATE/DELETE nas tabelas imutáveis. Use o usuário `accessgate` apenas para rodar `migrar`.
2. **Fail2ban:** jails para `sshd`, `webmin-auth` e `apache-auth`, que já vêm no Virtualmin.
3. **Webmin:** restrinja a porta 10000 à VPN e aos IPs da TI.
4. **Rotação de logs:** `storage/logs/*.log` no logrotate, com rotação semanal e 8 cópias.

## 11. Atualização de versão

```bash
cd ~/sag
php bin/console.php verificar                 # estado antes
# backup do banco (item 8) ANTES de atualizar
git pull        # ou envie os arquivos novos, preservando .env e storage/
php bin/console.php migrar                    # aplica migrações novas (idempotente)
php bin/console.php verificar
```

**Rollback:** volte o código para a versão anterior (`git checkout <tag>` ou restaure a pasta) e restaure o dump do banco feito antes da atualização.

## 12. Aceite pós-deploy

- [ ] `/saude` retorna ok por HTTPS
- [ ] Login do admin + troca de senha
- [ ] Entrada e saída de teste na Portaria Mina (com foto pela câmera do tablet/celular)
- [ ] Formulário público `/solicitar-acesso` abre sem login
- [ ] Cron executado (ver `storage/logs/cron.log` no dia seguinte)
- [ ] Backup agendado executado e testado
- [ ] Zabbix monitorando `/saude` e o certificado
