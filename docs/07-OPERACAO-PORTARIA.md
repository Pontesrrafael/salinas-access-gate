# Operação da Portaria — guia do porteiro e da TI

**Documento:** MD-TI-SIS-CBA-007 · Rev. 01 · Salinas Access Gate
**Público:** porteiros, Segurança Patrimonial, supervisores e TI
**Horário de referência:** Horário de Mato Grosso – UTC-4

---

## 1. Resumo

| Recurso | O que muda para o porteiro |
|---|---|
| **Saída rápida e em lote** | Clique em **Saída** na lista *Dentro agora* (portaria) ou em *Quem está no site*. Para várias pessoas, marque e use **Saída dos selecionados** |
| **Atalhos de teclado** | Registra sem tirar a mão do teclado (seção 3) |
| **Alerta vermelho** | Documento vencido, falta de integração ou veículo sem comissionamento travam o botão até o porteiro confirmar a ciência |
| **QR do convite** | O visitante aprovado recebe um QR. Na portaria ele é lido pelo leitor USB, pela câmera ou por foto, e a pessoa e a solicitação são selecionadas sozinhas |
| **Sem conexão** | Se o servidor cair, a tela continua registrando. Os registros ficam guardados no computador e são enviados quando a conexão volta |

## 2. Saída rápida e em lote

- **Uma pessoa:** na lateral da portaria (*Dentro agora*), filtre pelo nome, empresa ou placa e clique em **Saída**.
- **Várias pessoas** (ônibus de turno, fim de expediente):
  1. Marque as caixas ou use *Todos visíveis*.
  2. Clique em **Saída dos selecionados** e confirme.
- **Veículo:** o veículo com que a pessoa entrou sai junto, uma única vez por lote.
- **Rastreabilidade:** cada saída é um registro normal, com a observação *Saída rápida* ou *Saída em lote (N pessoas)*. O lote também é auditado como `SAIDA_EM_LOTE`.
- **Quem pode:** porteiro e admin. Supervisor e SSMA veem a lista sem os botões.

## 3. Atalhos de teclado (tela Portaria)

| Tecla | Ação |
|---|---|
| `F2` | Buscar pessoa (com uma pessoa selecionada, troca) |
| `F3` | Ler QR pela câmera ou por foto |
| `F4` | Buscar veículo |
| `F8` / `F9` | Entrada / Saída. Fora de campos de texto, também `E` / `S` |
| `Ctrl` + `Enter` | Confirmar. Com alerta vermelho, leva até a caixa de ciência; marque com `Espaço` e repita |
| `Esc` | Fecha a lista de resultados; de novo, limpa tudo para a próxima pessoa |
| `?` | Mostra a ajuda de atalhos |

`F2`, `F3`, `F4`, `F8` e `F9` foram escolhidas porque o Chrome não usa essas teclas. As combinações com `Alt` foram evitadas porque abrem o menu do navegador no Windows.

## 4. Alerta vermelho (confirmação obrigatória)

| Situação | Comportamento |
|---|---|
| Terceiro com documento vencido | **Bloqueia** (como antes) |
| Colaborador ou visitante com documento vencido (o mais recente de cada tipo) | Alerta vermelho |
| Visitante/terceiro **sem integração vigente** entrando em local do tipo **mina** | Alerta vermelho. Pode ser desligado em *Configurações → Regras da portaria* |
| Veículo **sem comissionamento** ou com comissionamento vencido na mina | Alerta vermelho |

O botão só libera depois de marcada a caixa *Conferi e autorizo a entrada mesmo assim*. O servidor também exige a confirmação: um envio sem ela é recusado.

A confirmação fica gravada nas **observações do registro** (`[Alertas confirmados pelo porteiro: …]`) e na auditoria (`ALERTA_CONFIRMADO`). Um bipe curto avisa quando o alerta aparece.

## 5. QR do convite

