<?php
declare(strict_types=1);
// Isolated guard doubles. No kernel, autoloader or database connection.
namespace Doctrine\ORM { interface EntityManagerInterface {} }
namespace Mautic\LeadBundle\Entity {
    class Lead { public function __construct(private int $id) {} public function getId(): int { return $this->id; } }
    class DoNotContact { public const IS_CONTACTABLE = 0; }
}
namespace Mautic\LeadBundle\Model { class DoNotContact { public function __construct(private int $status = 0) {} public function isContactable($lead, $channel): int { return $this->status; } } }
namespace MauticPlugin\MauticMetaBundle\Domain { enum ConsentStatus { case Unknown; case OptedIn; case OptedOut; } }
namespace MauticPlugin\MauticMetaBundle\Entity {
    class MetaAsset {}
    class MetaContactIdentity {
        public function __construct(private ?\Mautic\LeadBundle\Entity\Lead $contact, private \MauticPlugin\MauticMetaBundle\Domain\ConsentStatus $consent, private ?\DateTimeImmutable $out = null, private ?\DateTimeImmutable $in = null) {}
        public function getContact() { return $this->contact; }
        public function getConsentStatus() { return $this->consent; }
        public function getOptedOutAt() { return $this->out; }
        public function getConsentedAt() { return $this->in; }
    }
    class MetaContactIdentityRepository {
        public function __construct(private ?MetaContactIdentity $identity) {}
        public function findForAssetAndExternalId($asset, $external) { return $this->identity; }
    }
}
namespace {
    use Mautic\LeadBundle\Entity\Lead;
    use Mautic\LeadBundle\Model\DoNotContact;
    use MauticPlugin\MauticMetaBundle\Domain\ConsentStatus;
    use MauticPlugin\MauticMetaBundle\Entity\{MetaAsset, MetaContactIdentity, MetaContactIdentityRepository};
    use MauticPlugin\MauticMetaBundle\Application\Contact\IdentityManager;
    require $argv[1] ?? throw new RuntimeException('Supply the patched identity manager');
    $lead = new Lead(3511);
    $cases = [
        [null, 0, null],
        [new MetaContactIdentity(null, ConsentStatus::Unknown), 0, null],
        [new MetaContactIdentity($lead, ConsentStatus::Unknown), 0, null],
        [new MetaContactIdentity(new Lead(3512), ConsentStatus::Unknown), 0, 'different Mautic contact'],
        [new MetaContactIdentity(null, ConsentStatus::OptedOut), 0, 'opted out'],
        [new MetaContactIdentity(null, ConsentStatus::Unknown, new DateTimeImmutable('2026-10-07'), new DateTimeImmutable('2026-10-06')), 0, 'later WhatsApp opt-out'],
        [new MetaContactIdentity(null, ConsentStatus::Unknown), 1, 'Do Not Contact'],
    ];
    foreach ($cases as [$identity, $dnc, $error]) {
        $manager = new IdentityManager(new MetaContactIdentityRepository($identity), new class implements \Doctrine\ORM\EntityManagerInterface {}, new DoNotContact($dnc));
        try {
            $manager->assertCanSend(new MetaAsset(), '15550000001', $lead, true);
            if ($error !== null) throw new RuntimeException('Contact restriction was bypassed');
        } catch (DomainException $failure) {
            if ($error === null || !str_contains($failure->getMessage(), $error)) throw $failure;
        }
    }
    echo "7 WhatsApp identity guard cases passed; no database or kernel.\n";
}
