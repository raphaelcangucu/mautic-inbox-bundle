<?php
// Drives ContactDirectory's do-not-contact state and writer with the real Lead entity
// and the real DoNotContact entries. Only Mautic's DNC model, the permissions and
// Doctrine are stood in for, since those need a database. Needs a Mautic autoloader:
// found where the plugin is installed, or in MAUTIC_VENDOR_AUTOLOAD.
$candidates = array_filter([getenv('MAUTIC_VENDOR_AUTOLOAD') ?: null, __DIR__.'/../../../../vendor/autoload.php', __DIR__.'/../../vendor/autoload.php', __DIR__.'/../../../mautic-meta-bundle/vendor/autoload.php']);
$autoload = current(array_filter($candidates, 'is_file'));
if (!$autoload) { echo "SKIP: no Mautic autoloader found; set MAUTIC_VENDOR_AUTOLOAD\n"; exit(0); }
require $autoload;
spl_autoload_register(function (string $class): void { $p = 'MauticPlugin\\MauticInboxBundle\\'; if (str_starts_with($class, $p) && is_file($f = __DIR__.'/../../'.str_replace('\\', '/', substr($class, strlen($p))).'.php')) require $f; });

use Doctrine\ORM\Decorator\EntityManagerDecorator;
use Doctrine\ORM\EntityRepository;
use Mautic\CoreBundle\Security\Permissions\CorePermissions;
use Mautic\LeadBundle\Entity\DoNotContact as Entry;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Model\DoNotContact as DncModel;
use Mautic\UserBundle\Entity\User;
use MauticPlugin\MauticInboxBundle\Application\ContactDoNotContact;
use MauticPlugin\MauticInboxBundle\Application\InboxException;
use MauticPlugin\MauticInboxBundle\Application\Mobile\ContactDirectory;

function check(bool $v, string $label): void { if (!$v) throw new RuntimeException($label); }
function bare(string $class): object { return (new ReflectionClass($class))->newInstanceWithoutConstructor(); }
function put(object $target, string $property, mixed $value): void { $c = new ReflectionClass($target); while (!$c->hasProperty($property)) $c = $c->getParentClass(); $c->getProperty($property)->setValue($target, $value); }

/** Reads the contact's own entries, so the stub and the production check agree. */
final class StubDnc extends DncModel {
    public array $added = []; public array $removed = [];
    public function __construct() {}
    public function isContactable(Lead $contact, $channel) {
        foreach ($contact->getDoNotContact() as $entry) { if ($entry->getChannel() === $channel) { return (int) $entry->getReason(); } }
        return Entry::IS_CONTACTABLE;
    }
    public function addDncForContact($contact, $channel, $reason = Entry::BOUNCED, ?string $comments = '', $persist = true, $checkCurrentStatus = true, $allowUnsubscribeOverride = false) {
        $this->added[] = ['channel' => $channel, 'reason' => $reason, 'comments' => $comments];
        return true;
    }
    public function removeDncForContact($contact, $channel, $persist = true, $reason = null): bool {
        $this->removed[] = ['channel' => $channel, 'reason' => $reason];
        return true;
    }
}
final class StubPermissions extends CorePermissions { public function __construct(public array $granted = []) {} public function isGranted($requestedPermission, $mode = 'MATCH_ALL', $userEntity = null, $allowUnknown = false): array|bool { return in_array($requestedPermission, $this->granted, true); } }
final class StubRepository extends EntityRepository { public function __construct() {} public function findOneBy(array $criteria, ?array $orderBy = null): ?object { return null; } }
final class StubEntityManager extends EntityManagerDecorator {
    public array $persisted = [];
    public function __construct(private Lead $contact) {}
    public function getRepository($className): EntityRepository { return new StubRepository(); }
    public function find($className, $id, $lockMode = null, $lockVersion = null): ?object { return Lead::class === $className ? $this->contact : null; }
    public function persist($object): void { $this->persisted[] = $object; }
    public function flush($entity = null): void {}
}

/** @param array<int,int> $reasons */
function contactWith(array $reasons, string $channel = 'email'): Lead {
    $contact = new Lead();
    foreach ($reasons as $reason) { $contact->addDoNotContactEntry((new Entry())->setChannel($channel)->setReason($reason)); }
    return $contact;
}
function directoryFor(Lead $contact, ?DncModel $dnc, array $granted = [], bool $admin = true): ContactDirectory {
    $directory = bare(ContactDirectory::class);
    put($directory, 'em', new StubEntityManager($contact));
    put($directory, 'permissions', new StubPermissions($granted));
    put($directory, 'dnc', new ContactDoNotContact($dnc));
    return $directory;
}
function operator(bool $admin): User { $user = bare(User::class); put($user, 'id', 7); put($user, 'firstName', 'Ana'); put($user, 'lastName', 'Rocha'); if ($admin) { $role = bare(Mautic\UserBundle\Entity\Role::class); put($role, 'isAdmin', true); put($user, 'role', $role); } return $user; }

