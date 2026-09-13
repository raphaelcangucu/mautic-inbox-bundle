# Mautic Inbox Bundle — Atendimento omnicanal

Plugin nativo de atendimento para Mautic, com conversas de WhatsApp, Instagram e Facebook/Messenger no mesmo painel. Organiza o trabalho humano: responsáveis, filas, respostas, notas e histórico. A comunicação com a Meta é realizada pelo [Mautic Meta Bundle](https://github.com/raphaelcangucu/mautic-meta-bundle).

## Documentação

- [Instalação e dependências](docs/INSTALACAO.md)
- [Funcionamento, uso diário e limites](docs/OPERACAO.md)
- [Arquitetura e integração com o conector](docs/ARQUITETURA.md)
- [Diagnóstico e manutenção](docs/DIAGNOSTICO.md)
- [Desenvolvimento e validação](docs/DESENVOLVIMENTO.md)
- [Histórico de versões](CHANGELOG.md)

## Dependências

| Componente | Requisito | Responsabilidade |
| --- | --- | --- |
| Mautic | 7.x | Autenticação, permissões, CRM, banco e infraestrutura Symfony |
| PHP | >= 8.2, compatível com o Mautic instalado | Execução do plugin |
| Mautic Meta Bundle | >= 0.12.0 e < 0.13.0 (`^0.12.0`) | Credenciais, ativos, webhooks, identidades, mensagens e fila de envio |
| Banco | O banco suportado pela instalação Mautic | Tabelas do conector e sete tabelas de atendimento |
| Navegador | Moderno, com JavaScript | Interface; SSE e Web Audio quando disponíveis |

**O Atendimento não funciona sozinho.** O conector deve estar instalado e configurado na mesma instância. Ele pode operar sem o Atendimento; a dependência é em apenas uma direção. O plugin não requer Chatwoot, Redis ou servidor WebSocket próprio.

## Recursos

- Lista de conversas, histórico e contexto do contato, com as cores do Mautic.
- Nomes, handles e fotos quando disponibilizados pelo canal; identificação alternativa quando indisponíveis.
- Atribuição, transferência, resolução, adiamento, notas internas, respostas prontas e rascunhos.
- Comentários separados das mensagens privadas, com contexto da publicação.
- Resposta pública a comentários do Facebook e resposta privada a comentários do Instagram conforme disponibilidade da API.
- Prévia de imagens e stickers, controles de vídeo e áudio, links e formatação textual segura.
- Atualização por SSE com alternativa silenciosa; alertas sonoros opcionais e contador no favicon.
- Bloqueio de automações durante a tomada humana, aplicado também no momento de envio.

## Instalação resumida

Instale o conector `v0.12.0` em `plugins/MauticMetaBundle` e este plugin `v1.0.1` em `plugins/MauticInboxBundle`. Na raiz do Mautic, recarregue os plugins e limpe o cache. Depois conceda as permissões de Atendimento e Meta ao papel do atendente, configure os canais no conector e mantenha o processamento da fila ativo.

Consulte o [guia completo](docs/INSTALACAO.md) para comandos, cron, Composer e importação de conversas existentes. A interface fica em `/s/atendimento`, respeitando o prefixo configurado no Mautic.

## Estado da versão

A versão `v1.0.1` extrai o código validado do repositório Mautic e corrige o nome do pacote e a dependência mínima do conector. Não modifica o comportamento da versão anterior. O código foi validado com 32 testes/190 asserções do Inbox, 79 testes/218 asserções do conector e testes JavaScript. Veja o [escopo da validação](docs/DESENVOLVIMENTO.md).

Limites atuais: permissões por papel, sem isolamento por ativo; envio humano de texto, sem compositor de upload de anexos; mídia remota sujeita à disponibilidade das URLs; alertas apenas com a caixa aberta. Reels dependem do contexto recebido da Meta e não foram validados em teste real específico.

Licença: [GPL-3.0-or-later](LICENSE). Projeto independente, integrado ao Mautic.
