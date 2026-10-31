# Changelog

## 1.14.0

- A lista de conversas passa a agrupar por quem esta esperando: "Esperando voce" (precisa
  resposta), "Adiadas" e "Esperando o contato". Antes a coluna era uma pilha por relogio, e
  descobrir onde o atendente travava a fila exigia abrir conversa por conversa. Os tres grupos
  saem de `needs_response` e `lifecycle`, que o servidor ja mandava, entao nenhuma ponta pede
  dado novo. Uma conversa adiada sai do "esperando voce" mesmo devendo resposta: ela volta
  sozinha na hora marcada, e manter no topo algo que alguem ja decidiu adiar seria pedir a
  mesma decisao duas vezes. O selo "aguardando resposta" da linha desaparece quando repete o
  cabecalho do grupo e fica onde acrescenta algo, numa adiada que ainda deve resposta.
- A barra de cima para de repetir o titulo da pagina. O `headerTitle` do Mautic ja mostra
  "caixa de entrada de suporte" com a mesma chave de traducao, e a barra gastava 76 px para
  dizer de novo; agora tem 52 px e guarda o que a pagina nao diz, de que caixa se trata e se a
  atualizacao esta viva. A area de trabalho cresce 24 px em toda tela.
- O cinza dos textos secundarios passa de `#858b96` para `#646b78`. O tom antigo dava 3,4:1
  contra o branco em textos de 12 px, abaixo do minimo de 4,5:1; o novo da 5,4:1.
- O painel do contato ganha o atalho para o perfil publico do participante. O endereco ja
  vinha no payload da conversa (`profile_url`), montado e validado por `SocialProfileLinks`, e
  so o aplicativo o oferecia. A validacao se repete no navegador porque a tela tambem le
  conversa guardada em cache, de uma versao anterior do servidor.
- Gravar e enviar audio pelo navegador, nas conversas em que o canal aceita.
  `POST /inbox/api/conversations/{id}/audio` guarda o arquivo e o envio passa a aceitar
  `audio_id`, com as mesmas recusas que o aplicativo ja aplicava: interruptor da instalacao
  ligado, sessao de WhatsApp por QR, comentario nao, e o atendimento assumido por quem envia.
  Guardar e enviar sao duas chamadas com o MESMO `request_id`, que e como o store reconhece o
  reenvio do mesmo audio numa reconexao em vez de guardar outro. O botao so aparece onde o
  canal aceita E o navegador sabe gravar em MPEG-4, porque o servidor so aceita esse formato —
  gravar WebM daria uma recusa depois de a pessoa ja ter falado.
- A aba das sessoes de IA passa a se chamar "Agentes ativos". "Sessao" ficou ambiguo no plugin:
  a 1.13.0 chama de sessao a conversa do OPERADOR com o assistente, guardada no servidor, e
  esta aba e outra coisa — o agente de IA conversando com um CONTATO. A chave de traducao
  continua a mesma; so o texto mudou.
- A fila das sessoes do assistente vira uma aba. Ate aqui o estado do agente so existia
  conversa a conversa: para saber que um agente parou esperando uma pessoa era preciso abrir a
  conversa onde isso aconteceu, ou seja, saber antes o que so se descobre depois.
  `GET /inbox/api/ai/sessions` devolve as atribuicoes vivas com agente, contagem, limite e
  motivo, e a tela separa em duas secoes — quem pede gente e quem esta respondendo —, porque
  misturar os dois numa lista ordenada faria reler tudo para achar o que importa. O contador da
  aba vem junto e carrega na montagem, entao o aviso existe sem abrir nada. Sessao de conversa
  apagada, ou que o operador nao pode ver, nao entra: a checagem e a mesma da conversa.
- O painel do contato ganha o "nao enviar e-mails", o mesmo DoNotContact de e-mail que o
  aplicativo ja grava, agora por `POST /inbox/api/conversations/{id}/dnc`. Exige edicao do
  contato, nao so do atendimento: ver uma conversa nunca foi permissao para silenciar quem esta
  do outro lado. Liberar remove so o registro MANUAL, entao um descadastro do proprio contato e
  um retorno permanente sobrevivem e voltam como nao editaveis, com o motivo no lugar do
  controle. A regra mora em `Application/ContactDoNotContact.php` para nao virar uma segunda
  copia da que o lado do aplicativo tem.
