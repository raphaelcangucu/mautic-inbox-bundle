# LLM.txt — Guia de Fluxos do Sistema

> Este arquivo foi gerado a partir da leitura direta do código-fonte deste repositório (Next.js). O objetivo é instruir um agente de IA de suporte a orientar usuários finais dentro da plataforma, informando **onde cada ação fica na tela** e **quais textos reais** o usuário vai encontrar.
>
> - Todos os textos entre aspas em **negrito** foram extraídos literalmente de `src/locales/pt.json` (idioma português). Não existe `pt-br.json` neste projeto — o locale pt fica em `pt.json`.
> - `{variável}` indica um valor interpolado em runtime (ex.: nome de usuário, valor monetário).
> - Textos vindos de CMS/backend (não do arquivo de locale) estão sinalizados explicitamente.
> - Caminhos de arquivo são referências técnicas para quem for manter este documento — não são necessários para orientar o usuário final.
> - Este documento cobre os 4 fluxos definidos como prioritários: **Conta/Acesso**, **Carteira**, **Histórico de Transações** e **Mercados de Previsão**. A plataforma também possui outros fluxos (Cassino, Esportes, Loteria, VIP, Afiliados, Chat, etc.) que podem ser documentados aqui futuramente.

---

## 0. Pontos de entrada globais (sempre visíveis)

### Header (topo da página, sempre fixo)

**Usuário não autenticado** (canto superior direito):
- Botão **"Entrar"** → abre modal de autenticação na aba Login.
- Botão **"Cadastre-se"** → abre modal de autenticação na aba Registrar.
- Em telas médias/grandes também há um ícone de busca (lupa).
- Se a plataforma estiver em modo waitlist, esses dois botões **não aparecem**.

**Usuário autenticado**:
- Centro/esquerda: seletor de carteira, mostrando o **saldo** e a **moeda ativa**. Clicar abre um dropdown com todas as moedas (busca: **"Pesquisar Moeda"**, toggles **"Ver em fiat"** / **"Ocultar saldos zerados"**).
- Se o e-mail não estiver verificado: um aviso aparece sobre a carteira: **"Por favor, verifique seu e-mail."** + botão **"Enviar e-mail de verificação"**.
- Canto direito: avatar/foto de perfil (abre o menu do perfil).

### Menu do perfil (clicar no avatar no Header)

Abre um popover com, entre outros itens:
- **"Carteira"** → abre o modal de Carteira.
- **"Configurações"** → vai para `/settings/privacy`.
- **"Notificações"** → abre um drawer lateral.
- **"Transações"** → vai para `/transactions/deposits`.
- **"Suporte"** → abre o chat ao vivo (se disponível).
- **"Sair"** → confirma logout.
- Seletor de tema: **"Tema do Sistema"** (Claro / Sistema / Escuro).

### Menu lateral (categorias)

No menu lateral (desktop) ou dropdown (mobile) ficam as categorias de navegação, incluindo o acesso a **Mercado** (ícone de categoria "Mercado" → `/markets`) e **Esportes** (`/sports`).

---

## 1. Fluxo: Conta e Acesso

### 1.1 Cadastro (Registro)

**Como chegar:** Header → botão **"Cadastre-se"** (ou qualquer ação protegida que exija login, ex.: apostar sem estar logado).

O modal abre com logo à esquerda e formulário à direita (em mobile, ocupa a tela toda). No topo do formulário há duas abas: **"Entrar"** | **"Registrar"**.

**Passo a passo:**
1. Usuário confirma que está na aba **"Registrar"**.
2. Preenche os campos:
   | Campo | Label | Placeholder |
   |---|---|---|
   | E-mail | "E-mail" | "exemplo@email.com" |
   | Usuário | "Nome de usuário" | "Nome de usuário" (mín. 3 caracteres) |
   | Senha | "Senha" | "Insira uma senha" (ícone de olho para mostrar/ocultar) |
   | Código de referência | "Código de referência" | campo colapsável; pode vir travado se o link de acesso já trouxer um código (`?code=...`) |
