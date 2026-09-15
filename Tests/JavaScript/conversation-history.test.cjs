const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');

const source = fs.readFileSync(require('node:path').join(__dirname, '../../Assets/js/inbox.js'), 'utf8');
const start = source.indexOf('    function createInboxHistory(');
const end = source.indexOf('    function boot()', start);

let pathname = '/s/inbox';
const listeners = new Map();
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
    addEventListener(name, listener, capture) { listeners.set(name, {listener, capture}); },
    removeEventListener(name, listener, capture) {
        const current = listeners.get(name);
        if (current && current.listener === listener && current.capture === capture) listeners.delete(name);
    },
};
const context = {window, document: {title: 'Support inbox'}, URL};
vm.createContext(context);
vm.runInContext(source.slice(start, end) + ';globalThis.factory=createInboxHistory;', context);

const opened = [];
let cleared = 0;
const root = {dataset: {indexUrl: '/s/inbox', conversationUrl: '/s/inbox/conversations/0'}};
const history = context.factory(root, id => opened.push(id), () => { cleared++; });

assert.equal(history.current(), 0);
assert.equal(listeners.get('popstate').capture, true);
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
let stopped = 0;
listeners.get('popstate').listener({stopImmediatePropagation() { stopped++; }});
assert.equal(JSON.stringify(opened), '[29]');
assert.equal(stopped, 1, 'the Mautic global reload handler must be stopped for an Inbox route');

pathname = '/s/inbox';
listeners.get('popstate').listener({stopImmediatePropagation() { stopped++; }});
assert.equal(cleared, 1);
assert.equal(stopped, 2);

pathname = '/s/meta';
listeners.get('popstate').listener({stopImmediatePropagation() { stopped++; }});
assert.equal(stopped, 2, 'history outside Inbox must remain under Mautic control');

history.dispose();
assert.equal(listeners.has('popstate'), false);
console.log('Conversation history: permalink, deduplication, back/forward and cleanup passed');