- Favoritar e copiar por mensagem, as duas acoes que o aplicativo tem e o web nao tinha. O
  favorito e do navegador porque no servidor ele nao existe — no aplicativo tambem e do
  aparelho —, e a chave leva o usuario para duas pessoas no mesmo navegador nao herdarem a
  marcacao uma da outra.
- Selecao em massa na lista, com adiar e resolver nas conversas e spam e bloquear autor nos
  comentarios. As acoes vao uma de cada vez: cada conversa tem a sua `version`, e disparar tudo
  em paralelo faz as respostas voltarem fora de ordem e produzirem 409 sobre conversa que ja
  mudou. O que falha falha sozinho e as outras seguem, com a conta dos que nao deram certo.
- Ditar a resposta no editor, pelo reconhecimento de fala do navegador. O botao so aparece onde
  o navegador oferece a API, e o ditado acrescenta ao que ja esta escrito em vez de trocar o
  campo. Gravar e enviar um audio NAO vem junto: o servidor so aceita audio em sessao de
  WhatsApp por QR e o editor do navegador nao tem caminho de upload, entao o botao existiria
  sem nada atras dele.
- A sessao do agente ganha um bloco proprio entre o cabecalho e a conversa: nome, situacao, o
  que ele esta fazendo, quantas respostas deu do limite, o motivo de ter parado, e as duas
  acoes que importam ali — assumir a conversa e pausar o agente. Esse estado existia espremido
  num selo de 200 px no canto do cabecalho, onde cabia o nome e uma palavra; o resto so no
  painel do contato, do outro lado da tela. A linha da grade e `auto`, entao sem agente ela
  some sozinha.
- Moderar comentario passa a existir no web. O servidor e o aplicativo ja faziam isto; a tela
  do navegador mostrava o comentario e nao tinha o que fazer com ele. `GET` de lista e de
  conversa passam a trazer `moderation` e `moderation_available`, e
  `POST /inbox/api/conversations/{id}/moderation` aplica spam, ocultar, bloquear o autor e o
  contrario de cada um. Ocultar so aparece no Instagram, porque e o unico canal em que a Meta
  aceita a operacao, e a tela nao antecipa o novo estado: espera a confirmacao da Meta, senao
  diria "oculto" sobre um comentario que continua no post. O bloqueio do autor nao tem volta
  pela API e por isso vira estado, nao um botao que mente. Ver o que foi marcado e leitura;
  mudar e edicao, e sao permissoes separadas.
- A linha da lista oferece as acoes sem abrir a conversa, como o arrastar faz no aplicativo:
  assumir quando nao tem dono, adiar uma hora e resolver quando e minha, reabrir quando esta
  resolvida. A coluna desenha so o que o servidor vai aceitar — `ConversationActions` exige ser
  o responsavel em tudo menos `reopen` —, porque botao que responde erro e pior do que botao
  nenhum. Elas aparecem ao passar o mouse, ao focar pelo teclado e na linha aberta, e ficam
  fora do botao da linha porque botao nao aninha em botao. A transicao pela lista nao recarrega
  timeline nem ia da conversa ao lado, e guarda o detalhe para a `version` da linha nao ficar
  velha, que e o que produz 409 na acao seguinte.
- A lista fica 20 px mais larga e o painel do contato 30 px, para o nome e o grupo caberem sem
  cortar.

## 1.11.0

- Entrar com usuario e senha do Mautic, para quem nao recebe o codigo por e-mail nem consegue
  abrir a pagina do Mautic no aparelho. `POST /inbox/mobile/token` aceita
  `grant_type=password` com `username` (usuario ou e-mail) e `password`, e devolve a mesma
  sessao dos outros grants: senha conferida pelo hasher do Mautic, acesso pelo mesmo
  `ConversationAccess::canViewInbox`, e `fingerprint` da senha guardado, entao trocar a senha
  encerra as sessoes abertas. Uma recusa so para usuario inexistente, ambiguo, despublicado,
  senha errada ou sem permissao, com hash isca quando nao ha usuario, para que um nome errado
  custe o mesmo que uma senha errada. Limites de 10/h por identificador, 30/h por IP e 1000/h
  global, contados antes de olhar a senha. `config` anuncia `capabilities.password_login`, e os
  grants que ja existiam nao mudam.

## 1.10.1