3. Marca o checkbox: **"Confirmo que tenho pelo menos 18 anos e concordo com os Termos de Serviço."**
4. Resolve o captcha (Cloudflare Turnstile) — o botão final fica desabilitado até isso ser concluído.
5. Clica em **"Criar uma conta"**.
6. **Sucesso:** notificação **"Registro bem-sucedido!"** / **"Bem-vindo a bordo, {username}! Sua conta está pronta"** — modal fecha e usuário já está logado.
7. **Código de referência inválido:** notificação **"Código de referência inválido"** / **"Este código de referência não é válido. Apague-o ou informe outro e crie sua conta."**
8. **E-mail já cadastrado (403):** notificação **"Não autorizado"** — modal muda automaticamente para a aba de login com o e-mail já preenchido.

Se a plataforma tiver login social habilitado, aparece um divisor **"Ou entre com"** e botões Google / Apple / Facebook / X.

Rodapé do formulário: **"{nome da plataforma} é protegido por Captcha. A Política de Privacidade e os Termos de Serviço se aplicam"**.

### 1.2 Login

**Como chegar:** Header → botão **"Entrar"**.

**Passo a passo:**
1. Modal abre na aba **"Entrar"**.
2. Preenche:
   | Campo | Label | Placeholder |
   |---|---|---|
   | E-mail ou usuário | "E-mail ou Nome de usuário" | "E-mail ou Nome de usuário" |
   | Senha | "Senha" | "Insira uma senha" |
3. Resolve o captcha.
4. Clica em **"Entrar"** (ou aperta Enter).
5. **Sucesso:** **"Bem-vindo de volta!"** / **"Que bom te ver novamente, {username}!"**
6. **Conta com 2FA:** notificação **"Código Dois fatores"** e a tela muda para a etapa de 2FA (ver 1.4).
7. **Credenciais erradas / erro:** título **"Ops!"** ou **"Não autorizado"** + mensagem retornada pelo servidor.

**Esqueceu a senha?** Logo abaixo do botão "Entrar" existe um link de texto: **"Esqueci minha senha"**.

### 1.3 Recuperação de senha

**Etapa A — solicitar o link por e-mail:**
1. Na tela de login, clica em **"Esqueci minha senha"**.
2. Modal muda para a tela **"Esqueceu sua senha"** (com seta de voltar).
3. Preenche o campo **"E-mail"** (placeholder "exemplo@email.com").
4. Clica em **"Trocar a senha"**.
5. Uma notificação de sucesso confirma o envio do e-mail.

**Etapa B — definir a nova senha (a partir do link recebido por e-mail):**
1. O link do e-mail tem o formato `/password-reset/{token}?email={email}` (pode incluir `&enable2fa=1`).
2. Ao abrir o link, a página redireciona para a home já com o modal aberto na etapa de nova senha.
3. Campos exibidos:
   | Campo | Label | Placeholder |
   |---|---|---|
   | Senha | "Senha" | "Insira uma senha" |
   | Confirmação | "Confirme sua senha" | "Confirme sua senha" |
   | 2FA (se aplicável) | "Código de dois fatores" | "Código" |
4. Clica em **"Trocar a senha"**.
5. **Senhas não conferem:** erro inline **"Oops! As senhas digitadas não conferem."**
6. **Sucesso:** volta para a tela de login para o usuário entrar com a nova senha.

### 1.4 Autenticação de dois fatores (2FA)

**No login** (quando a conta já tem 2FA ativo):
1. Aparece a tela **"Autenticação de dois fatores"**.
2. Campo **"Código de dois fatores"** (placeholder "Código", 6 dígitos, ícone para colar).
3. Clica em **"Enviar"**.
4. Sucesso: **"Bem-vindo"** / **"Vamos nos divertir {username}!"**

