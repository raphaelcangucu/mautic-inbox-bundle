<?php

declare(strict_types=1);
namespace MauticPlugin\MauticInboxBundle\Application\Mobile;
use MauticPlugin\MauticInboxBundle\Application\InboxException;

/** Positive allowlist; model output is never passed verbatim to a mutation. */
final class AssistantActionPolicy
{
    public static function normalize(array $input): array
    {
        $tool=$input['tool']??'';$id=$input['id']??null;
        if(!is_int($id)||$id<1)throw new InboxException('Escolha um alvo exato antes de preparar a ação.',422);
        $out=['tool'=>$tool,'id'=>$id];
        if($tool==='mautic_reply_inbox'){
            $body=$input['body']??null;$mode=$input['reply_mode']??'private';
            if(!is_string($body)||trim($body)===''||mb_strlen($body)>4000||!in_array($mode,['public','private'],true))throw new InboxException('Resposta inválida.',422);
            return $out+['body'=>trim($body),'reply_mode'=>$mode];
        }
        if($tool==='campaign_update'){
            $data=$input['data']??[];if(!is_array($data)||!$data||array_diff(array_keys($data),['name','description','allowRestart']))throw new InboxException('Esta ação altera somente nome, descrição e reinício da campanha. Para mudar o fluxo, abra o editor do Mautic.',422);
            foreach($data as $key=>$value){if($key==='allowRestart'){if(!is_bool($value))throw new InboxException('Alteração inválida.',422);}elseif(!is_string($value)||mb_strlen($value)>($key==='name'?200:4000)||($key==='name'&&trim($value)===''))throw new InboxException('Alteração inválida.',422);}
            return $out+['data'=>$data];
        }
        if($tool==='campaign_add_contacts'){
            $ids=$input['contact_ids']??[];if(!is_array($ids)||!$ids||count($ids)>10)throw new InboxException('Escolha de 1 a 10 contatos exatos.',422);
            foreach($ids as $contact)if(!is_int($contact)||$contact<1)throw new InboxException('Contato inválido.',422);
            return $out+['contact_ids'=>array_values(array_unique($ids))];
        }
        if($tool==='inbox_transfer'){
            $target=$input['user_id']??null;if(!is_int($target)||$target<1)throw new InboxException('Escolha um operador exato.',422);
            return $out+['user_id'=>$target];
        }
        throw new InboxException('Ferramenta de escrita não autorizada.',403);
    }
}
