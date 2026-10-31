<?php
// Drives the assistant sessions against a real database: the entity mapping, the repository's
// SQL and the service on top of it, over an in-memory SQLite built from the entity metadata.
// The unit test beside this one shapes turns in memory; this one answers whether the queries
// run at all. Needs a Mautic autoloader and pdo_sqlite.
$candidates = array_filter([getenv('MAUTIC_VENDOR_AUTOLOAD') ?: null, __DIR__.'/../../../../vendor/autoload.php', __DIR__.'/../../vendor/autoload.php', __DIR__.'/../../../mautic-meta-bundle/vendor/autoload.php']);
$autoload = current(array_filter($candidates, 'is_file'));
if (!$autoload) { echo "SKIP: no Mautic autoloader found; set MAUTIC_VENDOR_AUTOLOAD\n"; exit(0); }
if (!extension_loaded('pdo_sqlite')) { echo "SKIP: pdo_sqlite is not loaded\n"; exit(0); }
require $autoload;
spl_autoload_register(function (string $class): void { $p = 'MauticPlugin\\MauticInboxBundle\\'; if (str_starts_with($class, $p) && is_file($f = __DIR__.'/../../'.str_replace('\\', '/', substr($class, strlen($p))).'.php')) require $f; });

$users = dirname((new ReflectionClass(\Mautic\UserBundle\Entity\User::class))->getFileName());
if (!is_dir($users)) { echo "SKIP: Mautic's UserBundle entities were not found\n"; exit(0); }

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\{EntityManager,EntityManagerInterface,ORMSetup};
use Doctrine\ORM\Mapping\Driver\StaticPHPDriver;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\{AbstractManagerRegistry,ManagerRegistry};
use Mautic\UserBundle\Entity\{Permission,Role,User};
use MauticPlugin\MauticInboxBundle\Application\Mobile\AssistantSessions;
use MauticPlugin\MauticInboxBundle\Entity\{AssistantSession,AssistantSessionRepository};

function check(bool $value, string $what): void { if (!$value) throw new RuntimeException('Assistant sessions (db) failed: '.$what); }

$config = ORMSetup::createConfiguration(true);
$config->setMetadataDriverImpl(new StaticPHPDriver([__DIR__.'/../../Entity', $users]));
$connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config);
$em = new EntityManager($connection, $config);
(new SchemaTool($em))->createSchema(array_map($em->getClassMetadata(...), [AssistantSession::class, User::class, Role::class, Permission::class]));

/** Só o necessário para o construtor do CommonRepository, que pede o manager da classe. */
$registry = new class($em) implements ManagerRegistry {
    public function __construct(private EntityManagerInterface $em) {}
    public function getManagerForClass(string $class): ?EntityManagerInterface { return $this->em; }
    public function getManager(?string $name = null): EntityManagerInterface { return $this->em; }
    public function getManagers(): array { return ['default' => $this->em]; }
    public function getManagerNames(): array { return ['default' => 'default']; }
    public function getDefaultManagerName(): string { return 'default'; }
    public function resetManager(?string $name = null): EntityManagerInterface { return $this->em; }
    public function getRepository(string $class, ?string $manager = null): object { return $this->em->getRepository($class); }
    public function getConnection(?string $name = null): object { return $this->em->getConnection(); }
    public function getConnections(): array { return ['default' => $this->em->getConnection()]; }
    public function getConnectionNames(): array { return ['default' => 'default']; }
    public function getDefaultConnectionName(): string { return 'default'; }
};

$operator = (new User())->setUsername('ana')->setEmail('ana@example.com')->setFirstName('Ana')->setLastName('Rocha');
$other = (new User())->setUsername('bruno')->setEmail('bruno@example.com')->setFirstName('Bruno')->setLastName('Dias');
$role = (new Role())->setName('Atendimento');
$em->persist($role);
foreach ([$operator, $other] as $user) { $user->setPassword('x')->setRole($role); $em->persist($user); }
$em->flush();

$repository = new AssistantSessionRepository($registry, AssistantSession::class);
$sessions = new AssistantSessions($repository);

// Abrir grava uma linha de verdade, com o contexto que o app mandou.
$started = $sessions->start(['agent_key' => 'suporte', 'title' => 'Quem está esperando?', 'context' => ['kind' => 'conversation', 'id' => 12]], $operator);
check($started['id'] > 0, 'start persisted a row');
check('conversation' === $started['context_kind'] && 12 === $started['context_id'], 'context stored through the mapping');
check(0 === $started['turns'] && [] === ($started['history'] ?? null), 'a new session carries no thread');
$id = $started['id'];
check(1 === (int) $connection->fetchOne('SELECT COUNT(*) FROM inbox_assistant_sessions'), 'one row in the table');

// Gravar o fio passa pela coluna JSON e volta igual.
$turns = [['id' => 'a', 'role' => 'user', 'text' => 'quem está esperando?'], ['id' => 'b', 'role' => 'assistant', 'text' => 'três contatos', 'tool' => 'read_inbox', 'conversationId' => 12]];
$recorded = $sessions->record($id, ['turns' => $turns], $operator);
check(2 === $recorded['turns'], 'both turns recorded');
check($turns === $recorded['history'], 'the thread survives the JSON column unchanged');
check('Quem está esperando?' === $recorded['title'], 'the title given at start is kept');

// A listagem roda o SELECT por dono e não traz o fio.
$listed = $sessions->listFor($operator);
check(1 === count($listed['items']), 'the owner sees one session');
check(!array_key_exists('history', $listed['items'][0]), 'the list leaves the thread behind');
check(2 === $listed['items'][0]['turns'], 'the list counts the turns');
check([] === $sessions->listFor($other)['items'], 'another operator sees none of it');

// Abrir pelo id de outro dono responde "não existe", que é o 404 do controller.
$refused = false;
try { $sessions->open($id, $other); } catch (DomainException $error) { $refused = 'not_found' === $error->getMessage(); }
check($refused, 'another owner is refused as not found');
check($turns === $sessions->open($id, $operator)['history'], 'the owner reopens the thread');

// Mais recente primeiro: a ordem da aba depende disto.
$second = $sessions->start(['agent_key' => 'suporte', 'title' => 'Campanhas ativas'], $operator);
$order = array_column($sessions->listFor($operator)['items'], 'id');
check([$second['id'], $id] === $order, 'the newest touched session comes first: '.implode(',', $order));

// A retenção apaga o que passou de noventa dias e deixa o resto. O UPDATE direto evita
// depender do relógio: a entidade só sabe marcar "agora".
$connection->executeStatement('UPDATE inbox_assistant_sessions SET date_modified = ? WHERE id = ?', [(new DateTimeImmutable('-120 days'))->format('Y-m-d H:i:s'), $id]);
$em->clear();
check(1 === $repository->purgeExpired(), 'one expired session deleted');
check([$second['id']] === array_column($sessions->listFor($operator)['items'], 'id'), 'the recent session stays');

// Apagar é do dono, e some do banco.
check(['removed' => true] === $sessions->remove($second['id'], $operator), 'the owner deletes');
check(0 === (int) $connection->fetchOne('SELECT COUNT(*) FROM inbox_assistant_sessions'), 'the table is empty again');

echo "PASS: mapping, JSON column, owner-scoped list and open, newest first, ninety-day purge and delete, against a real database\n";
