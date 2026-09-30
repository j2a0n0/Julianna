const assert = require('node:assert/strict');
const test = require('node:test');
const { renderAssistantBody, renderTurns } = require('../../../../../app/Domain/AgentUi/Js/agentUiController.js');

class FakeNode {
    constructor(tagName, text = '') {
        this.tagName = tagName;
        this._text = text;
        this.children = [];
        this.className = '';
    }

    set textContent(value) { this._text = String(value); this.children = []; }
    get textContent() { return this._text + this.children.map((child) => child.textContent).join(''); }
    set innerHTML(_) { throw new Error('Untrusted output must not use innerHTML.'); }
    appendChild(child) { this.children.push(child); return child; }
    replaceChildren(...children) { this._text = ''; this.children = children; }
}

global.document = {
    createElement: (tag) => new FakeNode(tag),
    createTextNode: (text) => new FakeNode('#text', text),
    getElementById: () => null,
};

function tags(node) {
    return [node.tagName, ...node.children.flatMap(tags)];
}

test('assistant output renders readable paragraphs, emphasis, and lists', () => {
    const root = new FakeNode('div');
    renderAssistantBody(root, '**Julianna**\n\nDone — I added **Adding 10 new clients**.\n\n- Tracking: **0 → 10** clients\n- Board: Build My Productivity System');

    assert.deepEqual(root.children.map((node) => node.tagName), ['p', 'ul']);
    assert.equal(root.children[0].textContent, 'Done — I added Adding 10 new clients.');
    assert.equal(root.children[1].children.length, 2);
    assert.equal(tags(root).filter((tag) => tag === 'strong').length, 2);
    assert.doesNotMatch(root.textContent, /\*\*|^Julianna/);
});

test('question marker is hidden and hostile HTML stays plain text', () => {
    const root = new FakeNode('div');
    renderAssistantBody(root, '**NEEDS_INPUT:** Which board? <img src=x onerror=alert(1)>');

    assert.equal(root.textContent, 'Which board? <img src=x onerror=alert(1)>');
    assert.ok(!tags(root).includes('img'));
    assert.doesNotMatch(root.textContent, /NEEDS_INPUT/);
});

test('user messages remain literal text', () => {
    const root = new FakeNode('div');
    renderTurns(root, [{ role: 'user', content: '**literal** <script>bad()</script>' }], 'agent-turn');

    assert.match(root.textContent, /\*\*literal\*\* <script>bad\(\)<\/script>/);
    assert.ok(!tags(root).includes('script'));
});