**Ativar 2FA nas Configurações** (`/settings/security`, seção "Autenticação de dois fatores"):
1. Descrição: **"É altamente recomendável ativar a autenticação de dois fatores, pois ela protege sua conta usando sua senha e seu dispositivo móvel."**
2. Clica em **"Habilitar 2FA"**.
3. Modal **"Habilitar Aplicativo 2FA"**: mostra QR code + instrução **"Digitalize o código QR abaixo usando um aplicativo autenticador compatível (como Google Authenticator, Authy, LastPass, etc.)."**
4. Alternativa: **"Não consegue escanear o QR Code? Insira este código em seu aplicativo autenticador."** (código copiável).
5. Digita o código gerado pelo app e clica **"Ativar"**.
6. Código errado: **"Código 2FA incorreto"**.

**Desativar 2FA:** clica em **"Desativar 2FA"** → modal pede **"Código de dois fatores - App"** + **"Senha de login"** → clica **"Desativar"**.

**2FA por e-mail** (usado para confirmar ações sensíveis de carteira, ex.: saque, quando o usuário não tem 2FA de app): modal com título **"Confirmação de {ação}"** (ex.: "Confirmação de Saque"), texto **"Por favor, insira o código de 6 dígitos enviado ao seu email dentro de 15 minutos para confirmar."**, campo **"Código de verificação - E-mail"**, botão **"Confirmar"**.

### 1.5 Configurações da conta (`/settings/{aba}`)

**Como chegar:** menu do perfil → **"Configurações"** (abre em `/settings/privacy`).

Layout: no desktop, menu lateral de abas + conteúdo à direita; no mobile, um select para trocar de aba. Cabeçalho: ícone de engrenagem + **"Configurações globais"** + X para fechar (volta para a home).

| Aba | URL | O que permite fazer |
|---|---|---|
| **"Privacidade"** | `/settings/privacy` | Ativar navegação anônima e marketing por e-mail |
| **"Segurança"** | `/settings/security` | Ver/verificar e-mail, vincular contas sociais, ativar/desativar 2FA |
| **"Foto do perfil"** | `/settings/profile-picture` | Escolher avatar padrão ou enviar uma imagem |
| **"Token de API"** | `/settings/sdk-token` | Gerar/renovar token de API (requer estar logado) |

**Privacidade:** dois switches — **"Modo de navegação anônima"** (oculta o usuário do feed de apostas ao vivo) e **"Receba marketing por e-mail"**.

**Segurança:** e-mail com badge **"Verificado"**/**"Não verificado"** (+ botão **"Verificar e-mail"** se necessário); seção **"Vincular conta social"** com botões **"Conectar"**/**"Conectado"** por provedor; seção de 2FA (ver 1.4).

**Foto do perfil:** grade de avatares padrão + botão **"Definir avatar"**; ou **"Carregar novo avatar"** para upload (limite de 4 MB — acima disso: **"Imagem muito grande"**).

**Token de API:** botão **"Criar token"** (ou **"Gerar novo token"** se já existir um) — gerar um novo revoga o anterior.

---

## 2. Fluxo: Carteira (Depósito e Saque)

### 2.1 Como abrir a carteira

**Header** → clicar no saldo/moeda (abre dropdown de seleção) ou no botão/ícone **"Carteira"** (desktop: botão com texto; mobile: apenas ícone) → abre o modal de Carteira.

Também acessível pelo menu do perfil → **"Carteira"**.

O modal de Carteira tem abas: **"Depósito"** | **"Saque"** | **"Cofre"** | **"Câmbio"** (se habilitado) | **"Tip"** (se habilitado). No canto superior direito há sempre um link **"Transações"** que leva ao histórico da aba atual.

### 2.2 Depósito

Dentro da aba **"Depósito"**, existem duas sub-abas:
- **"Use Cripto"** (selecionada por padrão)
- **"Use Dinheiro/Cartão"**

