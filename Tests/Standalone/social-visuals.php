<?php
// Filesystem-only unit fixtures. No kernel or database connection.
namespace MauticPlugin\MauticMetaBundle\Infrastructure { interface MetaGraphClientInterface { public function get(object $connection,string $path,array $query=[]):array; } }
namespace MauticPlugin\MauticMetaBundle\Application\Facebook { class PageConnectionResolver { public function resolve(object $asset):object{return $asset->getConnection();} } }
namespace MauticPlugin\MauticMetaBundle\Application\Instagram { class InstagramAccountResolver { public function __construct(object $graph){} public function resolve(object $asset):string{return $asset->getExternalId();} } }
namespace MauticPlugin\MauticInboxBundle\Entity { class ConversationState { public function __construct(private object $conversation){} public function getConversation():object{return $this->conversation;} } }
namespace {
require dirname(__DIR__,2).'/Application/Mobile/PublicationContext.php';
require dirname(__DIR__,2).'/Application/Mobile/SocialVisuals.php';
use MauticPlugin\MauticInboxBundle\Application\Mobile\SocialVisuals;
use MauticPlugin\MauticInboxBundle\Application\Mobile\PublicationContext;
use MauticPlugin\MauticInboxBundle\Entity\ConversationState;
use MauticPlugin\MauticMetaBundle\Application\Facebook\PageConnectionResolver;
$root=sys_get_temp_dir().'/mautic-social-visuals-test-'.bin2hex(random_bytes(8));mkdir($root.'/project',0700,true);
$graph=new class implements \MauticPlugin\MauticMetaBundle\Infrastructure\MetaGraphClientInterface {
 public array $calls=[]; public bool $mismatch=false;
 public function get(object $connection,string $path,array $query=[]):array {
  $this->calls[]=[$path,$query];$image='https://scontent.example.fbcdn.net/picture.jpg';
  if($path==='11111')return ['id'=>$path,'picture'=>['data'=>['url'=>$image]]];
  if($path==='33333')return ['id'=>$path,'from'=>['id'=>$this->mismatch?'99999':'22222','picture'=>['data'=>['url'=>$image]]]];
  if($path==='44444'){if(str_contains($query['fields'],'full_picture'))throw new \RuntimeException('Video field unsupported');return ['id'=>$path,'description'=>'Reel caption','picture'=>$image,'permalink_url'=>'https://www.facebook.com/reel/44444/'];}
  throw new \RuntimeException('Permission unavailable');
 }
};
$asset=new class {public function getId():int{return 1;}public function getExternalId():string{return '11111';}public function getConnection():object{return (object)[];}};
$conversation=new class($asset){public function __construct(private object $asset){}public function getAsset():object{return $this->asset;}public function getChannel():string{return 'facebook';}};
$state=new ConversationState($conversation);$pages=new PageConnectionResolver();
$publications=new PublicationContext($graph,$pages,$root.'/project');$service=new SocialVisuals($graph,$pages,$publications,$root.'/project');
$raw=['recipient'=>'22222','origins'=>[['comment_id'=>'33333','media_id'=>'44444','body'=>'First author comment']]];
if($service->cached($state,$raw)['avatar_url']!==null||$graph->calls)throw new \RuntimeException('List triggered Graph');
$result=$service->resolve($state,$raw);
if(!$result['avatar_url']||!$result['asset_avatar_url']||$result['publication']['caption']!=='Reel caption'||!$result['publication']['image'])throw new \RuntimeException('Visual hydration failed');
if(isset($result['publication']['body'])||isset($result['publication']['comment_id']))throw new \RuntimeException('Comment content cached as post metadata');
$second=$raw;$second['origins'][0]['body']='Second author comment';$second['origins'][0]['comment_id']='66666';
if(isset($service->resolve($state,$second)['publication']['body']))throw new \RuntimeException('Different comment body leaked through shared post cache');
// Existing cache written by an older release must be filtered on read too.
$legacy=$root.'/inbox-mobile-private/social-visuals/'.hash('sha256','post:1:44444').'.json';
$data=json_decode(file_get_contents($legacy),true);$data['value']['publication']['body']='Legacy private comment';$data['value']['publication']['comment_id']='77777';file_put_contents($legacy,json_encode($data));
if(isset($service->cached($state,$second)['publication']['body'])||isset($service->cached($state,$second)['publication']['comment_id']))throw new \RuntimeException('Legacy cache leaked commenter data');
$count=count($graph->calls);$service->resolve($state,$raw);if(count($graph->calls)!==$count)throw new \RuntimeException('Cache failed');
$other=$raw;$other['recipient']='55555';$graph->mismatch=true;
if($service->resolve($state,$other)['avatar_url']!==null)throw new \RuntimeException('Different author photo leaked');
$count=count($graph->calls);$service->resolve($state,$other);if(count($graph->calls)!==$count)throw new \RuntimeException('Negative cache failed');
foreach(['http://scontent.example.fbcdn.net/x','https://user@x.fbcdn.net/x','https://fbcdn.net.evil.test/x'] as $unsafe)if(PublicationContext::image($unsafe)!==null)throw new \RuntimeException('Unsafe image accepted');
$iterator=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST);foreach($iterator as $file){$file->isDir()?rmdir($file->getPathname()):unlink($file->getPathname());}rmdir($root);
echo "PASS social cache, author scope, safe URLs and Facebook reel image fallback (no database)\n";
}
