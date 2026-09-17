<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Application;

/**
 * A etiqueta de cache dos arquivos compilados, tirada da data deles.
 *
 * Existia uma etiqueta escrita a mao em cada template, e as duas ficaram para tras: o /s/inbox
 * pedia o CSS com uma data de duas semanas antes, entao o navegador servia a folha velha e
 * nenhuma mudanca de layout aparecia por mais que estivesse publicada. Dentro do app instalado e
 * pior, porque la nao ha barra de endereco nem recarregar forcado — o atendente ficaria preso na
 * versao que baixou na primeira visita, sem caminho de saida que nao fosse reinstalar.
 *
 * Tirar a etiqueta da data do proprio arquivo remove a etapa que alguem precisava lembrar de
 * fazer. Uma publicacao que mude o arquivo muda a etiqueta; uma que nao mude, nao invalida nada.
 */
final class AssetVersion
{
    private static ?string $cache = null;

    public static function current(): string
    {
        if (null !== self::$cache) {
            return self::$cache;
        }

        $base = __DIR__.'/../Assets';
        // O maior entre os dois: o CSS e o bundle mudam em publicacoes diferentes, e uma etiqueta
        // so precisa cobrir os dois.
        $marca = max(
            (int) @filemtime($base.'/dist/inbox-app.js'),
            (int) @filemtime($base.'/css/inbox.css')
        );

        return self::$cache = (string) ($marca ?: time());
    }
}
