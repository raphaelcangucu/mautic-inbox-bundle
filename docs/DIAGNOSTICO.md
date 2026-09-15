# Diagnóstico e manutenção

| Sintoma | O que conferir |
| --- | --- |
| Atendimento não aparece | Instalação dos dois bundles, recarregamento, cache e permissões do papel |
| Erro de classe ou serviço da integração | Meta Bundle >= 0.12.0; diretórios e namespaces corretos |
| Inbox vazio | Mensagens no conector, webhook do ativo e reconciliação das conversas antigas |
| Não consegue responder | Responsável, permissões do atendente, ativo habilitado, conexão Meta e janela do canal |
| Resposta fica pendente | Execução e logs de `mautic:meta:queue:process`; backlog da fila |
| Resposta falhou ou ficou incerta | Estado do job no conector; confira a conversa no canal antes de repetir |
| Aparecem IDs em vez de nomes | Disponibilidade de perfil na API, escopos e identificação recebida pelo webhook; perfil pode estar em cache |
| Foto ou anexo não abre | URL expirada, restrição de acesso, CSP, tipo de mídia ou resposta da origem |
| Atualização demora | Stream SSE, buffering/timeouts do proxy, workers PHP e fallback de atualização |
| Som não toca | Clique em Ativar som; confira volume e bloqueio de áudio do navegador |
| Favicon não muda | Aguarde uma nova mensagem recebida após a carga inicial, com a caixa aberta |
| Automação continua pausada após resolver | Comportamento esperado: resolver não remove a tomada humana |

## SSE e infraestrutura

Inspecione `/s/inbox/api/stream` nas ferramentas de rede do navegador autenticado. Deve usar `text/event-stream`, emitir eventos/heartbeats e reconectar. O prefixo pode variar pela instalação. Proxies não devem acumular todo o conteúdo do stream antes de entregá-lo. O fallback permite atualização mesmo quando SSE falha, mas com maior latência.

## Perfil, mídia e limites de canal

Nome e foto não são garantidos para toda identidade: dependem dos dados e permissões do canal. O conector faz enriquecimento com cache e falha não bloqueante. O plugin exibe mídia por URL remota; não preserva uma cópia quando a URL expira. Receber um anexo não significa que a interface permite enviar anexos: o compositor humano desta versão envia texto.

Facebook e Instagram têm configurações distintas. Habilitar Messenger não habilita automaticamente comentários. Reels só aparecem com o contexto fornecido pelo webhook/API; não existe coleta irrestrita de publicações.

## Dados e logs

Consulte os logs da aplicação e o estado dos jobs no conector, relacionando IDs da conversa e da requisição. Não publique tokens, payloads completos ou mensagens de clientes em issues. Faça backup de banco antes de operações de esquema; não exclua registros `meta_*` para limpar somente estados do Inbox.