**Depósito em Cripto:**
1. Escolhe a **"Moeda do Depósito"**.
2. Se for USDT/USDC, escolhe também a **"Rede"**.
3. É exibido o **"{moeda} Endereço de depósito"** (somente leitura, com ícones de atualizar e copiar) e o **QR code** correspondente.
4. Para XRP existe um fluxo especial: botão **"Mostrar endereço de depósito XRP"**, e depois um campo extra **"Tag de destino / Memo"**.
5. Aviso fixo: **"AVISO: Envie apenas o {moeda} para este endereço, 1 confirmação necessária."**
6. Se a plataforma tiver bônus de depósito habilitado, aparece o botão **"Ver Bônus de Depósito"** (ver 2.3).

**Depósito em Dinheiro/Cartão (Fiat):**
1. Tela **"Selecione o Método de Depósito"** — **"Escolha sua forma de pagamento preferida"**.
2. Métodos possíveis (variam por configuração): **"Depósito via PIX"**, **"Depósito via Noxpay"**, **"Depósito via OnRamper"**, **"Depósito via Swapped"**.
3. **PIX** (o mais comum no Brasil):
   - Escolhe **"Moeda de depósito"** (cripto que vai receber o crédito) e informa o **"Valor do depósito"** em reais.
   - Clica em **"Criar Depósito"**.
   - Aparece a tela de pagamento: QR code + **"Código PIX"** copiável, título **"Depósito via PIX Criado"**, instrução **"Escaneie o QR Code com o app do seu banco ou copie o código PIX para fazer o depósito."**, e um cronômetro **"Expira em:"**.
   - Após o pagamento ser processado, o título muda para **"Depósito PIX Confirmado"** e a mensagem **"Seu pagamento foi confirmado e o valor já está disponível na sua carteira."**
   - Valor abaixo do mínimo: **"O valor mínimo permitido é {valor}"**.
4. **Noxpay/OnRamper/Swapped:** abrem um checkout/iframe externo em nova aba; se falhar ao carregar, exibem **"Tente novamente"**.

### 2.3 Bônus de depósito

