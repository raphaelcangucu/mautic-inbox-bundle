<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Controller;

use Mautic\CoreBundle\Controller\CommonController;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serve o service worker fora da sessao.
 *
 * Publico de proposito: o navegador revalida o worker em segundo plano, e um redirecionamento
 * para a tela de login naquele momento quebraria a atualizacao sem dizer nada. O arquivo nao
 * contem segredo algum — a chave publica VAPID chega pela API autenticada, nao por aqui.
 */
class PwaAssetController extends CommonController
{
    public function serviceWorker(): Response
    {
        $file = __DIR__.'/../Assets/dist/inbox-sw.js';
        if (!is_readable($file)) {
            return new Response('// service worker nao compilado', 404, ['Content-Type' => 'application/javascript']);
        }

        return new Response((string) file_get_contents($file), 200, [
            'Content-Type' => 'application/javascript; charset=UTF-8',
            // Sem isto a Cloudflare retem o worker na borda: o navegador pede a versao nova,
            // recebe a antiga, e a equipe fica presa numa versao que ninguem consegue
            // atualizar — sem erro visivel em lugar nenhum. E a falha classica desta
            // arquitetura, e a unica defesa e este cabecalho.
            'Cache-Control' => 'no-cache, must-revalidate',
            // Permite registrar com escopo mais amplo que o diretorio do proprio arquivo.
            'Service-Worker-Allowed' => '/',
        ]);
    }
}