$admin = operator(true);

// A contact nobody blocked: the switch is off and may be turned on.
$free = contactWith([]);
$state = directoryFor($free, new StubDnc())->doNotContactState($free);
check(false === $state['blocked'] && null === $state['reason'] && true === $state['editable'], 'A contact with no entry reads as contactable');

// Turning it on records the manual reason and who asked, never a bounce.
$dnc = new StubDnc();
$result = directoryFor($free, $dnc)->setDoNotContact(1, $admin, true);
check([['channel' => 'email', 'reason' => Entry::MANUAL, 'comments' => 'Inbox: Ana Rocha']] === $dnc->added, 'Blocking writes MANUAL with the operator name');
check([] === $dnc->removed, 'Blocking removes nothing');
check(isset($result['do_not_contact']['blocked']) && 1 === $result['id'], 'The writer answers with the new state');

// Turning it off takes only the manual entry, so a contact's own unsubscribe survives.
$manual = contactWith([Entry::MANUAL]);
$dnc = new StubDnc();
$state = directoryFor($manual, $dnc)->doNotContactState($manual);
check(true === $state['blocked'] && 'manual' === $state['reason'] && true === $state['editable'], 'A manual block is the one the app may undo');
directoryFor($manual, $dnc)->setDoNotContact(1, $admin, false);
check([['channel' => 'email', 'reason' => Entry::MANUAL]] === $dnc->removed, 'Unblocking scopes the removal to the manual reason');

// What the contact asked for is not the operator's to undo.
$theirs = contactWith([Entry::UNSUBSCRIBED]);
$dnc = new StubDnc();
$state = directoryFor($theirs, $dnc)->doNotContactState($theirs);
check(true === $state['blocked'] && 'unsubscribed' === $state['reason'] && false === $state['editable'], 'An unsubscribe is reported and locked');
try { directoryFor($theirs, $dnc)->setDoNotContact(1, $admin, false); throw new RuntimeException('An unsubscribe was overridden'); }
catch (InboxException $e) { check(422 === $e->httpStatus && str_contains($e->getMessage(), 'não receber'), 'The refusal says the contact asked for it'); }
check([] === $dnc->removed && [] === $dnc->added, 'A refused change touches nothing');

// A bounced address stays blocked too, with its own reason.
$bounced = contactWith([Entry::BOUNCED]);
$state = directoryFor($bounced, new StubDnc())->doNotContactState($bounced);
check(true === $state['blocked'] && 'bounced' === $state['reason'] && false === $state['editable'], 'A bounce is reported and locked');
try { directoryFor($bounced, new StubDnc())->setDoNotContact(1, $admin, false); throw new RuntimeException('A bounce was cleared'); }
catch (InboxException $e) { check(str_contains($e->getMessage(), 'erro permanente'), 'The refusal names the bounce'); }

// Reading is not editing: viewing a contact never carries the right to silence them.
$viewer = operator(false);
$dnc = new StubDnc();
try { directoryFor($free, $dnc, ['lead:leads:viewother'], false)->setDoNotContact(1, $viewer, true); throw new RuntimeException('A viewer silenced a contact'); }
catch (InboxException $e) { check(403 === $e->httpStatus, 'Without edit permission the change is refused'); }
check([] === $dnc->added, 'The refused viewer wrote nothing');
$editor = directoryFor($free, $dnc, ['lead:leads:viewother', 'lead:leads:editother'], false)->setDoNotContact(1, $viewer, true);
check(1 === count($dnc->added), 'Edit permission is enough; admin is not required');

// An installation without the DNC service says so instead of pretending to block.
$plain = contactWith([]);
$state = directoryFor($plain, null)->doNotContactState($plain);
check(false === $state['blocked'] && false === $state['editable'], 'No DNC service means no switch');
try { directoryFor($plain, null)->setDoNotContact(1, $admin, true); throw new RuntimeException('Blocked without a DNC service'); }
catch (InboxException $e) { check(422 === $e->httpStatus, 'The missing service is refused, not ignored'); }

echo "PASS: do-not-contact state, manual block and release, unsubscribe and bounce kept, permission and missing service refused\n";