1. **Gerar:** em *Solicitações*, nas aprovadas de hoje em diante, clique em **Convite QR**. A página tem três opções:
   - **Imprimir**;
   - **Copiar link do convite**;
   - **Enviar pelo WhatsApp**.
2. **O que o visitante vê:** o link `/convite/<id>/<assinatura>` mostra só nome, data e local, sem login e sem documento. O link deixa de abrir depois da data ou se a solicitação deixar de estar aprovada.
3. **Ler na portaria:**
   - **Leitor USB:** com a tela da portaria aberta, basta ler o QR. Não é preciso clicar em nenhum campo.
   - **Câmera:** botão **Ler QR** ou `F3`. A câmera ao vivo exige **HTTPS**. Em HTTP, use **Tirar foto do QR**, que é lida no próprio navegador. Também dá para digitar o código.
4. **O que acontece na leitura:**
   - O sistema confere a **assinatura (HMAC)**: um QR adulterado é recusado e auditado como `QR_INVALIDO`.
   - Em seguida, confere se a solicitação está **aprovada para hoje**.
   - Por fim, procura a pessoa pelo vínculo, pelo CPF ou pelo nome exato.
5. **Primeira visita:** se a pessoa ainda não tem cadastro, abre o **Cadastro rápido** já preenchido. Ao concluir, a entrada segue com a solicitação do convite.

O QR **não dispensa** as demais regras (SSMA, documentos, alertas). Cada leitura é auditada (`QR_LIDO`).

## 6. Sem conexão com o servidor

**O que o porteiro vê:** uma faixa **vermelha** no topo e o botão *GUARDAR ENTRADA/SAÍDA (sem conexão)*.

**Como funciona:**
1. A cada 10 minutos a tela guarda no navegador um **cadastro local**: pessoas ativas (sem CPF, telefone ou e-mail), veículos, solicitações aprovadas de hoje e quem está dentro do local.
2. Sem conexão, a busca usa esse cadastro (por nome, matrícula, empresa ou placa). Documento vencido e falta de solicitação viram alerta vermelho.
3. O registro vai para uma **fila local** (IndexedDB) com a hora real (UTC-4) e as fotos.
4. A cada 15 s a tela testa o servidor. Quando a conexão volta, a fila é enviada na ordem:
   - o identificador de cada registro, gerado no navegador, impede duplicidade;
   - a hora original é mantida, até 72 h;
   - o registro recebe a observação `[Registrado SEM CONEXÃO …]`;
   - o que o servidor bloquearia vira `[Pendências na sincronização: …]` e auditoria `REGISTRO_OFFLINE`, para o supervisor conferir. A presença física já aconteceu, por isso não se descarta o registro.
5. Se a sessão tiver expirado, a faixa pede novo login. A fila continua guardada e é enviada no próximo acesso.

**Cuidados e limites:**

| Item | Detalhe |
|---|---|
| **Não recarregar a página sem conexão** | Em **HTTP** (porta 222 hoje), o navegador não guarda a tela. A fila sobrevive, mas a tela só abre de novo com o servidor no ar |
| **Com HTTPS** | Um service worker (`/sw.js`) guarda a tela e os arquivos, e a portaria abre mesmo com o servidor fora. A câmera ao vivo também passa a funcionar |
| **Busca por CPF** | Só online. O cadastro local não guarda CPF (LGPD) |
| **Cadastro rápido** | Só online |
| **Sair do sistema** | Apaga o cadastro local do posto. Se houver fila pendente, o sistema avisa antes |
| **Quando ajuda** | Quando o caminho entre o posto e o servidor cai (rede, switch, servidor reiniciando). Com servidor e portaria na mesma LAN, a queda da internet não afeta |

## 7. Fotos e anexos (celulares Samsung)

