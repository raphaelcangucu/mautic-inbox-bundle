<?php

declare(strict_types=1);

use MauticPlugin\MauticInboxBundle\Controller\InboxController;

return [
    'name' => 'Mautic Omnichannel Inbox',
    'description' => 'Atendimento humano para canais Meta conectado ao CRM do Mautic.',
    'version' => '1.0.15',
    'author' => 'Mautic',
    'routes' => ['main' => [
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
        'mautic_inbox_templates' => ['path' => '/inbox/api/conversations/{stateId}/templates', 'controller' => InboxController::class.'::templates', 'method' => 'GET', 'requirements' => ['stateId' => '\\d+']],
        'mautic_inbox_reply' => ['path' => '/inbox/api/conversations/{stateId}/reply', 'controller' => InboxController::class.'::reply', 'method' => 'POST', 'requirements' => ['stateId' => '\\d+']],
        'mautic_inbox_retry' => ['path' => '/inbox/api/outbound/{outboundId}/retry', 'controller' => InboxController::class.'::retry', 'method' => 'POST', 'requirements' => ['outboundId' => '\\d+']],
        'mautic_inbox_note' => ['path' => '/inbox/api/conversations/{stateId}/note', 'controller' => InboxController::class.'::note', 'method' => 'POST', 'requirements' => ['stateId' => '\\d+']],
        'mautic_inbox_draft' => ['path' => '/inbox/api/conversations/{stateId}/draft', 'controller' => InboxController::class.'::draft', 'method' => 'PUT', 'requirements' => ['stateId' => '\\d+']],
        'mautic_inbox_canned' => ['path' => '/inbox/api/canned-responses', 'controller' => InboxController::class.'::canned', 'method' => 'POST'],
        'mautic_inbox_canned_update' => ['path' => '/inbox/api/canned-responses/{responseId}', 'controller' => InboxController::class.'::updateCanned', 'method' => 'PUT', 'requirements' => ['responseId' => '\\d+']],
        'mautic_inbox_canned_delete' => ['path' => '/inbox/api/canned-responses/{responseId}', 'controller' => InboxController::class.'::deleteCanned', 'method' => 'DELETE', 'requirements' => ['responseId' => '\\d+']],
    ]],
    'menu' => ['main' => [
        'mautic.inbox.menu' => ['id' => 'mautic_inbox', 'route' => 'mautic_inbox_index', 'access' => 'inbox:conversations:view', 'iconClass' => 'ri-customer-service-2-line', 'priority' => 21],
    ]],
];