- A tabela `inbox_operator_presence` passa a ser criada por uma migration. O Mautic monta o
  schema de um plugin pelas entidades so na instalacao; numa atualizacao ele roda apenas as
  migrations do plugin, e a 1.10.0 nao trazia nenhuma, entao a entidade subia mapeada e sem
  tabela. Nada mais muda.

## 1.10.0

- Disponibilidade do atendente: `GET` e `POST /inbox/mobile/api/availability` guardam se o
  atendente esta aceitando conversas, numa tabela nova `inbox_operator_presence`. Sem linha o
  atendente esta disponivel, entao atualizar nao muda o comportamento de quem ja usava. O
  `config` passa a anunciar `capabilities.availability`, e o app so mostra o controle onde ele
  existe.
- Fotos de perfil de Instagram e Facebook deixam de sumir. A Meta manda URLs assinadas de vida
  curta (`platform-lookaside.fbsbx.com`), que respondem 404 depois de poucos dias; agora a
  imagem e copiada uma vez para `media/images/inbox-avatars`, reduzida a um quadrado de 96 px em
  WebP, e servida por URL estavel. Vale para o app e para o inbox web, que usam a mesma consulta.
  No maximo uma copia por requisicao, para a listagem nao virar fila de downloads.
- O WhatsApp nao entra nisso: a API da Meta nao manda foto de contato de WhatsApp, nem pelo canal
  homologado nem pela sessao por QR. As iniciais continuam sendo o que existe.

# Acesso limitado de operadores (6/10/2026)

- Login/senha existente do Mautic com papel próprio para avaliação Apple.
- Escopo de conversas próprias, fila aberta aguardando atendimento e supervisão explícita.
- Proteção de leitura, ações, mídia, notificações, assistente e push sem migração.
- Validação de banco somente em instalação descartável com guard do kernel real.

# Changelog

## 1.9.1 - 2026-10-25

Correções do teste real em produção com o app no aparelho. Use com o Multi Mail 0.5.1, que corrige a resposta duplicada na thread.

- **E-mail devolvido aparece como falha no cartão.** Quando o DSN chega, o envio do atendente passa a sair no histórico (web e app) com `status: failed`, `failure_code: email_bounced`, o diagnóstico do servidor do lead e sem "tentar de novo", porque reenviar ao mesmo endereço volta do mesmo jeito. Antes o cartão continuava "Enviada" e só a linha de evento mostrava a falha.
- **Páginas públicas na língua de quem abre.** O original no app, o link de formulário expirado e o descadastro seguem o `Accept-Language` do navegador (pt e en); sem uma delas, o perfil do atendente e depois o idioma padrão do Mautic. O original no celular de um atendente em português saía em inglês.
- **Parênteses no nome do formulário.** "DEP 04 - Feedback livre (o que faltou)" chegava ao lead como "Feedback livre  o que faltou"; só colchetes e quebras de linha saem do texto do link.
- Inclui as correções de produção do Facebook (falha de revisão do app Meta explicada na resposta) e do WhatsApp QR (aviso de sincronização separado do conteúdo do cliente).

## 1.9.0 - 2026-10-24

Canal de e-mail com threads. Exige Meta 0.15.0 (`Channel::Email`, `email_account`, operação de fila `email_message`); o envio, a leitura IMAP e os arquivos do e-mail são do Multi Mail 0.5.0. Sem o Multi Mail o Inbox continua funcionando: o original e os anexos aparecem como indisponíveis e o envio falha na fila com o motivo.

