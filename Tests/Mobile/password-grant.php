<?php
// Drives MobileAuthController::token through the password grant with the real User
// entity, the real ConversationAccess policy and the real SessionStore. Only Doctrine
// and the hasher are stood in for, since those need a database. Needs a Mautic
// autoloader: found where the plugin is installed, or in MAUTIC_VENDOR_AUTOLOAD.
$candidates = array_filter([getenv('MAUTIC_VENDOR_AUTOLOAD') ?: null, __DIR__.'/../../../../vendor/autoload.php', __DIR__.'/../../vendor/autoload.php', __DIR__.'/../../../mautic-meta-bundle/vendor/autoload.php']);
$autoload = current(array_filter($candidates, 'is_file'));
if (!$autoload) { echo "SKIP: no Mautic autoloader found; set MAUTIC_VENDOR_AUTOLOAD\n"; exit(0); }
require $autoload;
spl_autoload_register(function (string $class): void { $p = 'MauticPlugin\\MauticInboxBundle\\'; if (str_starts_with($class, $p) && is_file($f = __DIR__.'/../../'.str_replace('\\', '/', substr($class, strlen($p))).'.php')) require $f; });

use Doctrine\ORM\Decorator\EntityManagerDecorator;
use Doctrine\ORM\{EntityRepository,Query,QueryBuilder};
use Mautic\CoreBundle\Helper\UserHelper;
use Mautic\CoreBundle\Security\Permissions\CorePermissions;
use Mautic\UserBundle\Entity\{PermissionRepository,Role,User};
use MauticPlugin\MauticInboxBundle\Application\Mobile\SessionStore;
use MauticPlugin\MauticInboxBundle\Controller\MobileAuthController;
use MauticPlugin\MauticInboxBundle\Security\ConversationAccess;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface as Authenticatable;

function check(bool $v, string $label): void { if (!$v) throw new RuntimeException($label); }
function bare(string $class): object { return (new ReflectionClass($class))->newInstanceWithoutConstructor(); }
function put(object $target, string $property, mixed $value): void { $c = new ReflectionClass($target); while (!$c->hasProperty($property)) $c = $c->getParentClass(); $c->getProperty($property)->setValue($target, $value); }

