<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Controller;

use Mautic\CoreBundle\Controller\CommonController;
use Symfony\Component\HttpFoundation\JsonResponse;
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
    public function manifest(): JsonResponse
    {
        $manifest = new JsonResponse([
            'name'             => 'Atendimento',
            'short_name'       => 'Atendimento',
            'description'      => 'Atendimento humano dos canais Meta, no seu aparelho.',
            // start_url e scope apontam para o shell proprio, nao para /s/inbox: o app abre
            // direto na lista de conversas, sem o menu lateral do Mautic.
            'start_url'        => '/s/inbox/app',
            'scope'            => '/s/',
            'display'          => 'standalone',
            'orientation'      => 'portrait',
            'background_color' => '#4e5e9e',
            'theme_color'      => '#4e5e9e',
            'lang'             => 'pt-BR',
            'icons'            => [
                ['src' => '/inbox-icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => '/inbox-icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => '/inbox-icon-512-maskable.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
        ]);

        $manifest->headers->set('Content-Type', 'application/manifest+json');
        // Mesma razao do service worker: a borda nao pode reter isto.
        $manifest->headers->set('Cache-Control', 'no-cache, must-revalidate');

        return $manifest;
    }

    public function icon(string $name): Response
    {
        // Lista fechada de propria vontade: o nome vem da URL, e concatenar caminho com
        // entrada do usuario e como se cria travessia de diretorio.
        $arquivos = [
            '192'          => 'inbox-192.png',
            '512'          => 'inbox-512.png',
            '512-maskable' => 'inbox-512-maskable.png',
            'apple-180'    => 'inbox-apple-180.png',
        ];

        if (!isset($arquivos[$name])) {
            return new Response('', 404);
        }

        $caminho = __DIR__.'/../Assets/icons/'.$arquivos[$name];
        if (!is_readable($caminho)) {
            return new Response('', 404);
        }

        return new Response((string) file_get_contents($caminho), 200, [
            'Content-Type'  => 'image/png',
            'Cache-Control' => 'public, max-age=604800',
        ]);
    }

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