A partir de Depósito → Use Cripto → botão **"Ver Bônus de Depósito"**, o cabeçalho do modal muda para **"Bônus de Depósito"**. Ali o usuário vê cards de bônus com **"Match"** (ex.: "50% de Match"), **"Rollover"**, **"Depósito Mínimo"**, **"Valor Máximo de Match"**, e um botão **"Ativar"**/**"Desativar"** por bônus. Ao ativar: **"Bônus Ativado"** / **"Seu bônus de depósito foi ativado com sucesso"**.

### 2.4 Saque

Dentro da aba **"Saque"**, se houver mais de um método de pagamento configurado, existem sub-abas **"Cripto"** e **"Pix"** (esta última agrupa PIX e Noxpay).

**Saque em Cripto:**
1. Escolhe **"Moeda de saque"** e, se aplicável, a **"Rede"**.
2. Informa o **"{moeda} Endereço de saque"** — há um link **"Gerenciar endereço"** para abrir a lista de endereços autorizados (**"Endereços de permissões"**), útil quando a plataforma exige whitelist.
3. Informa o valor em **"Sacar o valor"** (há um botão de preencher o valor máximo do saldo) — é mostrada a **"Taxa estimada"**.
4. Se 2FA de app estiver ativo, pede o código; senão, uma confirmação por e-mail é enviada (ver 1.4).
5. Clica em **"Sacar"**.
6. Erros comuns: **"O endereço é inválido"**, **"O valor máximo permitido é {valor}"**, **"O valor mínimo permitido é {valor}"**.

**Saque via PIX:**
1. Informa a **"Chave PIX"** (placeholder "CPF, CNPJ, email ou telefone") — ou clica em **"Selecionar Chave"** para escolher uma chave já salva em **"Minhas Chaves PIX"** (permite Adicionar/Editar/Deletar chaves).
2. Informa o valor em **"Sacar o valor"** (em reais) — mostra a **"Taxa estimada"** e um cronômetro **"Expira em:"** (a cotação da conversão expira).
3. Clica em **"Sacar"**.
4. Erros comuns: **"A chave PIX não é válida"**, **"Saldo insuficiente"**, **"O valor mínimo permitido é {valor} BRL"**.

**Saque via Noxpay:** informa moeda e valor, clica em **"Sacar"** e é redirecionado para um checkout externo.

---

## 3. Fluxo: Histórico de Transações

### 3.1 Como acessar

- Menu do perfil (avatar) → **"Transações"** → `/transactions/deposits`.
- Dentro do modal de Carteira → link **"Transações"** (leva para a aba equivalente à que estava aberta na carteira).
- Menu Mercado → **"Minhas posições"** → `/transactions/market?tab=positions`.

A página tem o título **"Transações"**, um X no canto para fechar (volta para a home) e um menu de abas (lateral no desktop, dropdown no mobile).

### 3.2 Abas disponíveis

| Aba | URL | Aparece quando... |
|---|---|---|
| **Depósitos** | `/transactions/deposits` | sempre |
| **Saques** | `/transactions/withdrawals` | sempre |
| **Apostas** | `/transactions/bet` | Cassino, Originais ou Esportes habilitados |
| **Mercado** | `/transactions/market` | Mercados de Previsão habilitado |
| **Loteria** | `/transactions/lottery` | Mega Six habilitado |
| **Câmbio** | `/transactions/swaps` | Câmbio habilitado |
| **Cofre** | `/transactions/vault` | sempre |
| **Tip** | `/transactions/tip` | Tip habilitado |
| **Afiliado** | `/transactions/affiliate` | Programa de afiliados habilitado |
| **Promo** | `/transactions/promo` | Promoções habilitadas |
| **Rain** | `/transactions/rain` | Rain habilitado |
| **Desafio** | `/transactions/challenge` | Desafios habilitados |

Cada aba é uma tabela paginada com colunas como **Estado**, **Tempo** e **Quantia** (varia por aba), filtros de **moeda** (padrão **"Tudo"**) e de **período** (**"Selecione o intervalo de datas"**). Clicar no ícone de link ao lado de uma linha geralmente abre um modal com o detalhe da transação.

A aba **Mercado** é diferente: tem 3 sub-abas internas — **"Atividade"** (feed de compra/venda/resgate), **"Posições"** (cards com as posições do usuário, com **"Total Investido"**, **"Preço Médio"**, **"L&P"**/**"PNL"**, botões **"Vender Posição"** e **"Ver Mercado"**) e **"Minhas Ordens"** (tabela de ordens abertas/executadas, com opção de cancelar ordens abertas).

Cada aba tem uma mensagem para quando está vazia, geralmente com um link de atalho (ex.: aba Depósitos vazia mostra **"Deposite agora e comece a jogar"**, que abre a Carteira direto na aba de Depósito).

---

## 4. Fluxo: Mercados de Previsão

### 4.1 Como chegar

- Menu lateral → categoria **"Mercado"** → `/markets`.
- Submenu Mercado → **"Principal"** (`/markets`), **"Minhas posições"** (`/transactions/market?tab=positions`, requer login), **"Favoritos"** (`/markets/favorites`, requer login).
- Card na home: **"Mercado futuro"**.
- (Não existe atalho para mercados no Header — apenas no menu lateral/rodapé.)

### 4.2 Listagem (`/markets`)

1. No topo, uma barra de categorias em chips, começando por **"Popular"**.
2. Um banner/carrossel com mercados em destaque.
3. Filtros: **"Filtrar por"** → **"Categorias"** (seleção múltipla) e **"Ordenar por"** → A-Z, Z-A ou **"Mais recentes"**.
4. Grade de cards de mercado — cada card mostra a imagem, o título do mercado, a probabilidade/chance (círculo ou lista de outcomes), o multiplicador de cada opção e o volume negociado (**"Vol."**). Um ícone de estrela permite favoritar.
5. Botão **"Ver mais"** para carregar mais mercados.
6. Sem resultados: **"Não há mercados disponíveis"**.

