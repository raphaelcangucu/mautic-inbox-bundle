<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Controller;

use Mautic\CoreBundle\Controller\CommonController;
use Mautic\CoreBundle\Helper\UserHelper;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Routing\RouterInterface;

/** Compatibility link; Multi Mail owns the independent configuration module. */
final class MailConnectionsController extends CommonController
{
    public function index(Request $request, UserHelper $users, RouterInterface $router): Response
    {
        if (!$users->getUser(true)?->isAdmin()) { throw $this->createAccessDeniedException(); }
        if (!$router->getRouteCollection()->get('mautic_multimail_connections')) {
            throw $this->createNotFoundException('Instale o plugin Multi Mail para configurar conexões de e-mail.');
        }
        $query = [];
        $edit = $request->query->get('edit', '');
        if (is_string($edit) && preg_match('/^[a-f0-9]{32}$/D', $edit)) { $query['edit'] = $edit; }

        return $this->redirectToRoute('mautic_multimail_connections', $query, 303);
    }
}
