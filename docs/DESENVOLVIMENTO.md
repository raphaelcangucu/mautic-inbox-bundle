# Desenvolvimento e validação

Este repositório contém somente o bundle. Os testes PHP precisam do bootstrap, dependências e banco de teste de uma instalação Mautic com o Meta Bundle compatível. Não é uma aplicação PHP independente.

Na raiz de um checkout Mautic de desenvolvimento, instale ambos os bundles e use banco descartável. Com DDEV:

```bash
ddev exec php bin/phpunit -c app/phpunit.xml.dist plugins/MauticInboxBundle/Tests
ddev exec php bin/console lint:twig plugins/MauticInboxBundle/Resources/views
```

O frontend Svelte usa as dependências de desenvolvimento declaradas em `package.json`. Instale a árvore reproduzível do lockfile e execute as verificações estáticas, o build e os testes de comportamento:

```bash
npm --prefix plugins/MauticInboxBundle ci
npm --prefix plugins/MauticInboxBundle run check
npm --prefix plugins/MauticInboxBundle run build
npm --prefix plugins/MauticInboxBundle test
```

## Validação herdada da implementação

- Inbox: 32 testes, 190 asserções.
- Meta Bundle: 79 testes, 218 asserções; sete avisos de depreciação preexistentes.
- JavaScript: formatação segura e notificações, incluindo desbloqueio de áudio, deduplicação, silêncio e restauração do favicon.
- Contas próprias de teste: recebimento e resposta no Instagram e Messenger; resposta pública no Facebook; identificação, fotos e prévias de imagens; alerta visual real.

Vídeos têm controles e fallback implementados, mas não se afirma validação real de todos os formatos. Reels não tiveram teste real específico. O teste automatizado de áudio verifica sua execução; não equivale a uma avaliação auditiva. Esta extração não repete testes reais ou envia novas mensagens.

## Origem e versões

Código extraído de [mautic@1664d19a406ef64f026700f617cb69f6d9fc19e2](https://github.com/raphaelcangucu/mautic/commit/1664d19a406ef64f026700f617cb69f6d9fc19e2), diretório `plugins/MauticInboxBundle`, tag `inbox-v1.0.0`.

O repositório independente usa tags `vX.Y.Z`. A primeira foi `v1.0.1`, com o mesmo código de comportamento, documentação ampliada e nome Composer próprio. A linha `v1.1.x` requer o Meta Bundle [v0.14.0](https://github.com/raphaelcangucu/mautic-meta-bundle/tree/v0.14.0) ou outra versão compatível com `^0.14.0`.

Mudanças no contrato `InboxIntegrationInterface` exigem revisão coordenada dos dois plugins. Antes de publicar outra versão, execute os testes relevantes na instalação Mautic e confira a compatibilidade declarada no Composer. Credenciais e dados reais nunca devem entrar no repositório.
