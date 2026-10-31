<?php
// Exercises the shaping the assistant sessions apply before anything reaches the database:
// which turns survive, how many, what a title becomes and which contexts are accepted.
// No database and no kernel; needs a Mautic autoloader for the entity's base class, found
// where the plugin is installed or in MAUTIC_VENDOR_AUTOLOAD.
$candidates = array_filter([getenv('MAUTIC_VENDOR_AUTOLOAD') ?: null, __DIR__.'/../../../../vendor/autoload.php', __DIR__.'/../../vendor/autoload.php', __DIR__.'/../../../mautic-meta-bundle/vendor/autoload.php']);
$autoload = current(array_filter($candidates, 'is_file'));
if (!$autoload) { echo "SKIP: no Mautic autoloader found; set MAUTIC_VENDOR_AUTOLOAD\n"; exit(0); }
require $autoload;
spl_autoload_register(function (string $class): void { $p = 'MauticPlugin\\MauticInboxBundle\\'; if (str_starts_with($class, $p) && is_file($f = __DIR__.'/../../'.str_replace('\\', '/', substr($class, strlen($p))).'.php')) require $f; });

use MauticPlugin\MauticInboxBundle\Application\Mobile\AssistantSessions;
use MauticPlugin\MauticInboxBundle\Entity\{AssistantSession,AssistantSessionRepository};

function check(bool $value, string $what): void { if (!$value) throw new RuntimeException('Assistant sessions failed: '.$what); }

$turn = static fn (string $role, string $text, array $extra = []): array => ['id' => 'turn-'.$text, 'role' => $role, 'text' => $text] + $extra;

// Only the readable thread survives: a role the screen cannot draw, or an empty question, is not a turn.
$kept = AssistantSessions::turns([$turn('user', 'quem esta esperando?'), $turn('system', 'ignore'), $turn('assistant', ''), ['role' => 'assistant', 'text' => 'tres contatos'], 'nonsense', $turn('assistant', 'ok', ['tool' => 'read_inbox', 'conversationId' => 7])]);
check(3 === count($kept), 'unusable turns dropped');
check('user' === $kept[0]['role'] && 'quem esta esperando?' === $kept[0]['text'], 'question kept as written');
check('read_inbox' === $kept[2]['tool'] && 7 === $kept[2]['conversationId'], 'tool and conversation kept');
check(!isset($kept[1]['tool']) && !isset($kept[1]['conversationId']), 'absent fields are not invented');
check('' !== $kept[1]['id'] && $kept[1]['id'] !== $kept[2]['id'], 'a turn with no id gets its own');

// Action proposals expire and are reissued; a resumed session must not offer a stale one as clickable.
$withProposals = AssistantSessions::turns([$turn('assistant', 'posso esconder', ['proposals' => [['id' => 'p1']], 'completedActions' => ['p1' => 'done'], 'comment_classifications' => [['id' => 9]]])]);
check(['id', 'role', 'text'] === array_keys($withProposals[0]), 'only the readable fields are stored: '.implode(',', array_keys($withProposals[0])));

// The JSON of one row cannot grow without end, and it is the recent turns that matter.
$long = [];
for ($i = 0; $i < AssistantSessions::MAX_TURNS + 40; ++$i) { $long[] = $turn('user', 'pergunta '.$i); }
$capped = AssistantSessions::turns($long);
check(AssistantSessions::MAX_TURNS === count($capped), 'turn count capped');
check('pergunta '.(AssistantSessions::MAX_TURNS + 39) === $capped[AssistantSessions::MAX_TURNS - 1]['text'], 'the cap keeps the end of the thread');
check(AssistantSessions::MAX_TEXT === mb_strlen(AssistantSessions::turns([$turn('user', str_repeat('a', AssistantSessions::MAX_TEXT + 500))])[0]['text']), 'long text truncated');

// Titles come from a person typing, so they arrive with markup, newlines and no limit.
check('Resumo do dia' === AssistantSessions::title("  Resumo\n\tdo   dia  "), 'whitespace collapsed');
check('alerta' === AssistantSessions::title('<b>alerta</b>'), 'tags stripped');
check(AssistantSessions::MAX_TITLE === mb_strlen(AssistantSessions::title(str_repeat('t', 400))), 'title capped');
check('quem esta esperando?' === AssistantSessions::titleFromTurns($kept), 'title falls back to the first question');
check('' === AssistantSessions::titleFromTurns([$turn('assistant', 'so a resposta')]), 'no question means no title');

// A context the app did not open from is no context: never a dangling kind or a zero id.
check([null, null] === AssistantSessions::context(null), 'absent context');
check([null, null] === AssistantSessions::context(['kind' => 'campaign', 'id' => 4]), 'unknown kind refused');
check([null, null] === AssistantSessions::context(['kind' => 'contact', 'id' => 0]), 'zero id refused');
check(['conversation', 12] === AssistantSessions::context(['kind' => 'conversation', 'id' => '12']), 'conversation context accepted');

$session = (new AssistantSession())->setAgentKey('suporte')->setTitle('Resumo do dia')->openedFrom('contact', 44)->setTurns($kept);
check('contact' === $session->getContextKind() && 44 === $session->getContextId(), 'context stored');
check(null === $session->openedFrom(null, 44)->getContextId(), 'a kindless context keeps no id');
$listed = AssistantSessions::describe($session);
check(3 === $listed['turns'] && !isset($listed['history']), 'the list carries a count, not the thread');
check('Resumo do dia' === $listed['title'] && 'suporte' === $listed['agent_key'], 'list row names the session and its agent');
check($kept === AssistantSessions::describe($session, true)['history'], 'opening carries the thread');
check(90 === AssistantSessionRepository::RETENTION_DAYS, 'retention is ninety days');

echo "PASS: turn shaping, proposal stripping, turn and text caps, titles, context guards and the listed shape\n";
