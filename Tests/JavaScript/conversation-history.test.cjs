const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');

const source = fs.readFileSync(require('node:path').join(__dirname, '../../Assets/js/inbox.js'), 'utf8');
const start = source.indexOf('    function createInboxHistory(');
const end = source.indexOf('    function boot()', start);

let pathname = '/s/inbox';
const writes = [];
const window = {
    location: {
        origin: 'https://mautic.example',
        get pathname() { return pathname; },
    },
    history: {
        pushState(state, title, target) { writes.push(['push', state, target]); pathname = target; },
        replaceState(state, title, target) { writes.push(['replace', state, target]); pathname = target; },
    },
};
const context = {window, document: {title: 'Support inbox'}, URL};
vm.createContext(context);
vm.runInContext(source.slice(start, end) + ';globalThis.factory=createInboxHistory;', context);

const root = {dataset: {indexUrl: '/s/inbox', conversationUrl: '/s/inbox/conversations/0'}};
const history = context.factory(root);

assert.equal(history.current(), 0);
history.open(17);
assert.equal(pathname, '/s/inbox/conversations/17');
assert.equal(writes[0][0], 'push');
assert.equal(writes[0][1].mauticInbox, true);
assert.equal(writes[0][1].stateId, 17);
assert.equal(writes[0][2], '/s/inbox/conversations/17');
history.open(17);
assert.equal(writes.length, 1, 'selecting the current conversation must not duplicate history');
history.clear(true);
assert.equal(pathname, '/s/inbox');
assert.equal(writes[1][0], 'replace');

pathname = '/s/inbox/conversations/29';
assert.equal(history.current(), 29);

console.log('Conversation history: permalink, URL updates and duplicate-entry prevention passed');
