# Runbook — Deploy em Docker (Ubuntu Server)

**Documento:** MD-TI-SIS-CBA-005 · Rev. 00 · Salinas Access Gate
**Servidor:** `192.168.4.5` (Ubuntu Server) · **Porta:** `222/TCP` (HTTP)
**Pilha:** Docker Compose com `app` (PHP 8.3 + Apache) e `db` (MariaDB 10.11)
**Horário de referência:** Horário de Mato Grosso – UTC-4

---

## 1. Arquitetura

```
Navegador ──HTTP :222──► [app] PHP 8.3 + Apache ──rede interna──► [db] MariaDB 10.11
                           volume "arquivos"  (fotos, sessões, logs)  volume "banco"
```

| Item | Valor |
|---|---|
| Pasta no servidor | `/opt/salinas-access-gate` |
| Segredos | `docker/app.env` e `docker/db.env`, gerados na 1ª instalação com `chmod 600` |
| Banco | Não fica exposto na rede. Só o container `app` alcança o MariaDB |
| Migrações | Aplicadas automaticamente a cada início do container |
| Agendamentos | `/etc/cron.d/salinas-access-gate`: expira solicitações às 00:05 e faz backup à 01:30 |
| Backup | `/var/backups/salinas-access-gate`: banco, fotos e cópia do `app.env`, com retenção de 14 dias |

## 2. Pré-requisitos

- [ ] Ubuntu Server 22.04 ou 24.04 com acesso à internet. O instalador baixa o Docker e as imagens.
- [ ] Usuário com `sudo` (ex.: `admin`) e SSH ativo na porta 22.
- [ ] Porta **222** livre. O instalador verifica e aborta se outro serviço estiver usando a porta, por exemplo um SSH alternativo.
- [ ] No Windows: o *Cliente OpenSSH*, que já vem no Windows 10 e 11.

## 3. Instalação (1 clique no Windows)

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

- [ ] **Guardar as chaves:** copie `/opt/salinas-access-gate/docker/app.env` para o cofre de senhas. Sem as chaves `CPF_*` os CPFs ficam ilegíveis.
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

```bash
cd /opt/salinas-access-gate
gunzip -c /var/backups/salinas-access-gate/banco-AAAA-MM-DD-HHMM.sql.gz | \
  sudo docker compose exec -T db sh -c 'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE"'
sudo docker compose exec -T app tar -xzf - -C /var/www/html/storage < /var/backups/salinas-access-gate/fotos-AAAA-MM-DD-HHMM.tar.gz
```

Em um servidor novo, restaure também o `app.env`, que contém as mesmas chaves `CPF_*`, **antes** de subir a aplicação.

## 7. Riscos e limitações

| Risco | Tratamento |
|---|---|
| HTTP sem criptografia na porta 222 | Uso restrito à rede interna. Para acesso externo, coloque um proxy reverso com HTTPS (Nginx/Traefik + Let's Encrypt) e use `SESSION_SECURE_COOKIE=true` |
| Docker publica portas ignorando o UFW | Restrinja no Sophos o acesso à porta 222 à VLAN corporativa e às portarias |
| Backup no próprio servidor | Copie `/var/backups/salinas-access-gate` para o NAS ou outro local fora do servidor (ex.: rsync noturno) |
| Perda do `app.env` | Mantenha uma cópia no cofre. Cada backup também copia o arquivo |

## 8. Validação desta revisão

Instalação testada do zero em ambiente Docker:
- **Testes:** 77/77 dos testes ponta a ponta pela porta 222.
- **Persistência:** os dados continuam após `down`/`up`.
- **Reinstalação:** chaves, banco e admin preservados.
- **Backup:** banco, fotos e segredos copiados.
- **Conflito de porta:** detectado, e a instalação aborta.
- **Exposição:** `.env`, `docker/`, `bin/` e `storage/` inacessíveis pela web.
- **Banco:** porta não publicada na rede.