| Item | Regra |
|---|---|
| **Formatos** | JPG, PNG, WEBP, **HEIC/HEIF** e **RAW (DNG)**. HEIC é o padrão da câmera Samsung com *Fotos de alta eficiência* ligado; DNG sai do modo **Pro** com *Cópias RAW* ligado |
| **Tamanho** | Até **25 MB por foto** e **100 MB por entrada/saída**, no máximo 8 fotos. Configurável no `.env`: `UPLOAD_MAX_MB`, `UPLOAD_MAX_TOTAL_MB`, `UPLOAD_MAX_FILES` |
| **HEIC** | O servidor converte para **JPEG**, já girado, para abrir em qualquer navegador. Usa o pacote `libheif`, que já vem na imagem Docker. O nome original do arquivo é guardado. Sem o conversor (XAMPP), o HEIC fica guardado como arquivo para baixar |
| **RAW (DNG)** | Guardado como está, sem recodificar, e aparece no registro como **arquivo para baixar**. Abre em visualizadores de RAW (Lightroom, Galeria Samsung ou Windows Fotos com a extensão de imagem RAW) |
| **Privacidade** | GPS, modelo do aparelho e outros metadados são removidos de todos os formatos. No RAW e no HEIC sem conversão, o bloco de GPS é esvaziado sem alterar a imagem |
| **Conferência** | O tipo é conferido pelo conteúdo, não pela extensão. Um `.jpg` que na verdade é HEIC é aceito; um arquivo qualquer renomeado para `.heic` ou `.dng` é recusado |

**Fotos guardadas no computador (rascunho e fila)**
- **Anexou, ficou guardado:** cada foto anexada já fica salva no navegador do posto. Se a página fechar, recarregar ou a conexão cair antes de confirmar, as fotos voltam ao abrir a portaria de novo.
- **Sem internet ou queda no meio do envio:** o registro e as fotos vão para a fila e sobem sozinhos quando a conexão volta. A faixa no topo mostra o progresso (*Enviando registro 1 de 3 — 45%*).
- **Envio online:** o botão mostra o percentual (*Enviando fotos… 60%*), útil com fotos grandes em LTE (4G) ou Starlink.
- **Sair do sistema:** a fila pendente é mantida e o sistema avisa antes de sair.

> Dica para o posto: com o celular da portaria, prefira JPEG (*Câmera → Configurações → Formato da foto → Fotos de alta eficiência* desligado). Fica menor e sobe mais rápido. Use RAW só quando precisar de evidência com máxima qualidade.

## 8. Validação desta revisão

- **Fotos Samsung (Rev. 01):**
  - formatos e limites: 18/18 (HEIC→JPEG sem GPS e girado, DNG sem GPS e íntegro, limites de 25/100 MB, arquivos disfarçados recusados);
  - interface: 10/10 (rascunho após recarregar, fila offline com 3 formatos sincronizada, envio de 20 MB com progresso).
- **Testes ponta a ponta:** 81/81. Atualizados para o alerta vermelho.
- **Pacote Portaria:** 49/49. Cobrem:
  - saída rápida e em lote com veículo;
  - permissões;
  - QR válido, adulterado e de outro dia;
  - convite público;
  - fila offline com hora real, duplicidade e limites de 72 h e de horário futuro;
  - configuração do alerta.
- **Interface (Chromium):** 29/29. Cobrem:
  - atalhos;
  - alerta vermelho e ciência;
  - QR por leitor USB simulado e por foto (jsQR);
  - saída com 1 clique;
  - queda e volta da conexão com sincronização;
  - QR desenhado no convite;
  - celular sem rolagem horizontal;
  - nenhum erro de JavaScript.
- **Regressão:** os testes da configuração do LDAP pela tela (31/31) continuam passando.
- **Bibliotecas** (servidas localmente, sem CDN, compatíveis com a CSP):
  - `qrcode-generator` 1.4.4 (MIT);
  - `jsQR` 1.4.0 (Apache-2.0, licença em `public/assets/js/vendor`).
