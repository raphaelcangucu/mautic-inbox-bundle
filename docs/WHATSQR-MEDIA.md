# Fotos e anexos no WhatsApp QR — Inbox 1.5.0

## Dependências

- Mautic 7 e PHP 8.2 ou superior.
- Meta Bundle 0.14.2 ou compatível.
- WhatsQR 0.3.0 e seu serviço Go, usando Whatsmeow.
- ClamAV no servidor do serviço QR; é obrigatório para servir anexos.

A extensão de fotos usa `ParticipantAvatarProviderInterface` com a tag `mautic.inbox.participant_avatar`.
A extensão de anexos usa `AttachmentProviderInterface` com a tag `mautic.inbox.attachment`.
Os provedores montam URLs a partir dos objetos já carregados; não consultam o banco nem a rede dentro dos loops de apresentação.
As URLs QR são autenticadas. Sem um provedor correspondente, os canais existentes mantêm seu avatar e seu proxy de mídia.

## Validação real em 6 de outubro de 2026

Uma imagem sintética e um PDF, sem dados pessoais, foram enviados por uma sessão WhatsApp Web para a conta QR conectada.
Os dois entraram na conversa do Inbox sem recarregar a página.

- A imagem foi convertida pelo WhatsApp em JPEG de 960 × 540 pixels, 40.962 bytes. O preview carregou completamente e o download autenticado funcionou.
- O PDF de 1.810 bytes foi baixado pelo link do Inbox e era idêntico byte a byte ao arquivo original.
- Os hashes dos caches privados coincidiram com os hashes declarados pelo WhatsApp.
- Acesso sem login aos dois endpoints retornou HTTP 403, sem expor o arquivo.
- A checagem isolada do ClamAV e do armazenamento, somente em filesystem temporário, bloqueou HTML disfarçado de PDF, HTML disfarçado de imagem e a assinatura inofensiva EICAR.

Nenhum teste de banco, fixture, reset ou migração foi executado no Mautic de produção.
As evidências completas de operação ficam em armazenamento privado; screenshots de contatos não são publicados neste repositório.

## Limites

- A prova real exercitou imagem e PDF. Vídeo, áudio e figurinhas têm cobertura automatizada; não foram enviados neste teste real.
- Anexos anteriores à captura das referências precisam ser reenviados. Não há importação retroativa de histórico.
- O limite de 32 MiB e os limites de concorrência/quota têm testes automatizados; o teste real não enviou arquivos desse tamanho.
- Documentos, inclusive PDFs, são downloads, sem execução inline.
- O envio de arquivos pelo compositor QR e os recibos de leitura QR ainda não fazem parte desta entrega.

Detalhes da validação de conteúdo, armazenamento e configuração do scanner: [WhatsQR README](https://github.com/raphaelcangucu/mautic-whatsqr-bundle/blob/v0.3.0/README.md).
