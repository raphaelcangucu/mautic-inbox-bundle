<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Application\Email;

/**
 * HTML de e-mail recebido nunca vai cru para a tela.
 *
 * O saneamento e por lista de permissao, com o HTMLPurifier que o proprio Mautic ja traz
 * (exercise/htmlpurifier-bundle): nada de script, iframe, object, embed, form, base, meta,
 * link, svg, atributo on*, javascript:, data: fora de imagem ou CSS externo. O iframe sem
 * scripts e o CSP do web sao a segunda camada, nao a unica.
 *
 * Imagem remota continua no HTML de proposito: quem a bloqueia ate o clique e o CSP do iframe
 * (img-src), que assim tambem cobre a imagem que escapasse daqui. url() em CSS sai sempre:
 * fundo remoto e o mesmo pixel de rastreio com outra roupa, e nenhum clique o devolve.
 */
final class EmailHtmlSanitizer
{
    /** Acima disto o resto e descartado antes do parser: e-mail de 2 MiB de HTML e abuso. */
    public const MAX_INPUT_BYTES = 2_097_152;

    private ?\HTMLPurifier $purifier = null;

    /**
     * @param (callable(string): ?string)|null $cid recebe o Content-ID (sem < >) e devolve a URL
     *                                              que substitui o "cid:", ou null para tirar a imagem
     */
    public function sanitize(string $html, ?callable $cid = null): string
    {
        if ('' === trim($html)) { return ''; }
        if (strlen($html) > self::MAX_INPUT_BYTES) { $html = substr($html, 0, self::MAX_INPUT_BYTES); }
        if (!mb_check_encoding($html, 'UTF-8')) { $html = mb_convert_encoding($html, 'UTF-8', 'UTF-8'); }

        // A imagem embutida aponta para outra parte do proprio e-mail. O purificador nao
        // conhece o esquema cid:, entao a troca pela URL do anexo acontece antes; o que sair
        // daqui ainda passa pela validacao de URL dele.
        $html = (string) preg_replace_callback(
            '/(\bsrc\s*=\s*)(["\']?)cid:([^"\'\s>]+)\2/i',
            static function (array $match) use ($cid): string {
                $url = null === $cid ? null : $cid(trim(rawurldecode($match[3]), '<>'));

                return is_string($url) && '' !== $url ? $match[1].'"'.htmlspecialchars($url, ENT_QUOTES).'"' : $match[1].'""';
            },
            $html,
        );

        $clean = $this->purifier()->purify($html);

        // O purificador valida url() em CSS como qualquer outra URL e deixaria passar um fundo
        // https. O atributo ja sai normalizado por ele (aspas duplas, entidades escapadas), o
        // que torna seguro olhar para dentro com uma expressao.
        $clean = (string) preg_replace_callback(
            '/\sstyle="([^"]*)"/i',
            static function (array $match): string {
                $style = html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $style = (string) preg_replace('/url\s*\(\s*(?:"[^"]*"|\'[^\']*\'|[^)]*)\s*\)/i', '', $style);
                $kept = [];
                foreach (explode(';', $style) as $declaration) {
                    [$property, $value] = array_pad(explode(':', $declaration, 2), 2, '');
                    if ('' !== trim($property) && '' !== trim($value)) { $kept[] = trim($property).':'.trim($value); }
                }

                return [] === $kept ? '' : ' style="'.htmlspecialchars(implode(';', $kept), ENT_QUOTES, 'UTF-8').'"';
            },
            $clean,
        );

        // Sem <base>, um endereco relativo nao leva a lugar nenhum que o remetente quisesse; e
        // e por onde um "java script:" desmontado pelo purificador sobrevive como texto.
        $clean = (string) preg_replace_callback(
            '/\s(href|src)="([^"]*)"/i',
            static function (array $match): string {
                $value = html_entity_decode($match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');

                return preg_match('#^(https?://|mailto:|data:image/(png|gif|jpe?g);)#i', $value) ? $match[0] : '';
            },
            $clean,
        );

        return trim($clean);
    }

    private function purifier(): \HTMLPurifier
    {
        if (null !== $this->purifier) { return $this->purifier; }
        $config = \HTMLPurifier_Config::createDefault();
        $config->set('Core.Encoding', 'UTF-8');
        // Sem cache em disco: o plugin nao tem diretorio proprio garantido como gravavel, e a
        // definicao fica guardada nesta instancia durante a requisicao.
        $config->set('Cache.DefinitionImpl', null);
        // Transitional para manter o vocabulario de e-mail: table com width e bgcolor, font,
        // center, align.
        $config->set('HTML.Doctype', 'XHTML 1.0 Transitional');
        $config->set('HTML.ForbiddenElements', ['script', 'style', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'form', 'input', 'button', 'select', 'textarea', 'base', 'meta', 'link', 'svg', 'math']);
        $config->set('HTML.SafeIframe', false);
        $config->set('HTML.SafeObject', false);
        $config->set('HTML.SafeEmbed', false);
        $config->set('HTML.TargetBlank', true);
        $config->set('HTML.TargetNoreferrer', true);
        $config->set('HTML.TargetNoopener', true);
        $config->set('HTML.Nofollow', true);
        $config->set('Attr.EnableID', false);
        $config->set('Attr.AllowedFrameTargets', ['_blank']);
        // data: so vale para imagem: o esquema data do purificador aceita apenas png, gif e jpeg.
        $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true, 'data' => true]);
        $config->set('CSS.AllowTricky', false);
        $config->set('CSS.Proprietary', false);
        $config->set('CSS.AllowImportant', false);
        $config->set('Core.RemoveProcessingInstructions', true);
        $config->set('Core.ConvertDocumentToFragment', true);

        return $this->purifier = new \HTMLPurifier($config);
    }
}
