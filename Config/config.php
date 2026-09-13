<?php

declare(strict_types=1);

use MauticPlugin\MauticInboxBundle\Controller\InboxController;

return [
    'name' => 'Mautic Omnichannel Inbox',
    'description' => 'Atendimento humano para canais Meta conectado ao CRM do Mautic.',
    'version' => '1.0.1',
    'author' => 'Mautic',
    'routes' => ['main' => [
        'mautic_inbox_index' => ['path' => '/atendimento', 'controller' => InboxController::class.'::index', 'method' => 'GET'],
        'mautic_inbox_stream' => ['path' => '/atendimento/api/stream', 'controller' => InboxController::class.'::updatesStream', 'method' => 'GET'],
        'mautic_inbox_list' => ['path' => '/atendimento/api/conversas', 'controller' => InboxController::class.'::conversations', 'method' => 'GET'],
        'mautic_inbox_detail' => ['path' => '/atendimento/api/conversas/{stateId}', 'controller' => InboxController::class.'::detail', 'method' => 'GET', 'requirements' => ['stateId' => '\\d+']],
        'mautic_inbox_timeline' => ['path' => '/atendimento/api/conversas/{stateId}/historico', 'controller' => InboxController::class.'::timeline', 'method' => 'GET', 'requirements' => ['stateId' => '\\d+']],
        'mautic_inbox_poll' => ['path' => '/atendimento/api/atualizacoes', 'controller' => InboxController::class.'::poll', 'method' => 'GET'],
        'mautic_inbox_take' => ['path' => '/atendimento/api/conversas/{stateId}/assumir', 'controller' => InboxController::class.'::take', 'method' => 'POST', 'requirements' => ['stateId' => '\\d+']],
        'mautic_inbox_state' => ['path' => '/atendimento/api/conversas/{stateId}/estado', 'controller' => InboxController::class.'::state', 'method' => 'POST', 'requirements' => ['stateId' => '\\d+']],
        'mautic_inbox_reply' => ['path' => '/atendimento/api/conversas/{stateId}/responder', 'controller' => InboxController::class.'::reply', 'method' => 'POST', 'requirements' => ['stateId' => '\\d+']],
        'mautic_inbox_note' => ['path' => '/atendimento/api/conversas/{stateId}/nota', 'controller' => InboxController::class.'::note', 'method' => 'POST', 'requirements' => ['stateId' => '\\d+']],
        'mautic_inbox_draft' => ['path' => '/atendimento/api/conversas/{stateId}/rascunho', 'controller' => InboxController::class.'::draft', 'method' => 'PUT', 'requirements' => ['stateId' => '\\d+']],
        'mautic_inbox_canned' => ['path' => '/atendimento/api/respostas-prontas', 'controller' => InboxController::class.'::canned', 'method' => 'POST'],
    ]],
    'menu' => ['main' => [
        'mautic.inbox.menu' => ['id' => 'mautic_inbox', 'route' => 'mautic_inbox_index', 'access' => 'inbox:conversations:view', 'iconClass' => 'ri-customer-service-2-line', 'priority' => 21],
    ]],
];
