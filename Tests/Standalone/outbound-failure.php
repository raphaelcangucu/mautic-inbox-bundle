<?php
declare(strict_types=1);
require __DIR__.'/../../Application/OutboundFailure.php';
use MauticPlugin\MauticInboxBundle\Application\OutboundFailure;
function check(bool $value): void { if (!$value) throw new RuntimeException('Failure diagnostic regression'); }
check(OutboundFailure::describe('{"message":"Local anti-spam cooldown active for this recipient (60 seconds)."}') === ['code'=>'local_cooldown','seconds'=>60]);
check(OutboundFailure::describe('token=SECRET') === ['code'=>'delivery_failed','seconds'=>null]);
check(OutboundFailure::describe(null) === ['code'=>null,'seconds'=>null]);
check(OutboundFailure::describe('{"message":"WhatsApp identity is linked to a different Mautic contact."}') === ['code'=>'contact_identity_mismatch','seconds'=>null]);
$review = ['code'=>'meta_messaging_review_required','seconds'=>null];
$english = 'Cannot message users who are not admins, developers or testers of the app until pages_messaging permission is reviewed and the app is live.';
$portuguese = 'Não é possível enviar mensagens a usuários que não sejam administradores, desenvolvedores ou testadores do aplicativo até que a permissão pages_messaging seja analisada e o aplicativo esteja ativo.';
foreach ([$english, $portuguese] as $message) {
    foreach ([10,200,'10','200'] as $code) {
        check(OutboundFailure::describe(json_encode(['message'=>$message,'code'=>$code,'http_status'=>400,'endpoint'=>'https://graph.facebook.com/123/messages?access_token=SECRET'])) === $review);
    }
}
check(OutboundFailure::describe(json_encode(['message'=>$english,'code'=>4])) === ['code'=>'delivery_failed','seconds'=>null]);
check(OutboundFailure::describe('{"message":"A different pages_messaging permission error","code":10}') === ['code'=>'delivery_failed','seconds'=>null]);
check(OutboundFailure::describe('{"message":"pages_messaging reviewed live",') === ['code'=>'delivery_failed','seconds'=>null]);
check(OutboundFailure::describe('"pages_messaging reviewed live"') === ['code'=>'delivery_failed','seconds'=>null]);
echo "16 failure diagnostic cases passed; no database or kernel.\n";
