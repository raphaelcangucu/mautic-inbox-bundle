<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Application\Ai;

/** Same orchestration in production and isolated flow tests; side effects are explicit ports. */
final class CommentAutomationExecutor
{
    public function process(array $config, array $source, ?array $existing, callable $generate, callable $current, callable $save, callable $spam, callable $reply): ?array
    {
        if ($existing || ($config['mode']??'off')==='off' || !CommentAutomationPolicy::eligible($source,$config)) return null;
        $run = ['key'=>CommentAutomationPolicy::key($source['asset_id'],$source['comment_id']), 'config_key'=>$config['key'],
            'config_revision'=>$config['revision'], 'state'=>$source['state_id'], 'asset_id'=>$source['asset_id'],
            'comment_id'=>$source['comment_id'], 'publication_id'=>$source['publication_id'], 'inbound'=>$source['inbound_id'],
            'fingerprint'=>CommentAutomationPolicy::fingerprint($source), 'state_version'=>$source['state_version'],
            'document_key'=>$config['document_key'], 'document_version'=>$config['document_version'], 'status'=>'generating', 'date'=>gmdate(DATE_ATOM)];
        $run['comment_excerpt']=mb_substr($source['body'],0,1000);
        $save($run);
        try {
            $run += CommentAutomationPolicy::decision($generate(),$source);
            if ($config['mode']==='review' || $run['decision']==='review') {
                $run['status']='review'; $save($run); return $run;
            }
            return $this->execute($config,$run,$current,$save,$spam,$reply);
        } catch (\Throwable $error) {
            // An action may have completed remotely. Never automatically retry after a lost response.
            if($error instanceof \DomainException && $error->getMessage()==='public_reply_unavailable_or_limit') {
                $run['status']='review';$run['reason']='public_reply_unavailable_or_limit';
            } elseif($error instanceof \DomainException && $error->getMessage()==='comment_context_changed') {
                $run['status']='cancelled';$run['reason']='context_or_authorization_changed';
            } else {
                $run['status']=str_starts_with($run['status'],'executing') ? 'uncertain' : 'failed';
                $run['reason']='execution_failed';
            }
            $save($run); return $run;
        }
    }

    public function execute(array $config, array &$run, callable $current, callable $save, callable $spam, callable $reply): array
    {
        $fresh = $current();
        if (!$fresh || !CommentAutomationPolicy::eligible($fresh,$config) || $run['fingerprint']!==CommentAutomationPolicy::fingerprint($fresh) || $run['state_version']!==$fresh['state_version']) {
            $run['status']='cancelled'; $run['reason']='context_or_authorization_changed'; $save($run); return $run;
        }
        if ($run['decision']==='spam' && $config['mark_spam_enabled']) {
            $run['status']='executing_spam'; $save($run); $spam($run,$fresh); $run['status']='spam';
        } elseif ($run['decision']==='legitimate' && $config['public_reply_enabled']) {
            $run['status']='executing_reply'; $save($run); $run['job']=$reply($run,$fresh); $run['status']='queued';
        } else { $run['status']='review'; $run['reason']='action_disabled_or_ambiguous'; }
        $save($run); return $run;
    }
}
