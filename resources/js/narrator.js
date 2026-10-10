/**
 * The storyteller's voice.
 *
 * The server renders every passage it has audio for onto the host screen as a
 * list of {key, url}; this decides which of them have not been heard yet and
 * plays those, one after another.
 *
 * Keeping the "have we said this" record here rather than on the server is what
 * makes the whole thing safe: the board re-renders on every broadcast and again
 * every ten seconds from the poll, so anything driven by render count alone
 * would read the same scene aloud over and over.
 */

import audio from './audio';

/** Passages already spoken, or deliberately skipped, by key. */
const spoken = new Set();

let queue = [];
let speaking = null;
let listening = false;

function board() {
    return document.querySelector('[data-voice-over]');
}

function passagesOn(node) {
    try {
        return JSON.parse(node.getAttribute('data-voice-over') || '[]');
    } catch {
        return [];
    }
}

/**
 * Reloading the host screen mid-session must not replay the last few scenes,
 * so the first look only takes note of what is already there.
 */
function sync(node) {
    const passages = passagesOn(node);

    if (!listening) {
        listening = true;
        passages.forEach((passage) => spoken.add(passage.key));

        return;
    }

    passages.forEach((passage) => {
        if (spoken.has(passage.key)) return;

        spoken.add(passage.key);
        queue.push(passage);
    });

    advance();
}

function advance() {
    if (speaking || !queue.length) return;

    // Muting is a decision about the room, not about this passage: drop the
    // backlog rather than saving it up to deliver later.
    if (audio.muted) {
        queue = [];

        return;
    }

    const passage = queue.shift();
    const sound = new window.Audio(passage.url);

    speaking = sound;

    // A passage that will not load is one the room reads off the screen; it
    // must never stall the ones queued behind it.
    const done = () => {
        if (speaking === sound) speaking = null;
        advance();
    };

    sound.addEventListener('ended', done);
    sound.addEventListener('error', done);

    sound.play().catch(done);
}

/** Stop him mid-sentence and forget what was coming. */
function hush() {
    queue = [];

    if (speaking) {
        speaking.pause();
        speaking = null;
    }
}

// The board is morphed in place rather than replaced, so watching the document
// for the attribute covers both a re-render and a fresh page arriving.
const observer = new MutationObserver(() => {
    const node = board();

    if (node) sync(node);
});

observer.observe(document.documentElement, {
    subtree: true,
    childList: true,
    attributes: true,
    attributeFilter: ['data-voice-over'],
});

document.addEventListener('DOMContentLoaded', () => {
    const node = board();

    if (node) sync(node);
});

// A new page is a new session as far as the voice is concerned.
document.addEventListener('livewire:navigated', () => {
    hush();
    listening = false;
    spoken.clear();

    const node = board();

    if (node) sync(node);
});

// audio.js owns the toggle and runs first, so by the time this fires the new
// mute state is already set.
document.addEventListener('click', (event) => {
    if (event.target.closest('[data-sound-toggle]') && audio.muted) {
        hush();
    }
});

export default { hush };
