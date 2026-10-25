<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Form\Type;

use Doctrine\ORM\EntityManagerInterface;
use MauticPlugin\MauticMetaBundle\Entity\MetaAsset;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/** A configuracao da acao "Iniciar conversa por e-mail": conta, nome no From, assunto e texto. */
final class EmailConversationActionType extends AbstractType
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $choices = [];
        foreach ($this->entityManager->getRepository(MetaAsset::class)->findBy(['type' => 'email_account', 'status' => 'active', 'isPublished' => true], ['name' => 'ASC']) as $asset) {
            $address = (string) ($asset->getSettings()['email_address'] ?? '');
            $choices['' !== $address ? $asset->getName().' · '.$address : $asset->getName()] = $asset->getId();
        }
        $builder
            ->add('asset_id', ChoiceType::class, ['label' => 'mautic.inbox.campaign.email_account', 'choices' => $choices, 'constraints' => [new NotBlank()]])
            ->add('from_name', TextType::class, ['label' => 'mautic.inbox.campaign.email_from_name', 'required' => false, 'attr' => ['tooltip' => 'mautic.inbox.campaign.email_from_name_tooltip', 'maxlength' => 100], 'constraints' => [new Length(max: 100)]])
            ->add('subject', TextType::class, ['label' => 'mautic.inbox.campaign.email_subject', 'attr' => ['maxlength' => 255], 'constraints' => [new NotBlank(), new Length(max: 255)]])
            ->add('message', TextareaType::class, ['label' => 'mautic.inbox.campaign.email_message', 'attr' => ['rows' => 10, 'tooltip' => 'mautic.inbox.campaign.email_message_tooltip', 'maxlength' => 4000], 'constraints' => [new NotBlank(), new Length(max: 4000)]]);
    }

    public function getBlockPrefix(): string
    {
        return 'inbox_email_campaign_action';
    }
}