final class StubQuery extends Query { public array $rows = []; public array $captured = []; public ?int $limit = null; public function getResult($hydrationMode = self::HYDRATE_OBJECT): array { return $this->rows; } }
final class StubBuilder extends QueryBuilder {
    public function __construct(public StubQuery $query) {}
    public function where($predicates): static { return $this; }
    public function andWhere(...$where): static { return $this; }
    public function setParameter($key, $value, $type = null): static { $this->query->captured[(string) $key] = $value; return $this; }
    public function setMaxResults($maxResults): static { $this->query->limit = null === $maxResults ? null : (int) $maxResults; return $this; }
    public function getQuery(): Query { return $this->query; }
}
final class StubRepository extends EntityRepository { public function __construct(public StubBuilder $builder) {} public function createQueryBuilder($alias, $indexBy = null): QueryBuilder { return $this->builder; } }
final class StubEntityManager extends EntityManagerDecorator {
    /** @var array<int,User> */ public array $byId = []; public array $lookups = [];
    public function __construct(private StubRepository $repository) {}
    public function getRepository($className): EntityRepository { $this->lookups[] = $className; return $this->repository; }
    public function find($className, $id, $lockMode = null, $lockVersion = null): ?object { return $this->byId[(int) $id] ?? null; }
}
/** Compares against the hash the entity carries, as Mautic's own hasher does. */
final class StubHasher implements UserPasswordHasherInterface {
    public function hashPassword(Authenticatable $user, #[SensitiveParameter] string $plainPassword): string { return password_hash($plainPassword, PASSWORD_BCRYPT, ['cost' => 12]); }
    public function isPasswordValid(Authenticatable $user, #[SensitiveParameter] string $plainPassword): bool { return password_verify($plainPassword, (string) $user->getPassword()); }
    public function needsRehash(Authenticatable $user): bool { return false; }
}
final class StubRoles extends PermissionRepository { public function __construct() {} public function getPermissionsByRole(Role $role, $forForm = false): array { return []; } }
final class StubPermissions extends CorePermissions { public function __construct() {} public function getPermissionObjects(): array { return []; } }

function operator(int $id, string $username, string $email, string $secret, bool $inbox, bool $published = true): User {
    $user = new User(); put($user, 'id', $id);
    $user->setUsername($username); $user->setEmail($email); $user->setFirstName('Apple'); $user->setLastName('Review');
    $user->setPassword(password_hash($secret, PASSWORD_BCRYPT, ['cost' => 12])); $user->setIsPublished($published);
    // An admin role is the shortest path through the real permission policy; the
    // operator without one lands on the same refusal a wrong password gets.
    if ($inbox) { $role = new Role(); put($role, 'id', 1); $role->setName('Operators'); $role->setIsAdmin(true); $user->setRole($role); }
    return $user;
}

$query = bare(StubQuery::class); $em = new StubEntityManager(new StubRepository(new StubBuilder($query)));
$hasher = new StubHasher(); $access = new ConversationAccess(new StubPermissions(), new StubRoles(), bare(UserHelper::class));
$root = sys_get_temp_dir().'/mautic_password_grant_'.bin2hex(random_bytes(8)); $store = new SessionStore($root);
$controller = bare(MobileAuthController::class);
$call = function (array $body, string $address = '203.0.113.10') use ($controller, $store, $em, $hasher, $access): array {
    $request = Request::create('/inbox/mobile/token', 'POST', [], [], [], ['REMOTE_ADDR' => $address, 'CONTENT_TYPE' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR));
    $response = $controller->token($request, $store, $em, $hasher, $access);
    return [$response->getStatusCode(), json_decode($response->getContent(), true), $response];
};

$secret = bin2hex(random_bytes(16));
$allowed = operator(42, 'apple-review', 'apple.review@macro.markets', $secret, true); $em->byId[42] = $allowed;

// The name is trimmed and lowercased for the lookup, which asks for two rows so an
// ambiguous identifier can be told apart from a single match.
$query->rows = [$allowed];
[$status, $body, $response] = $call(['grant_type' => 'password', 'username' => '  Apple-Review  ', 'password' => $secret]);
check(200 === $status, 'accepted');
check(is_string($body['access_token'] ?? null) && is_string($body['refresh_token'] ?? null) && 3600 === ($body['expires_in'] ?? null) && 'Bearer' === ($body['token_type'] ?? null), 'token pair');
check(42 === ($body['user']['id'] ?? null) && 'apple.review@macro.markets' === ($body['user']['email'] ?? null) && 'Apple Review' === ($body['user']['name'] ?? null), 'user the app validates');
check([] === array_diff(array_keys($body), ['access_token','refresh_token','token_type','expires_in','user']), 'no user_id or fingerprint in the response');
check(str_contains((string) $response->headers->get('Cache-Control'), 'no-store'), 'not cacheable');
check($store->authenticate($body['access_token'])['user'] === 42, 'session bound to that operator');
check('apple-review' === ($query->captured['id'] ?? null) && 2 === $query->limit, 'lookup normalised and capped at two');
check(User::class === ($em->lookups[0] ?? null), 'queries the user repository');
$first = $body['access_token'];

$raw = file_get_contents($root.'/var/inbox-mobile/sessions.json');
check(hash('sha256', (string) $allowed->getPassword()) === ($store->authenticate($first)['fingerprint'] ?? null), 'fingerprint of the current password');
check(in_array(hash('sha256', (string) $allowed->getPassword()), array_column(json_decode($raw, true)['access'] ?? [], 'fingerprint'), true), 'fingerprint persisted');
check(!str_contains($raw, $secret) && !str_contains($raw, 'apple-review') && !str_contains($raw, '203.0.113.10'), 'no password, identifier or address persisted');

// One refusal for every reason: wrong password, unknown name, ambiguous name, no
// inbox permission and an unpublished operator all answer the same.
[$status, $refusal] = $call(['grant_type' => 'password', 'username' => 'apple-review', 'password' => 'wrong-'.$secret]);
check(400 === $status && 'invalid_grant' === ($refusal['error'] ?? null), 'wrong password refused');
$query->rows = [];
check([400, $refusal] === array_slice($call(['grant_type' => 'password', 'username' => 'absent', 'password' => $secret]), 0, 2), 'unknown identifier answers the same');
$twin = operator(43, 'twin', 'apple.review@macro.markets', $secret, true); $em->byId[43] = $twin; $query->rows = [$allowed, $twin];
check([400, $refusal] === array_slice($call(['grant_type' => 'password', 'username' => 'apple.review@macro.markets', 'password' => $secret]), 0, 2), 'ambiguous identifier picks neither');
$noInbox = operator(44, 'no-inbox', 'no-inbox@macro.markets', $secret, false); $em->byId[44] = $noInbox; $query->rows = [$noInbox];
check([400, $refusal] === array_slice($call(['grant_type' => 'password', 'username' => 'no-inbox', 'password' => $secret]), 0, 2), 'right password without inbox permission answers the same');
$retired = operator(45, 'retired', 'retired@macro.markets', $secret, true, false); $em->byId[45] = $retired; $query->rows = [$retired];
check([400, $refusal] === array_slice($call(['grant_type' => 'password', 'username' => 'retired', 'password' => $secret]), 0, 2), 'unpublished operator answers the same');

// Malformed input is refused before the lookup, so it costs neither a query nor an attempt.
$lookups = count($em->lookups);
foreach ([['username' => '', 'password' => $secret], ['username' => 'x', 'password' => ''], ['username' => str_repeat('a', 255), 'password' => $secret], ['username' => 'x', 'password' => str_repeat('a', 1025)], [], ['username' => ['list'], 'password' => $secret]] as $body) {
    [$status, $out] = $call(['grant_type' => 'password'] + $body);
    check(400 === $status && 'invalid_request' === ($out['error'] ?? null), 'invalid request refused');
}
check($lookups === count($em->lookups), 'invalid requests never reach the database');

// Ten attempts per identifier per hour, counted before the password is checked, so a
// right password is held back too while the window lasts. Other identifiers go on.
$target = operator(46, 'rate-test', 'rate@macro.markets', $secret, true); $em->byId[46] = $target; $query->rows = [$target];
$spent = 0; $last = [0, []];
for ($i = 0; $i < 15; $i++) { $last = $call(['grant_type' => 'password', 'username' => 'rate-test', 'password' => 'wrong'], '198.51.100.7'); if (429 === $last[0]) break; $spent++; }
check(429 === $last[0] && 'rate_limited' === ($last[1]['error'] ?? null), 'rate limited');
check(10 === $spent, 'after exactly ten attempts');
check(429 === $call(['grant_type' => 'password', 'username' => 'rate-test', 'password' => $secret], '198.51.100.7')[0], 'the right password waits out the window too');
$query->rows = [$noInbox];
check(400 === $call(['grant_type' => 'password', 'username' => 'no-inbox', 'password' => 'wrong'], '198.51.100.7')[0], 'the limit is per identifier, not only per address');

// The decoy must cost what a real verification costs, or its own timing would say that
// the name does not exist. Mautic hashes with bcrypt at cost 12.
$decoy = (new ReflectionClass(MobileAuthController::class))->getConstant('DECOY_HASH');
check(str_starts_with($decoy, '$2y$12$'), 'decoy at the cost Mautic hashes with');
$time = fn (string $hash): float => (function () use ($hash) { $start = microtime(true); password_verify('any-attempt', $hash); return microtime(true) - $start; })();
$decoyTime = min($time($decoy), $time($decoy), $time($decoy)); $realTime = min($time((string) $allowed->getPassword()), $time((string) $allowed->getPassword()), $time((string) $allowed->getPassword()));
check(abs($decoyTime - $realTime) / max($decoyTime, $realTime) < 0.15, sprintf('decoy %.0fms against real %.0fms', $decoyTime * 1000, $realTime * 1000));

// A password change leaves the issued session unable to match, and the other grants
// answer as before.
$allowed->setPassword(password_hash('next-'.$secret, PASSWORD_BCRYPT, ['cost' => 12]));
check(hash('sha256', (string) $allowed->getPassword()) !== ($store->authenticate($first)['fingerprint'] ?? null), 'password change breaks the fingerprint');
[$status, $body] = $call(['grant_type' => 'nothing-like-it', 'username' => 'x', 'password' => 'y']);
check(400 === $status && 'unsupported_grant_type' === ($body['error'] ?? null), 'unknown grant still refused');
[$status, $body] = $call(['grant_type' => 'refresh_token', 'refresh_token' => 'absent']);
check(400 === $status && isset($body['error']), 'invalid refresh token still refused');

exec('rm -rf '.escapeshellarg($root));
echo "PASS: password grant accepted, response shape, one refusal for every reason, input guards, per-identifier limit, decoy cost, fingerprint and untouched grants\n";
