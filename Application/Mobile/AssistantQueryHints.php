<?php

declare(strict_types=1);
namespace MauticPlugin\MauticInboxBundle\Application\Mobile;

/** Read-only hydration for IDs explicitly named by the operator, never mutations. */
final class AssistantQueryHints
{
    public static function calls(string $question): array
    {
        $calls=[];
        $nouns=[
            'inbox'=>'(?:atendimento|conversa|coment[aá]rio|conversation|comment|conversaci[oó]n|comentario)',
            'campaign'=>'(?:campanha|campaign|campa[nñ]a)',
            'contact'=>'(?:contato|contact|contacto)',
        ];
        foreach($nouns as $kind=>$noun){
            $other=implode('|',array_diff_key($nouns,[$kind=>true]));
            if(!preg_match('/\b'.$noun.'\b(?:(?!\b(?:'.$other.')\b)[^\n.]){0,90}?\bID\s*([1-9][0-9]{0,8})\b/iu',$question,$match))continue;
            $id=(int)$match[1];
            if($kind==='inbox'){$calls[]=['tool'=>'mautic_read_inbox','resource'=>'conversation','id'=>$id];$calls[]=['tool'=>'mautic_read_inbox','resource'=>'timeline','id'=>$id,'filters'=>['limit'=>20]];}
            elseif($kind==='campaign')$calls[]=['tool'=>'mautic_fetch_campaign','id'=>$id];
            else $calls[]=['tool'=>'mautic_fetch_contact','id'=>$id];
        }
        return array_slice($calls,0,3);
    }
}
