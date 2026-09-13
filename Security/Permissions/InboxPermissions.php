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
    }
    public function getName(): string { return 'inbox'; }
    public function buildForm(FormBuilderInterface &$builder, array $options, array $data): void
    {
        $this->addStandardFormFields('inbox', 'conversations', $builder, $data);
        $this->addStandardFormFields('inbox', 'templates', $builder, $data);
    }
}
