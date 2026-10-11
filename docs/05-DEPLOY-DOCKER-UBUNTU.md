# Runbook — Deploy em Docker (Ubuntu Server)

**Documento:** MD-TI-SIS-CBA-005 · Rev. 02 · Salinas Access Gate
**Servidor:** `192.168.4.5` (Ubuntu Server) · **Porta:** `222/TCP` (HTTP)
**Pilha:** Docker Compose com `app` (PHP 8.5 + Apache) e `db` (MariaDB 11.8 LTS)
**Horário de referência:** Horário de Mato Grosso – UTC-4

---

## 1. Arquitetura

```
Navegador ──HTTP :222──► [app] PHP 8.5 + Apache ──rede interna──► [db] MariaDB 11.8
                           volume "arquivos"  (fotos, sessões, logs)  volume "banco"
```

| Item | Valor |
|---|---|
| Pasta no servidor | `/opt/salinas-access-gate` |
| Segredos | `docker/app.env`, `docker/db.env` e `docker/bi.env`, gerados na 1ª instalação com `chmod 600` |
| Banco | Não fica exposto na rede por padrão. Só o container `app` alcança o MariaDB |
| BI | Usuário `sag_bi` (somente leitura, só views `bi_*`), criado em toda instalação. Publicação opcional com `SAG_BI_PORTA=3306` (ver MD-TI-SIS-CBA-006) |
| Migrações | Aplicadas automaticamente a cada início do container |
| Agendamentos | `/etc/cron.d/salinas-access-gate`: expira solicitações às 00:05 e faz backup à 01:30 |
| Backup | `/var/backups/salinas-access-gate`: banco, fotos, certificado da CA do AD e cópias do `app.env` e do `bi.env`, **cifrados** com `docker/backup.chave`, com retenção de 14 dias |
| Senhas do banco | Escolhidas na instalação. Troca: `sudo bash docker/alterar-senha-banco.sh app\|root\|bi` |
| LGPD | Retenção mensal (`lgpd-retencao`, dia 1 às 02:15). Ver MD-TI-SIS-CBA-008 |

## 2. Pré-requisitos

- [ ] Ubuntu Server 22.04 ou 24.04 com acesso à internet. O instalador baixa o Docker e as imagens.
- [ ] Usuário com `sudo` (ex.: `admin`) e SSH ativo na porta 22.
- [ ] Porta **222** livre. O instalador verifica e aborta se outro serviço estiver usando a porta, por exemplo um SSH alternativo.
- [ ] No Windows: o *Cliente OpenSSH*, que já vem no Windows 10 e 11.

## 3. Instalação direta pelo GitHub (no console do Ubuntu)

O repositório é **privado**. Por isso o download usa um **token de leitura** do GitHub, que você digita na hora e que não fica gravado no histórico.

**Criar o token (uma vez):**
1. GitHub → *Settings* → *Developer settings* → *Personal access tokens* → *Fine-grained tokens* → *Generate new token*.
2. *Expiration*: 7 dias.
3. *Repository access*: *Only select repositories* → `salinas-access-gate`.
4. *Permissions* → *Contents*: **Read-only**. Gere o token e copie.

**Instalar ou atualizar:** cole no terminal do servidor.

```bash
read -rsp "Token GitHub: " GT && echo && rm -rf /tmp/sag && mkdir -p /tmp/sag && curl -fSL -H "Authorization: Bearer $GT" https://api.github.com/repos/Pontesrrafael/salinas-access-gate/tarball/main | tar -xz -C /tmp/sag --strip-components=1 && unset GT && sudo SAG_PORTA=222 bash /tmp/sag/docker/instalar.sh /tmp/sag
```

O comando baixa a versão mais recente do branch `main` e executa `docker/instalar.sh`. Para atualizar, rode o mesmo comando: segredos, banco e fotos são preservados. Revogue o token depois do uso.

## 3.2 Instalação pelo PuTTY (passo a passo)

1. **Conectar:** abra o PuTTY e preencha *Host Name* `192.168.4.5`, *Port* `22`, *SSH*. Clique em *Open*. No 1º acesso, aceite a chave do servidor.
   Faça login como `administrator`. A senha não aparece enquanto você digita.