- **Conversa de e-mail no Inbox web e na API mobile.** `email` entra no filtro de canais; o resumo ganha `subject`, `asset.address` e `email.inbound`/`inbound_since` (faixa de leitura atrasada); o item do histórico ganha `subject`, `email` (de, para, cc, citado, `has_html`, `has_original`, resposta automática, remetente não verificado, `kind`) e vários anexos com proxy autenticado. `capabilities.channels` do app inclui `email`.
- **Resposta por e-mail pela fila do Meta.** `reply` aceita `subject` (web e mobile); assunto vazio vira `Re: <assunto da última mensagem>`; o primeiro e-mail da conversa exige assunto. O job `email_message` leva `subject`, `author_name` e até 9 tentativas; o "tentar de novo" copia o `_email_message_id` gravado pelo despachante. Sem janela de 24 h: o e-mail fecha só por conversa resolvida, conta desativada, DNC de e-mail ou destinatário inválido. IA fica fora do canal e-mail nesta versão.
- **Saneamento de HTML** por lista de permissão com o HTMLPurifier que o Mautic já traz (sem script, iframe, object, form, base, meta, svg, `on*`, `javascript:`, `data:` fora de imagem, CSS remoto ou `url()`); imagens `cid:` apontam para o proxy do anexo. Contrato `EmailContentProviderInterface` (tag `mautic.inbox.email_content`) para o Multi Mail entregar o HTML original e os anexos.
- **Anexos e original.** `GET /s/inbox/api/email/{id}/attachments/{index}` e `GET /inbox/mobile/api/email/{id}/attachments/{index}` com `nosniff`, `Content-Security-Policy: sandbox`, documento como `attachment` e checagem de acesso à conversa. Original saneado em `GET /s/inbox/api/messages/{id}/original`; no app, `POST /inbox/mobile/api/messages/{id}/original-link` devolve um link assinado de 5 minutos para uma página com `CSP: sandbox`.
- **Abrir conversa por e-mail pelo sheet de canais.** `GET /inbox/mobile/api/contacts/{id}` lista uma opção `email:{assetId}` por conta conversacional ativa; `start` cria a conversa com o e-mail do contato em minúsculas, atribuída a quem abriu, sem enviar nada, e recusa com 409 um e-mail ligado a outro contato.
- **Formulário de pesquisa na thread.** `GET /s/inbox/api/forms` e `GET /inbox/mobile/api/forms`; o marcador `[[form:ID]]` vira um link `/inbox/f/{token}` (formulário, contato e conversa, 30 dias) no enfileiramento, inclusive no WhatsApp; formulário despublicado recusa antes de enfileirar. Abrir o link rastreia o contato e registra "abriu o formulário"; o envio registra "respondeu o formulário" com as respostas.
- **Ação de campanha "Iniciar conversa por e-mail"**: conta, nome no From, assunto e texto com tokens de contato e formulário; sem e-mail, com DNC ou com o e-mail ligado a outro contato o evento fecha com o motivo, sem nova tentativa; conversa em atendimento humano falha para o Mautic tentar de novo depois; chave `campaign:{log}` impede duplicata; `List-Unsubscribe` de um clique (RFC 8058) em `/inbox/u/{token}` grava DNC de e-mail.
- **Visual estilo Gmail no web**: `EmailThread`, `EmailCard`, `EmailBody` (iframe sem scripts, CSP no `srcdoc`, imagens remotas só depois de "Mostrar imagens") e `EmailComposer` (Para, De, Assunto `Re:` editável, respostas prontas e formulário no "+"). Ícone de envelope, rótulo, filtro, cor e assunto na lista.
- Corrige a versão declarada do plugin (1.5.2 → 1.9.0); as versões 1.6 a 1.8.2 saíram só como tags.

## 1.5.2 - 2026-10-07

- Publica o acesso de operadores com escopo de conversas, autenticação do aplicativo, notificações push e recuperação WhatsQR já presentes no atendimento.
- Mantém uma única mensagem visível para tentativas de reenvio e exibe diagnósticos de identidades WhatsApp não vinculadas.
- Mostra o número QR de envio entre as opções de canal.
- Atualiza a timeline com histórico privado importado, preservando datas, atribuição e ciclo de atendimento; mensagens antigas não acionam som, contador de novas mensagens ou push.
- Reserva espaço para o rodapé do Mautic e adapta lista, conversa e painel de contato à largura disponível, incluindo tablets.
- Reúne as opções do editor no botão **+**, mantém envio ao lado do texto e ajusta a altura conforme digitação, rascunho e largura.
- Preserva notas, respostas prontas, templates, restrições do canal, atalhos e envio otimista; corrige a seleção de respostas prontas com IDs numéricos.
- Alinha a versão declarada do plugin e documenta a dependência Meta 0.14.2 e o complemento WhatsQR 0.3.2.

## 1.3.0

- Adiciona um contrato de transporte para canais externos ao Meta Bundle.
- Permite que plugins de canal reutilizem Inbox, atribuição, notas, rascunhos e agentes de IA.
- Inclui metadados de tempo real por conversa e suporte ao canal Web Chat.

## Nao lancado

- Adiciona a fundacao criptografica do Web Push sobre ext-openssl, sem dependencia de Composer.
- Verifica a cifra de conteudo contra os vetores publicados na RFC 8291.

## 1.1.1