**Favoritos (`/markets/favorites`):** mesma estrutura, mas apenas com os mercados marcados como favoritos; vazio mostra **"Você não tem mercados favoritos."**

### 4.3 Página de um mercado (`/market/{slug}`)

Coluna principal (esquerda):
1. Cabeçalho com imagem, título, categoria, ícones de **favoritar** e **"Copiar link"**, e o **"Vol."** total.
2. Gráfico de probabilidade ao longo do tempo, com seletor de período (**"1h"**, **"6h"**, **"1d"**, **"1w"**, **"1m"**, **"todos"**).
3. Lista de opções (outcomes) do mercado — clicar em uma opção atualiza o painel de operação à direita.
4. Descrição do mercado dentro de um accordion **"Detalhes do mercado"**.
5. Se o mercado já foi resolvido, aparece uma seção **"Mensagem de Resolução"** com a explicação oficial.
6. Abas inferiores: **Chat**, **"Compradores"**, **"Atividade"**, **"Minhas Ordens"** (se logado) e **"Artigos"**.

Painel lateral direito (operação — muda conforme o estado do mercado):
- **Mercado ativo:** formulário de compra/venda (ver 4.4).
- **Encerrado, aguardando resultado:** **"Operações Indisponíveis"**.
- **Resolvido:** mostra o **"Resultado"** e quanto o usuário **"Recebeu"**.

### 4.4 Comprar uma posição (fluxo normal, dentro da página do mercado)

1. Escolhe o lado/opção (ex.: "Sim" ou "Não", ou uma opção de múltipla escolha).
2. No painel de operação, confirma a aba **"Comprar"** (ou **"Vender"**, se já tiver posição).
3. Insere o **"Valor"** desejado (para venda, insere a quantidade de **"Ações"**, com atalho **"Máximo Disponível"**).
4. Se disponível, pode ativar **"Usar bônus de shares"** ou **"Usar bônus de mercado"**.
5. É exibido um resumo: **"Odds"**, **"Custo Estimado"** (ou **"Você receberá"** na venda) e **"Pagamento se {opção}"**.
6. Botão final:
   - Não logado: **"Comprar"** (clicar abre o login).
   - Saldo insuficiente na moeda do mercado, mas com saldo em outra: **"Fazer Conversão"**.
   - Sem saldo algum: **"Depositar {moeda}"**.
   - Com saldo: **"Comprar"** / **"Vender"**.
7. Sucesso: notificação **"Sucesso!"** com o resumo da ordem.
8. Rodapé: **"Ao negociar, você concorda com os Termos de Serviço"**.

### 4.5 Checkout guiado (primeira posição de um novo usuário)

Fluxo dedicado em `/checkout/{marketSlug}`, usado tipicamente quando alguém chega por um link de campanha para um mercado específico. Barra de progresso no topo: **"Conta"** → **"Depósito"** → **"Mercado"** → **"Concluído"**.

1. **Conta** (se não estiver logado): título **"Crie sua conta"**, subtítulo **"Seus dados ficam seguros conosco. Depois do cadastro, você escolhe quando começar."** — formulário de cadastro padrão.
2. **Depósito** (se não tiver saldo suficiente):
   - Escolhe um valor sugerido ou digita **"Outro valor"**.
   - Vê a estimativa: **"Seu investimento"** → Depósito, Valor creditado, Lucro potencial.
   - Escolhe a **"Forma de depósito"**: **"PIX"** ou **"Crypto"**.
   - PIX: botão **"Gerar QR Code do PIX"** → tela **"Aguardando seu PIX"** → **"Depósito confirmado"**.
   - Crypto: tela **"Depósito em crypto"** com endereço e rede para envio.
