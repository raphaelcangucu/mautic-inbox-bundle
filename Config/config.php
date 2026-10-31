<?php

declare(strict_types=1);

use MauticPlugin\MauticInboxBundle\Controller\InboxController;

return [
    'name' => 'Mautic Omnichannel Inbox',
    'description' => 'Atendimento humano para canais Meta conectado ao CRM do Mautic.',
    'version' => '1.14.0',
    'author' => 'Mautic',
    'routes' => ['main' => [
        'mautic_inbox_mail_connections' => ['path' => '/inbox/mail-connections', 'controller' => \MauticPlugin\MauticInboxBundle\Controller\MailConnectionsController::class.'::index', 'method' => ['GET', 'POST']],
        'mautic_inbox_mobile_device_authorize' => ['path' => '/inbox/mobile/device-authorize', 'controller' => \MauticPlugin\MauticInboxBundle\Controller\MobileAuthController::class.'::authorizeDevice', 'method' => ['GET','POST']],
        'mautic_inbox_mobile_authorize' => ['path' => '/inbox/mobile/authorize', 'controller' => \MauticPlugin\MauticInboxBundle\Controller\MobileAuthController::class.'::authorize', 'method' => ['GET','POST']],
        'mautic_inbox_ai' => ['path'=>'/inbox/ai','controller'=>\MauticPlugin\MauticInboxBundle\Controller\AiController::class.'::index','method'=>'GET'],
        'mautic_inbox_ai_data' => ['path'=>'/inbox/ai/data','controller'=>\MauticPlugin\MauticInboxBundle\Controller\AiController::class.'::data','method'=>'GET'],
        'mautic_inbox_ai_action' => ['path'=>'/inbox/ai/action','controller'=>\MauticPlugin\MauticInboxBundle\Controller\AiController::class.'::action','method'=>'POST'],
        'mautic_inbox_ai_available' => ['path'=>'/inbox/api/conversations/{stateId}/ai','controller'=>\MauticPlugin\MauticInboxBundle\Controller\AiController::class.'::available','method'=>'GET','requirements'=>['stateId'=>'\\d+']],
        'mautic_inbox_ai_assign' => ['path'=>'/inbox/api/conversations/{stateId}/ai','controller'=>\MauticPlugin\MauticInboxBundle\Controller\AiController::class.'::assign','method'=>'POST','requirements'=>['stateId'=>'\\d+']],
        'mautic_inbox_ai_retry' => ['path'=>'/inbox/api/conversations/{stateId}/ai/retry','controller'=>\MauticPlugin\MauticInboxBundle\Controller\AiController::class.'::retry','method'=>'POST','requirements'=>['stateId'=>'\\d+']],
        'mautic_inbox_index' => ['path' => '/inbox', 'controller' => InboxController::class.'::index', 'method' => 'GET'],
        'mautic_inbox_conversation' => ['path' => '/inbox/conversations/{stateId}', 'controller' => InboxController::class.'::index', 'method' => 'GET', 'requirements' => ['stateId' => '\\d+']],
        'mautic_inbox_stream' => ['path' => '/inbox/api/stream', 'controller' => InboxController::class.'::updatesStream', 'method' => 'GET'],
        'mautic_inbox_list' => ['path' => '/inbox/api/conversations', 'controller' => InboxController::class.'::conversations', 'method' => 'GET'],
        'mautic_inbox_detail' => ['path' => '/inbox/api/conversations/{stateId}', 'controller' => InboxController::class.'::detail', 'method' => 'GET', 'requirements' => ['stateId' => '\\d+']],
        'mautic_inbox_timeline' => ['path' => '/inbox/api/conversations/{stateId}/history', 'controller' => InboxController::class.'::timeline', 'method' => 'GET', 'requirements' => ['stateId' => '\\d+']],
        'mautic_inbox_media' => ['path' => '/inbox/api/media/{messageId}', 'controller' => InboxController::class.'::media', 'method' => 'GET', 'requirements' => ['messageId' => '\\d+']],
        'mautic_inbox_poll' => ['path' => '/inbox/api/updates', 'controller' => InboxController::class.'::poll', 'method' => 'GET'],
        'mautic_inbox_take' => ['path' => '/inbox/api/conversations/{stateId}/take', 'controller' => InboxController::class.'::take', 'method' => 'POST', 'requirements' => ['stateId' => '\\d+']],
        'mautic_inbox_state' => ['path' => '/inbox/api/conversations/{stateId}/state', 'controller' => InboxController::class.'::state', 'method' => 'POST', 'requirements' => ['stateId' => '\\d+']],
        'mautic_inbox_moderation' => ['path' => '/inbox/api/conversations/{stateId}/moderation', 'controller' => InboxController::class.'::moderation', 'method' => 'POST', 'requirements' => ['stateId' => '\\d+']],
        'mautic_inbox_templates' => ['path' => '/inbox/api/conversations/{stateId}/templates', 'controller' => InboxController::class.'::templates', 'method' => 'GET', 'requirements' => ['stateId' => '\\d+']],
        'mautic_inbox_reply' => ['path' => '/inbox/api/conversations/{stateId}/reply', 'controller' => InboxController::class.'::reply', 'method' => 'POST', 'requirements' => ['stateId' => '\\d+']],
        'mautic_inbox_retry' => ['path' => '/inbox/api/outbound/{outboundId}/retry', 'controller' => InboxController::class.'::retry', 'method' => 'POST', 'requirements' => ['outboundId' => '\\d+']],
        'mautic_inbox_email_options' => ['path' => '/inbox/api/conversations/{stateId}/email-options', 'controller' => InboxController::class.'::emailOptions', 'method' => 'GET', 'requirements' => ['stateId' => '\\d+']],
        'mautic_inbox_email_apply' => ['path' => '/inbox/api/conversations/{stateId}/email-actions', 'controller' => InboxController::class.'::emailApply', 'method' => 'POST', 'requirements' => ['stateId' => '\\d+']],
        'mautic_inbox_note' => ['path' => '/inbox/api/conversations/{stateId}/note', 'controller' => InboxController::class.'::note', 'method' => 'POST', 'requirements' => ['stateId' => '\\d+']],
        'mautic_inbox_draft' => ['path' => '/inbox/api/conversations/{stateId}/draft', 'controller' => InboxController::class.'::draft', 'method' => 'PUT', 'requirements' => ['stateId' => '\\d+']],
        'mautic_inbox_app' => ['path' => '/inbox/app', 'controller' => \MauticPlugin\MauticInboxBundle\Controller\PwaShellController::class.'::index', 'method' => 'GET'],
        'mautic_inbox_app_conversation' => ['path' => '/inbox/app/conversations/{stateId}', 'controller' => \MauticPlugin\MauticInboxBundle\Controller\PwaShellController::class.'::index', 'method' => 'GET', 'requirements' => ['stateId' => '\\d+']],
        'mautic_inbox_push_config' => ['path' => '/inbox/api/push/config', 'controller' => \MauticPlugin\MauticInboxBundle\Controller\PushController::class.'::config', 'method' => 'GET'],
        'mautic_inbox_push_subscribe' => ['path' => '/inbox/api/push/subscriptions', 'controller' => \MauticPlugin\MauticInboxBundle\Controller\PushController::class.'::subscribe', 'method' => 'POST'],
        'mautic_inbox_push_unsubscribe' => ['path' => '/inbox/api/push/subscriptions', 'controller' => \MauticPlugin\MauticInboxBundle\Controller\PushController::class.'::unsubscribe', 'method' => 'DELETE'],
        'mautic_inbox_canned' => ['path' => '/inbox/api/canned-responses', 'controller' => InboxController::class.'::canned', 'method' => 'POST'],
        'mautic_inbox_canned_update' => ['path' => '/inbox/api/canned-responses/{responseId}', 'controller' => InboxController::class.'::updateCanned', 'method' => 'PUT', 'requirements' => ['responseId' => '\\d+']],
        'mautic_inbox_canned_delete' => ['path' => '/inbox/api/canned-responses/{responseId}', 'controller' => InboxController::class.'::deleteCanned', 'method' => 'DELETE', 'requirements' => ['responseId' => '\\d+']],
        // Canal de e-mail. O prefixo "email/" e "messages/" nao colide com email-options e
        // email-actions, que sao do chip de e-mail do CRM dentro da conversa.
        'mautic_inbox_email_attachment' => ['path' => '/inbox/api/email/{messageId}/attachments/{index}', 'controller' => \MauticPlugin\MauticInboxBundle\Controller\EmailController::class.'::attachment', 'method' => 'GET', 'requirements' => ['messageId' => '\\d+', 'index' => '\\d{1,4}']],
        'mautic_inbox_message_original' => ['path' => '/inbox/api/messages/{messageId}/original', 'controller' => \MauticPlugin\MauticInboxBundle\Controller\EmailController::class.'::original', 'method' => 'GET', 'requirements' => ['messageId' => '\\d+']],
        'mautic_inbox_forms' => ['path' => '/inbox/api/forms', 'controller' => \MauticPlugin\MauticInboxBundle\Controller\EmailController::class.'::forms', 'method' => 'GET'],
    ],
    'public' => [
        'mautic_inbox_review_webchat' => ['path'=>'/inbox/review/webchat/{publicKey}','controller'=>\MauticPlugin\MauticInboxBundle\Controller\ReviewWebChatController::class.'::show','method'=>'GET','defaults'=>['_stateless'=>true],'requirements'=>['publicKey'=>'pub_[a-zA-Z0-9_-]+']],
        'mautic_inbox_mobile_magic_code' => ['path' => '/inbox/mobile/magic-code', 'controller' => \MauticPlugin\MauticInboxBundle\Controller\MobileAuthController::class.'::magicCode', 'method' => 'POST'],
        'mautic_inbox_mobile_device' => ['path' => '/inbox/mobile/device', 'controller' => \MauticPlugin\MauticInboxBundle\Controller\MobileAuthController::class.'::device', 'method' => 'POST'],
        'mautic_inbox_mobile_config' => ['path' => '/inbox/mobile/config', 'controller' => \MauticPlugin\MauticInboxBundle\Controller\MobileAuthController::class.'::config', 'method' => 'GET'],
        'mautic_inbox_mobile_token' => ['path' => '/inbox/mobile/token', 'controller' => \MauticPlugin\MauticInboxBundle\Controller\MobileAuthController::class.'::token', 'method' => 'POST'],
        'mautic_inbox_mobile_api' => ['path' => '/inbox/mobile/api/{resource}', 'controller' => \MauticPlugin\MauticInboxBundle\Controller\MobileApiController::class.'::api', 'method' => ['GET','POST','PUT','DELETE'], 'requirements' => ['resource' => '[a-z0-9/-]+']],
        'mautic_inbox_service_worker' => ['path' => '/inbox-sw.js', 'controller' => \MauticPlugin\MauticInboxBundle\Controller\PwaAssetController::class.'::serviceWorker', 'method' => 'GET'],
        'mautic_inbox_manifest' => ['path' => '/inbox-manifest.webmanifest', 'controller' => \MauticPlugin\MauticInboxBundle\Controller\PwaAssetController::class.'::manifest', 'method' => 'GET'],
        'mautic_inbox_icon' => ['path' => '/inbox-icon-{name}.png', 'controller' => \MauticPlugin\MauticInboxBundle\Controller\PwaAssetController::class.'::icon', 'method' => 'GET', 'requirements' => ['name' => '[0-9a-z-]+']],
        // Links assinados do canal de e-mail: formulario de pesquisa (30 dias), original no app
        // (5 minutos, amarrado ao usuario) e descadastro de um clique (RFC 8058).
        'mautic_inbox_form_link' => ['path' => '/inbox/f/{token}', 'controller' => \MauticPlugin\MauticInboxBundle\Controller\PublicEmailController::class.'::form', 'method' => 'GET', 'requirements' => ['token' => '[A-Za-z0-9_-]{8,128}']],
        'mautic_inbox_mobile_original' => ['path' => '/inbox/mobile/original/{token}', 'controller' => \MauticPlugin\MauticInboxBundle\Controller\PublicEmailController::class.'::original', 'method' => 'GET', 'requirements' => ['token' => '[A-Za-z0-9_-]{8,128}']],
        'mautic_inbox_email_unsubscribe' => ['path' => '/inbox/u/{token}', 'controller' => \MauticPlugin\MauticInboxBundle\Controller\PublicEmailController::class.'::unsubscribe', 'method' => ['GET', 'POST'], 'requirements' => ['token' => '[A-Za-z0-9_-]{8,128}']],
    ]],
    'menu' => ['main' => [
        'mautic.inbox.menu' => ['id' => 'mautic_inbox', 'route' => 'mautic_inbox_index', 'access' => 'inbox:conversations:view', 'iconClass' => 'ri-customer-service-2-line', 'priority' => 21],
    ]],
];
