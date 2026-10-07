<?php

declare(strict_types=1);
namespace MauticPlugin\MauticInboxBundle\Application\Mobile;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\UserBundle\Entity\User;
use Mautic\CampaignBundle\Entity\Campaign;
use MauticPlugin\MauticInboxBundle\Entity\{CommentContext,ConversationState};
use MauticPlugin\MauticInboxBundle\Security\ConversationAccess;
use MauticPlugin\MauticMcpBundle\Mcp\Tool\Campaign\{SearchCampaignsTool,ReadCampaignFlowTool};
use MauticPlugin\MauticMcpBundle\Mcp\Tool\Analytics\AnalyticsTool;

final class AssistantCampaignReports
{
    public function __construct(private SearchCampaignsTool $search,private ReadCampaignFlowTool $flows,private AnalyticsTool $analytics,private EntityManagerInterface $em,private ConversationAccess $access){}
    public function flow(int $id): array {if($id<1)throw new \InvalidArgumentException('campaign_id_required');return ($this->flows)('get',$id);}
    public static function channels(array $events): array
    {
        $out=[];foreach($events as $event){$type=(string)($event['type']??'');if(str_starts_with($type,'meta.instagram.comment'))$out[]='instagram_comments';if(str_contains($type,'whatsapp')||str_contains(strtolower($type),'whatsqr'))$out[]='whatsapp';}
        return array_values(array_unique($out));
    }
    public static function day(?string $date=null): array
    {
        $zone=new \DateTimeZone('America/Sao_Paulo');$date??=(new \DateTimeImmutable('now',$zone))->format('Y-m-d');
        $start=\DateTimeImmutable::createFromFormat('!Y-m-d',$date,$zone);
        if(!$start||$start->format('Y-m-d')!==$date)throw new \InvalidArgumentException('invalid_date');
        $utc=new \DateTimeZone('UTC');return ['date'=>$date,'timezone'=>'America/Sao_Paulo','from'=>$start->setTimezone($utc)->format(DATE_ATOM),'to'=>$start->modify('+1 day')->setTimezone($utc)->format(DATE_ATOM)];
    }
    public function report(array $call): array
    {
        $period=self::day(isset($call['date'])?(string)$call['date']:null);
        $page=max(1,min(100,(int)($call['page']??1)));$list=($this->search)(mb_substr((string)($call['query']??''),0,120),50,$page);
        $mode=(string)($call['report']??'today');if(!in_array($mode,['today','channels'],true))throw new \InvalidArgumentException('invalid_report');
        $stats=[];
        // The legacy analytics adapter has global aggregates: never use it for
        // own-only operators, and use UTC boundaries for the stored UTC dates.
        if($mode==='today'&&$this->access->granted($call['_user'],'campaign:campaigns:viewother')){
            $raw=($this->analytics)('campaign_performance',$period['from'],$period['to'],'day',500,1);
            foreach($raw['rows']??[] as $row)$stats[(int)$row['id']]=$row;
        }
        $items=[];
        foreach($list['items']??[] as $item){
            $id=(int)$item['id'];$entity=$this->em->find(Campaign::class,$id);if(!$entity instanceof Campaign)continue;
            $this->em->refresh($entity);$flow=$this->flow($id);
            $item['is_published']=$entity->isPublished();$item['channels']=self::channels($flow['events']??[]);$item['event_count']=$flow['eventCount']??0;
            $item['created_on_date']=substr((new \DateTimeImmutable($item['date_added']))->setTimezone(new \DateTimeZone($period['timezone']))->format(DATE_ATOM),0,10)===$period['date'];
            if(isset($stats[$id]))$item['activity']=array_intersect_key($stats[$id],array_flip(['contacts','triggeredEvents','emailDeliveries','clicks']));
            $items[]=$item;
        }
        $channel=(string)($call['channel']??'');if($channel!=='')$items=array_values(array_filter($items,static fn($i)=>in_array($channel,$i['channels'],true)));
        return $period+['report'=>$mode,'items'=>$items,'page'=>$page,'scanned'=>count($list['items']??[]),'total_visible'=>$list['total']??null,'has_more'=>(bool)($list['hasMore']??false),'next_page'=>$list['nextPage']??null,'activity_available'=>$mode==='today'&&$this->access->granted($call['_user'],'campaign:campaigns:viewother'),'metric_note'=>'Activity covers distinct campaign event logs and attributed email deliveries/clicks, not total messages or comments. Open/failure sums from the legacy joined analytics are not included. Channel membership comes from actual flow event types.'];
    }
    public function comments(int $campaignId,User $user,?string $date=null): array
    {
        $flow=$this->flow($campaignId);$bindings=[];
        foreach($flow['events']??[] as $event){if(($event['type']??'')!=='meta.instagram.comment')continue;$p=$event['properties']??[];$asset=(int)($p['asset_id']??0);$media=(string)($p['media_id']??'');if($asset>0&&$media!=='')$bindings[$asset.':'.$media]=['asset_id'=>$asset,'media_id'=>$media];}
        $period=$date!==null?self::day($date):[];$counts=[];
        foreach($bindings as $binding){
            $qb=$this->em->createQueryBuilder()->select('COUNT(DISTINCT ctx.id)')->from(CommentContext::class,'ctx')->join('ctx.publicConversation','c')->join(ConversationState::class,'s','WITH','s.conversation = c')->join('ctx.message','m')->where('c.asset = :asset')->andWhere('c.channel = :channel')->andWhere('ctx.mediaId = :media')->andWhere('m.direction = :direction')->setParameters(['asset'=>$binding['asset_id'],'channel'=>'instagram','media'=>$binding['media_id'],'direction'=>'inbound']);
            $this->access->apply($qb,$user);
            if($period)$qb->andWhere('m.dateAdded >= :from AND m.dateAdded < :to')->setParameter('from',new \DateTimeImmutable($period['from']))->setParameter('to',new \DateTimeImmutable($period['to']));
            $counts[]=$binding+['count'=>(int)$qb->getQuery()->getSingleScalarResult()];
        }
        return ['campaign_id'=>$campaignId,'campaign_name'=>$flow['campaignName'],'bindings'=>$counts,'registered_comments'=>array_sum(array_column($counts,'count')),'period'=>$period?:'all_time','metric'=>'registered_inbound_comments_on_flow_publications','scope'=>'current_user_inbox','note'=>'Comments matched by exact publication and account, not campaign conversions or keyword matches. Historical comments without an immutable source context are not counted. Multiple campaigns sharing a publication can include the same comments.'];
    }
}