2. **Pré-checagem:** `sudo -v && curl -sI https://github.com | head -1 && sudo ss -ltnp | grep ':222 ' || echo "porta 222 livre"`.
3. **Código, opção A (GitHub):** cole com o botão direito do mouse o comando da seção 3 e informe o token quando ele for pedido.
4. **Código, opção B (sem token):** no Prompt de Comando do Windows:
   `tar -czf %TEMP%\sag-deploy.tgz --exclude=.git --exclude=.env --exclude=storage --exclude=app.env --exclude=db.env -C %USERPROFILE%\Documents\salinas-access-gate .`
   `"C:\Program Files\PuTTY\pscp.exe" %TEMP%\sag-deploy.tgz administrator@192.168.4.5:/tmp/`
   Depois, no PuTTY:
   `rm -rf /tmp/sag && mkdir -p /tmp/sag && tar -xzf /tmp/sag-deploy.tgz -C /tmp/sag && sed -i 's/\r$//' /tmp/sag/docker/*.sh && sudo SAG_PORTA=222 bash /tmp/sag/docker/instalar.sh /tmp/sag`
5. **Banco de dados (1ª instalação):** informe o usuário da aplicação (Enter = `sag_app`), a senha desse usuário e a senha do root do MariaDB.
   - A senha deve ter de 12 a 64 caracteres, com maiúscula, minúscula e número, e não pode ter espaço, aspas, `$`, `#` ou `\`.
   - Enter gera uma senha forte.
   - Guarde tudo no cofre (MD-TI-SIS-CBA-008, seção 2).
6. **Administrador:** confirme o e-mail e o nome e aperte *Enter* na senha. Anote a senha temporária exibida.
7. **Validação:** `curl -s http://127.0.0.1:222/saude` deve retornar `"status":"ok"`, e `sudo docker compose -f /opt/salinas-access-gate/docker-compose.yml ps` deve mostrar app e db como *healthy*.
   No navegador, abra `http://192.168.4.5:222` e entre com o admin; o sistema pede a troca da senha.

## 3.1 Instalação a partir do Windows (1 clique)

1. Na pasta do projeto, dê dois cliques em **`bin\INSTALAR-NO-SERVIDOR.bat`**.
   - Para outro servidor, use `INSTALAR-NO-SERVIDOR.bat IP USUARIO PORTA`.
2. Confirme com **S**.
3. Digite a **senha do servidor** quando o envio (`scp`) pedir.
4. Digite a senha do servidor de novo na instalação (`ssh`) e depois a do **sudo**.
5. Na primeira vez, informe o e-mail e o nome do administrador. Na pergunta da senha, aperte **Enter**: o sistema gera uma senha temporária, mostra no terminal e exige a troca no primeiro acesso.
6. No fim, o navegador abre em `http://192.168.4.5:222`.

> A senha do servidor é digitada só por você, nas janelas do SSH. Ela não fica gravada em nenhum arquivo.

### Alternativa manual (direto no servidor)

```bash
# envie o pacote (ex.: WinSCP) e no servidor:
mkdir -p /tmp/sag && tar -xzf sag-deploy.tgz -C /tmp/sag
sudo SAG_PORTA=222 bash /tmp/sag/docker/instalar.sh /tmp/sag
```

## 4. Pós-instalação (obrigatório)

- [ ] **Guardar as chaves:** copie para o cofre de senhas os arquivos `/opt/salinas-access-gate/docker/app.env`, `db.env`, `bi.env` e **`backup.chave`**. Sem as chaves `CPF_*` os CPFs ficam ilegíveis; sem a `backup.chave` os backups não abrem.
- [ ] **Privacidade:** em *Configurações → Privacidade (LGPD)*, informe o encarregado (DPO) e confira os prazos de retenção.
- [ ] **AD (opcional):** ligue e configure pela tela *Configurações → Active Directory / LDAP* (MD-TI-SIS-CBA-004).
- [ ] **BI (opcional):** para Power BI ou Metabase em outra máquina, reinstale com `SAG_BI_PORTA=3306` e restrinja a porta no Sophos (MD-TI-SIS-CBA-006).
- [ ] **Primeiro acesso:** entre com o admin e troque a senha temporária.
- [ ] **Configurações:** ajuste as áreas, os locais e o `alerta_permanencia_horas` (turnos de 12 h pedem 14 h).
- [ ] **Monitoramento:** cadastre no Zabbix o *web scenario* `http://192.168.4.5:222/saude`, esperando HTTP 200 e `"status":"ok"`.
- [ ] **Segurança da conta:** troque a senha do usuário `admin` do Linux e prefira chave SSH a senha.

