# Iniciar contato com um modelo WhatsApp

No editor de uma conversa WhatsApp, use **Escolher modelo WhatsApp**. A lista reúne modelos aprovados do catálogo local, após confirmar na Meta a conta comercial à qual o número pertence. Selecione o idioma/modelo, preencha as variáveis e revise a prévia. O botão de envio pede confirmação; quando a conversa está sem responsável, o atendente a assume ao enviar.

O editor suporta cabeçalho de texto, corpo, rodapé e botões estáticos. Modelos com mídia, botões dinâmicos ou componentes especiais aparecem sinalizados e não podem ser enviados por este editor. A configuração original dos modelos não é modificada.

O conector mantém as verificações de consentimento, DNC, canal, aprovação e limites. Um modelo pode ser usado fora da janela de atendimento, mas seu envio não libera a resposta livre antes da interação do destinatário. Conversas resolvidas precisam ser reabertas e conversas atribuídas a outro atendente precisam ser transferidas.

## Integração

- GET `/s/atendimento/api/conversas/{stateId}/modelos`: catálogo e motivo de bloqueio, protegido pelas permissões de envio.
- POST de resposta existente: `template_id`, `variables` (chaves como `BODY:1`) e `request_id` estável para repetição segura.
- A fila usa `whatsapp_template` com `_template_id`, preservando a seleção exata, origem humana e histórico do atendimento.
- Nenhuma migração de banco é necessária. Atualize os dois plugins para utilizar a seleção exata do catálogo na execução da fila.

## Validação

Quatro testes novos cobrem conta comercial correta, solicitação repetida sem duplicação, variáveis independentes em cabeçalho/corpo, catálogo HTTP sem envio, consentimento e componentes não suportados. A suite anterior do Atendimento e os testes do remetente WhatsApp continuam passando. Na instância real, três modelos aprovados foram carregados; seleção, prévia e habilitação do envio foram conferidas sem disparar mensagens.
