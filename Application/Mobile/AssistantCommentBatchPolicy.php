<?php
declare(strict_types=1);
namespace MauticPlugin\MauticInboxBundle\Application\Mobile;

/** Batch capacity applies only to fully read, individually authorized public comments. */
final class AssistantCommentBatchPolicy
{
    public const PAGE_SIZE=10;
    public static function scanIntent(string $question): bool
    {
        return preg_match('/(?:coment|comment|spam)/iu',$question)===1
            && preg_match('/(?:todos|todas|varr|sweep|all\b|every)/iu',$question)===1;
    }
    public static function needsUpgrade(string $question,array $payload): bool
    {
        return self::scanIntent($question)
            && preg_match('/(?:trat|limp|marc|respond|reply|classif|moderat|clean|handle|agir)/iu',$question)===1
            && (!in_array('comment_batches',(array)($payload['supported_actions']??[]),true)
                || !in_array('inbox_moderate',(array)($payload['supported_actions']??[]),true));
    }
    public static function explicitScan(string $question,array $payload): bool
    {
        return in_array('comment_batches',(array)($payload['supported_actions']??[]),true)
            && self::scanIntent($question);
    }
    public static function replyEligible(array $source): bool
    {
        return in_array($source['channel']??'', ['instagram','facebook'],true) && !empty($source['asset_active'])
            && !empty($source['body']) && !empty($source['publication_id']) && ($source['lifecycle']??'')==='open'
            && !empty($source['needs_response']) && empty($source['already_replied']) && empty($source['deleted'])
            && empty($source['body_truncated']) && empty($source['own_comment']) && empty($source['spam']) && empty($source['blocked']) && empty($source['ai_assigned']);
    }
    public static function classifications(array $drafts,array $items): array
    {
        $known=[];foreach(array_slice($drafts,0,20) as $draft){
            if(!is_array($draft)||!is_int($draft['id']??null)||isset($known[$draft['id']]))continue;
            if(!in_array($draft['classification']??'', ['spam','legitimate','uncertain'],true)||!is_string($draft['reason']??null))continue;
            $known[$draft['id']]=['classification'=>$draft['classification'],'reason'=>mb_substr($draft['reason'],0,500)];
        }
        return array_map(static function($item)use($known){
            $decision=!empty($item['context_complete'])?($known[$item['id']]??['classification'=>'uncertain','reason'=>'Classificação indisponível.']):['classification'=>'uncertain','reason'=>'Contexto completo indisponível.'];
            return ['id'=>$item['id'],'name'=>$item['name'],'body'=>$item['body'],'account'=>$item['account']['name']??'', 'reply_available'=>!empty($item['reply_available'])]+$decision;
        },$items);
    }
    public static function batchReply(array $action,array $items,bool $supported): bool
    {
        if(!$supported||($action['tool']??'')!=='mautic_reply_inbox'||($action['reply_mode']??'')!=='public')return false;
        foreach($items as $item)if(($item['id']??0)===$action['id'])return !empty($item['context_complete'])&&!empty($item['reply_available']);
        return false;
    }
    /** The model cannot invent remaining totals or claim actions were applied. */
    public static function summary(string $question,array $classifications,int $proposals,bool $hasMore): string
    {
        $counts=array_count_values(array_column($classifications,'classification'));
        $total=count($classifications);$spam=$counts['spam']??0;$legitimate=$counts['legitimate']??0;$uncertain=$counts['uncertain']??0;
        if(preg_match('/\b(?:comments|scan|sweep|classify|reply|every)\b/iu',$question))return "Classified $total comments in this batch: $spam spam, $legitimate legitimate and $uncertain uncertain. Prepared $proposals actions for review. Uncertain comments were preserved. ".($hasMore?'More comments are available in the next batch.':'This is the last available batch.').' No actions have been applied.';
        if(preg_match('/(?:todos los|analiza|clasifica)/iu',$question))return "Clasificados $total comentarios en este lote: $spam spam, $legitimate legítimos y $uncertain inciertos. Preparadas $proposals acciones para revisión. Los inciertos se conservaron. ".($hasMore?'Hay más comentarios en el próximo lote.':'Este es el último lote disponible.').' No se aplicó ninguna acción.';
        return "Classificados $total comentários neste lote: $spam spam, $legitimate legítimos e $uncertain incertos. Preparadas $proposals ações para revisão. Os incertos foram preservados. ".($hasMore?'Há mais comentários no próximo lote.':'Este é o último lote disponível.').' Nenhuma ação foi aplicada.';
    }
}