3. **Mercado** (compra), assim que há saldo:
   - Título **"Seu saldo já está disponível para ser utilizado."**, convite **"Agora, vamos entrar no mercado"**.
   - Mostra o mercado e a opção escolhida (SIM/NÃO), com um resumo de investimento/retorno.
   - Botão principal: **"Entrar no mercado"**.
4. **Concluído:** título **"Você entrou no mercado."**, subtítulo **"Pronto! Acompanhe o resultado do seu mercado."**, com botões **"Ver minhas ordens"**, **"Descobrir outros mercados"** e **"Ir para o chat geral"**.

Durante todo o fluxo, um painel lateral (**MarketPreview**/**PositionSummary**) mostra o mercado escolhido, a opção, o preço atual e o retorno/perda potencial, permitindo ao usuário conferir a qualquer momento em qual mercado está entrando.

---

## 5. Observações importantes para o suporte

1. **Waitlist ativa:** se a plataforma estiver com waitlist habilitada, os botões "Entrar"/"Cadastre-se" somem do Header — é preciso orientar o usuário a usar o formulário de waitlist ou um link de campanha.
2. **Captcha obrigatório:** login e cadastro exigem resolver o Cloudflare Turnstile; se o usuário disser que o botão não funciona, provavelmente o captcha não foi resolvido.
3. **Tela de nova senha usa o título "Esqueceu sua senha"** (não "Redefinir senha") — isso é uma peculiaridade da interface atual, não um erro do usuário.
4. **Métodos de depósito/saque variam por configuração** (PIX, Noxpay, OnRamper, Swapped) — nem toda conta terá todos os métodos disponíveis.
5. **Valores mínimos/máximos de depósito e saque são dinâmicos** (vêm da configuração do gateway em tempo real) — não é possível informar um valor fixo sem consultar o sistema.
6. **Verificação de e-mail** pode ser refeita tanto pelo aviso no Header quanto em Configurações → Segurança → **"Verificar e-mail"**.
7. **Posições de mercado não ficam na página do mercado**, e sim em `/transactions/market?tab=positions` (aba "Posições" dentro de Transações → Mercado).
8. **Ordens (CLOB) podem ser canceladas** apenas na aba "Minhas Ordens" (ícone de lixeira), e apenas se estiverem "Aberta" ou "Parcial".
9. **Notificações de sucesso/erro de depósito e saque** costumam vir com o texto exato retornado pela API — podem variar ligeiramente do texto genérico documentado aqui.

---

## 6. Referência técnica rápida (para manutenção deste arquivo)

| Fluxo | Diretórios/arquivos principais |
|---|---|
| Conta/Acesso | `src/components/Modals/Authentication/`, `src/components/Modals/2FA/`, `src/components/Settings/`, `src/pages/settings/[slug].tsx`, `src/pages/password-reset/[slug].tsx` |
| Carteira | `src/components/Modals/Wallet/`, `src/components/Inputs/SelectWallet/`, `src/utils/constants/wallet.ts` |
| Transações | `src/pages/transactions/[slug].tsx`, `src/components/Transactions/`, `src/utils/constants/pages/transaction/` |
| Mercados | `src/pages/markets/`, `src/pages/market/[marketSlug]/`, `src/pages/checkout/`, `src/components/Market/`, `src/components/Checkout/FirstPosition/` |
| Textos em português | `src/locales/pt.json` |
| Feature flags | `src/hooks/useFeatures.ts`, `src/utils/constants/feature.ts` |
| Abertura de modais via URL | `src/utils/api/queries.ts` (`openModalInUrl`), `src/services/providers/Modals.tsx` |

> **Próximos passos sugeridos:** ampliar este arquivo com os fluxos de Cassino (jogos originais e de provedores), Esportes, Loteria Mega Six, VIP e Afiliados, seguindo o mesmo padrão de investigação usado aqui.