- Adiciona limite global e por agente para respostas de IA em cada conversa.
- Mantém `0` como padrão ilimitado e aplica o menor limite positivo entre as duas configurações.
- Envia a última resposta permitida, pausa o agente e mantém a conversa aguardando atendimento humano ao atingir a trava.
- Preserva como ilimitadas as configurações globais e os agentes antigos cujo campo de limite existia apenas como dado informativo.

## 1.1.0

- Migra o atendimento e o workspace de agentes de IA para componentes Svelte 5 com TypeScript.
- Preserva os contratos PHP, rotas, permissões, CSRF, SSE, filas, rascunhos, mídia, modelos WhatsApp, respostas prontas e ações de atendimento existentes.
- Compartilha componentes de avatar, lista, timeline, mensagens, mídia, composer, contato, configurações, documentos, permissões e agentes.
- Mantém o visual do Mautic e o ciclo de navegação AJAX com montagem e desmontagem seguras.
- Preserva o salvamento do agente pela barra superior e seu comportamento responsivo, evolução que já estava ativa em produção.
- Publica um bundle autônomo em `Assets/dist/inbox-app.js`, permitindo instalar o plugin por Composer sem Node.js em produção.
- Adiciona verificações de TypeScript/Svelte e testes do bundle compilado, traduções, Markdown, histórico, notificações, CSRF e persistência de rascunhos.
- Alinha a dependência do conector com o Meta Bundle 0.14.0, que inclui a interface Svelte e o Tech Provider preservado.

## 1.0.14

- Adiciona uma URL compartilhável e estável para cada conversa do atendimento.
- Atualiza a barra de endereço com History API sem recarregar ou desmontar o Inbox.
- Mantém Voltar e Avançar entre conversas dentro da interface, neutralizando o recarregamento global do shell apenas nas rotas do Inbox.
- Abre diretamente a conversa indicada após autenticação e verificação das permissões já existentes.
- Padroniza todas as URLs do plugin em inglês, começando por `/s/inbox`.

## 1.0.13

- Detecta conversas WhatsApp equivalentes quando a Meta alterna números móveis brasileiros com ou sem o nono dígito.
- Adiciona uma prévia segura e um comando explícito para consolidar duplicidades existentes.
- Preserva mensagens, eventos, filas, rascunhos, notas, contato vinculado, tomada humana e sessão do agente de IA durante a união.
- Bloqueia automaticamente grupos com contatos conflitantes e executa cada união em uma única transação.

## 1.0.12

- Remove o teto de respostas dos agentes de IA; o contador passa a ser apenas telemetria.
- Adiciona reinício seguro da sessão do agente, com novo nonce e invalidação de trabalhos antigos.
- Mantém pausas causadas por falha de entrega, retomada humana, restrições do canal e desativação administrativa.
- Exibe estado e autoria do agente no atendimento e simplifica a configuração para modelos realmente disponíveis no Pi/Codex.
- Serve mídia recebida pelo WhatsApp por proxy autenticado e adiciona nova tentativa automática e manual de carregamento.
- Amplia o histórico inicial para evitar que anexos recentes fiquem ocultos pela paginação.
- Consolida configurações, documentos, fontes controladas, gerenciamento de respostas prontas e atualização em tempo real da interface.
- Atualiza testes e documentação operacional.

## 1.0.2

- Adiciona captura atual do atendimento ao README.
- Atualiza instruções de instalação para Inbox 1.0.2 e Meta 0.12.1.
- Vincula a documentação visual do conector e esclarece a compatibilidade entre os plugins.
- Mantém o comportamento do atendimento e a dependência mínima Meta ^0.12.0.

## 1.0.1

- Publica o Atendimento como repositório independente, extraído da tag `inbox-v1.0.0` do Mautic.
- Define o pacote Composer `raphaelcangucu/mautic-inbox-bundle`.
- Corrige a dependência do Meta Bundle para `^0.12.0`.
- Documenta instalação, arquitetura, operação, diagnóstico, limites e validação.
- Preserva o comportamento do código previamente validado.

## 1.0.0

- Primeira implementação validada: atendimento WhatsApp, Instagram e Facebook/Messenger.
- Atribuição, transferência, resolução, adiamento, notas e rascunhos.
- Integração com a fila Meta e bloqueio de automações durante a tomada humana.
- Nomes e fotos, prévias de mídia, formatação segura, SSE e alertas sonoros/visuais.
