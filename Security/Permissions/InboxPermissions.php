<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Security\Permissions;

use Mautic\CoreBundle\Security\Permissions\AbstractPermissions;
use Symfony\Component\Form\FormBuilderInterface;

final class InboxPermissions extends AbstractPermissions
{
    public function __construct(array $params)
    {
        parent::__construct($params);
        $this->addStandardPermissions(['conversations'], false);
        $this->addStandardPermissions(['templates'], false);
        $this->addCustomPermission('scope', ['own'=>1,'waiting'=>2,'all'=>4,'full'=>1024]);
    }
    public function getName(): string { return 'inbox'; }
    public function buildForm(FormBuilderInterface &$builder, array $options, array $data): void
    {
        $this->addStandardFormFields('inbox', 'conversations', $builder, $data);
        $this->addStandardFormFields('inbox', 'templates', $builder, $data);
        $this->addCustomFormFields('inbox','scope',$builder,'mautic.inbox.permissions.scope',['mautic.inbox.permissions.scope.own'=>'own','mautic.inbox.permissions.scope.waiting'=>'waiting','mautic.inbox.permissions.scope.all'=>'all','mautic.core.permissions.full'=>'full'],$data);
    }
}
