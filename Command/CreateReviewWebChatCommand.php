<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\UserBundle\Entity\User;
use MauticPlugin\MauticInboxBundle\Security\ConversationAccess;
use MauticPlugin\MauticMetaBundle\Domain\AssetType;
use MauticPlugin\MauticMetaBundle\Entity\{MetaAsset, MetaConnection};
use MauticPlugin\MauticWebChatBundle\Application\Presentation;
use MauticPlugin\MauticWebChatBundle\Entity\ChatWidget;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'mautic:inbox:review-webchat:create', description: 'Create a dedicated review WebChat for an own-only operator, using existing tables.')]
final class CreateReviewWebChatCommand extends Command
{
    public function __construct(private EntityManagerInterface $em, private ConversationAccess $access)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('username', null, InputOption::VALUE_REQUIRED);
        $this->addOption('base-url', null, InputOption::VALUE_REQUIRED, 'Public HTTPS Mautic origin.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $base = rtrim((string) $input->getOption('base-url'), '/');
        $url = parse_url($base);
        if (!is_array($url) || ($url['scheme'] ?? '') !== 'https' || empty($url['host']) || isset($url['user']) || isset($url['pass']) || isset($url['port']) || isset($url['query']) || isset($url['fragment']) || !empty($url['path'])) {
            throw new \RuntimeException('An HTTPS origin without credentials or path is required.');
        }
        $user = $this->em->getRepository(User::class)->findOneBy(['username' => (string) $input->getOption('username')]);
        $scope = $user instanceof User ? $this->access->scope($user) : [];
        if (!$user instanceof User || $user->isAdmin() || empty($scope['own']) || !empty($scope['waiting']) || !empty($scope['all'])) {
            throw new \RuntimeException('A published own-only operator is required.');
        }
        $externalId = 'inbox-review-webchat-user-'.$user->getId();
        if ($this->em->getRepository(MetaAsset::class)->findOneBy(['externalId' => $externalId])) {
            throw new \RuntimeException('Review asset already exists; no changes made.');
        }
        $lead = (new Lead())->setFirstname('Apple')->setLastname('Review')->setOwner($user)->setCreatedBy($user->getId());
        // A WebChat needs an asset association, but this connection is deliberately
        // inactive: it has no Graph credentials and must never be polled by Meta.
        $connection = (new MetaConnection())->setName('App Review · WebChat only')->setAppId($externalId)->setStatus('inactive')
            ->setEncryptedAppSecret('')->setEncryptedAccessToken('')->setEncryptedVerifyToken('');
        $asset = (new MetaAsset())->setConnection($connection)->setName('App Review · WebChat')->setExternalId($externalId)
            ->setType(AssetType::FacebookPage)->setStatus('inactive')->setIsPublished(true);
        $widget = (new ChatWidget())->setAsset($asset)->setName('App Review · WebChat')->setPublished(true)
            ->setGreeting('Hello! Send a message to test Mautic Inbox.')->setOfflineMessage('Your message is saved in the evaluation inbox.')
            ->setRequireName(false)->setRequireEmail(false)->setRequirePhone(false)->setAllowedDomains([$url['host']])->setAccentColor('#244184')
            ->setPresentation(Presentation::sanitize(['theme'=>'essential', 'brandName'=>'Mautic Inbox', 'fields'=>['name'=>'hidden','email'=>'hidden','phone'=>'hidden'], 'overrides'=>['essential'=>['options'=>['font'=>'system','appearance'=>'light','density'=>'compact']]], 'translations'=>['en'=>['greeting'=>'Hello! Send a message to test Mautic Inbox.', 'offline'=>'Your message is saved in the evaluation inbox.']]]));
        $this->em->wrapInTransaction(function () use ($lead, $connection, $asset, $widget, $user): void {
            $this->em->persist($lead);
            $this->em->persist($connection);
            $this->em->flush();
            $asset->setSettings(['webchat'=>true, 'inbox_review_only'=>true, 'inbox_default_assignee_id'=>$user->getId(), 'inbox_review_contact_id'=>$lead->getId()]);
            $this->em->persist($asset);
            $this->em->persist($widget);
        });
        $output->writeln(json_encode(['widget_id'=>$widget->getId(), 'asset_id'=>$asset->getId(), 'contact_id'=>$lead->getId(), 'user_id'=>$user->getId(), 'page_url'=>$base.'/inbox/review/webchat/'.$widget->getPublicKey(), 'graphConnectionActive'=>false], JSON_THROW_ON_ERROR));
        return Command::SUCCESS;
    }
}