## 5. Operação

```bash
cd /opt/salinas-access-gate
sudo docker compose ps                     # estado (app e db devem estar "healthy")
sudo docker compose logs -f app            # logs do Apache/PHP
sudo docker compose restart app            # reinício
sudo docker compose exec app php bin/console.php verificar
sudo docker compose exec app php bin/console.php redefinir-senha --email=fulano@salinasgold.com.br
sudo /opt/salinas-access-gate/docker/backup.sh   # backup sob demanda
```

**Atualização de versão:** rode o mesmo `INSTALAR-NO-SERVIDOR.bat`. Ele preserva os segredos, o banco e as fotos, reconstrói a imagem e aplica as migrações novas. Faça um backup antes.

## 6. Restauração

Os arquivos de backup são cifrados. Para abrir, use a chave `docker/backup.chave`, que está no servidor e no cofre:

```bash
cd /opt/salinas-access-gate
K=docker/backup.chave; B=/var/backups/salinas-access-gate; D=AAAA-MM-DD-HHMM
openssl enc -d -aes-256-cbc -pbkdf2 -iter 200000 -pass file:$K -in $B/banco-$D.sql.gz.enc | gunzip | \
  sudo docker compose exec -T db sh -c 'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE"'
openssl enc -d -aes-256-cbc -pbkdf2 -iter 200000 -pass file:$K -in $B/fotos-$D.tar.gz.enc | \
  sudo docker compose exec -T app tar -xzf - -C /var/www/html/storage
openssl enc -d -aes-256-cbc -pbkdf2 -iter 200000 -pass file:$K -in $B/app.env-$D.enc > /tmp/app.env.restaurado   # só em servidor novo
```

Em um servidor novo, primeiro copie do cofre o `app.env`, que contém as mesmas chaves `CPF_*`, e a `backup.chave`. Só depois suba a aplicação.

## 7. Riscos e limitações

| Risco | Tratamento |
|---|---|
| HTTP sem criptografia na porta 222 | Uso restrito à rede interna. Para acesso externo, coloque um proxy reverso com HTTPS (Nginx/Traefik + Let's Encrypt) e use `SESSION_SECURE_COOKIE=true`. Com HTTPS, a portaria também ganha a câmera ao vivo para o QR e a abertura da tela sem conexão (MD-TI-SIS-CBA-007) |
| Docker publica portas ignorando o UFW | Restrinja no Sophos o acesso à porta 222 à VLAN corporativa e às portarias, e a porta do BI (se publicada) ao IP do gateway de BI |
| Banco publicado para BI | Opcional. O `sag_bi` só lê views sem dados sensíveis, tem no máximo 5 conexões e 120 s por consulta, e o `root@'%'` é removido |
| Backup no próprio servidor | Copie `/var/backups/salinas-access-gate` para o NAS (ex.: rsync noturno). Os arquivos já saem cifrados; **não** copie a `backup.chave` junto |
| Perda do `app.env` | Mantenha uma cópia no cofre. Cada backup também copia o arquivo |

## 8. Validação desta revisão

Instalação testada do zero em ambiente Docker, inclusive a partir do pacote baixado do GitHub:
- **Testes:** 77/77 dos testes ponta a ponta pela porta 222.
- **Persistência:** os dados continuam após `down`/`up`.
- **Reinstalação:** chaves, banco e admin preservados.
- **Backup:** banco, fotos e segredos copiados.
- **Conflito de porta:** detectado, e a instalação aborta.
- **Falha no Docker Hub:** se a consulta falhar, por exemplo por limite de downloads (erro 429), o instalador usa as imagens já baixadas.
- **Exposição:** `.env`, `docker/`, `bin/` e `storage/` inacessíveis pela web.
- **Banco:** porta não publicada na rede (padrão).
- **Rev. 01:** atualização de uma instalação existente para a migração 004. Usuário `sag_bi` com acesso só às views. Porta de BI publicada e root remoto recusado. Estrutura das tabelas idêntica no MariaDB 11.8.
