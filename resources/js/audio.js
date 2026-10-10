/**
 * Every sound in this app is synthesised in the browser.
 *
 * There are no audio files: a handful of oscillators and noise bursts cover
 * the whole palette, which keeps the bundle tiny, sidesteps sample licensing
 * entirely, and means a sound can be retuned by changing a number here rather
 * than by rendering a new asset.
 */

const STORAGE_KEY = 'aa.muted';
const A4 = 440;

/** MIDI note number to frequency. 69 is A4. */
const hz = (midi) => A4 * 2 ** ((midi - 69) / 12);

/** Note names used by the music below, as MIDI numbers. */
const N = {
    C3: 48, D3: 50, E3: 52, F3: 53, G3: 55, A3: 57,
    C4: 60, D4: 62, E4: 64, F4: 65, G4: 67, A4: 69, B4: 71,
    C5: 72, D5: 74, E5: 76, G5: 79, A5: 81, C6: 84,
};

class Audio {
    constructor() {
        this.ctx = null;
        this.master = null;
        this.musicGain = null;
        this.muted = this.readMuted();
        this.music = null;
        this.wantsMusic = false;
    }

    readMuted() {
        try {
            return localStorage.getItem(STORAGE_KEY) === '1';
        } catch {
            // Private browsing, blocked storage: default to audible.
            return false;
        }
    }

    persistMuted() {
        try {
            localStorage.setItem(STORAGE_KEY, this.muted ? '1' : '0');
        } catch {
            /* nothing we can do, and nothing that should break playback */
        }
    }

    /**
     * Browsers refuse to start audio without a gesture, so the context is
     * built lazily on the first real interaction rather than at import time.
     */
    wake() {
        if (!this.ctx) {
            const Ctor = window.AudioContext || window.webkitAudioContext;
            if (!Ctor) return null;

            this.ctx = new Ctor();
            this.master = this.ctx.createGain();
            this.master.gain.value = 0.9;
            this.master.connect(this.ctx.destination);

            this.musicGain = this.ctx.createGain();
            this.musicGain.gain.value = 0;
            this.musicGain.connect(this.master);
        }

        if (this.ctx.state === 'suspended') {
            this.ctx.resume();
        }

        return this.ctx;
    }

    // ---- Voices ----------------------------------------------------------

    /** A plucked tone with a fast attack and an exponential tail. */
    tone(midi, { at = 0, dur = 0.25, type = 'triangle', gain = 0.25, to = null, detune = 0 } = {}) {
        const ctx = this.ctx;
        if (!ctx || this.muted) return;

        const t = ctx.currentTime + at;
        const osc = ctx.createOscillator();
        const env = ctx.createGain();

        osc.type = type;
        osc.detune.value = detune;
        osc.frequency.setValueAtTime(hz(midi), t);

        if (to !== null) {
            osc.frequency.exponentialRampToValueAtTime(hz(to), t + dur);
        }

        env.gain.setValueAtTime(0.0001, t);
        env.gain.exponentialRampToValueAtTime(gain, t + 0.008);
        env.gain.exponentialRampToValueAtTime(0.0001, t + dur);

        osc.connect(env).connect(this.master);
        osc.start(t);
        osc.stop(t + dur + 0.02);
    }

    /** Same as tone(), but routed through the music bus so it ducks together. */
    musicTone(midi, { at = 0, dur = 0.25, type = 'triangle', gain = 0.25 } = {}) {
        const ctx = this.ctx;
        if (!ctx) return;

        const t = at;
        const osc = ctx.createOscillator();
        const env = ctx.createGain();

        osc.type = type;
        osc.frequency.setValueAtTime(hz(midi), t);

        env.gain.setValueAtTime(0.0001, t);
        env.gain.exponentialRampToValueAtTime(gain, t + 0.01);
        env.gain.exponentialRampToValueAtTime(0.0001, t + dur);

        osc.connect(env).connect(this.musicGain);
        osc.start(t);
        osc.stop(t + dur + 0.02);
    }

    /** Filtered white noise: hats, thuds, dice clatter, water. */
    noise({ at = 0, dur = 0.12, gain = 0.2, freq = 2000, q = 1, type = 'bandpass', bus = null } = {}) {
        const ctx = this.ctx;
        if (!ctx || (this.muted && !bus)) return;

        const t = (bus ? at : ctx.currentTime + at);
        const frames = Math.max(1, Math.floor(ctx.sampleRate * dur));
        const buffer = ctx.createBuffer(1, frames, ctx.sampleRate);
        const data = buffer.getChannelData(0);

        for (let i = 0; i < frames; i++) {
            data[i] = Math.random() * 2 - 1;
        }

        const src = ctx.createBufferSource();
        src.buffer = buffer;

        const filter = ctx.createBiquadFilter();
        filter.type = type;
        filter.frequency.value = freq;
        filter.Q.value = q;

        const env = ctx.createGain();
        env.gain.setValueAtTime(gain, t);
        env.gain.exponentialRampToValueAtTime(0.0001, t + dur);

        src.connect(filter).connect(env).connect(bus ?? this.master);
        src.start(t);
        src.stop(t + dur);
    }

    // ---- Sound effects ---------------------------------------------------

    play(name) {
        if (this.muted) return;
        if (!this.wake()) return;

        const fx = {
            // A soft wooden tick for ordinary presses.
            tap: () => this.tone(N.C5, { dur: 0.07, gain: 0.12, type: 'square' }),

            // Choosing an option: two notes, upward, confident.
            select: () => {
                this.tone(N.G4, { dur: 0.1, gain: 0.18 });
                this.tone(N.C5, { at: 0.07, dur: 0.16, gain: 0.18 });
            },

            // Dice tumbling across a table.
            roll: () => {
                for (let i = 0; i < 7; i++) {
                    this.noise({
                        at: i * 0.045 + Math.random() * 0.015,
                        dur: 0.05,
                        gain: 0.16 - i * 0.015,
                        freq: 1400 + Math.random() * 1800,
                        q: 6,
                    });
                }
            },

            // Cleared the difficulty: bright major arpeggio.
            success: () => {
                [N.C5, N.E5, N.G5, N.C6].forEach((n, i) =>
                    this.tone(n, { at: i * 0.055, dur: 0.3, gain: 0.2 }));
            },

            // Missed it: a short slide downward, more comic than tragic.
            fail: () => {
                this.tone(N.G4, { dur: 0.3, gain: 0.2, type: 'sawtooth', to: N.C4 });
            },

            // Taking a hit: low thud plus a bruised tone.
            damage: () => {
                this.noise({ dur: 0.18, gain: 0.3, freq: 160, q: 0.8, type: 'lowpass' });
                this.tone(N.C3, { dur: 0.22, gain: 0.22, type: 'square' });
            },

            // Treasure: the sound every platformer trains you to want.
            loot: () => {
                this.tone(N.E5, { dur: 0.12, gain: 0.2 });
                this.tone(N.B4 + 12, { at: 0.08, dur: 0.35, gain: 0.2 });
            },

            // Somebody new at the table.
            join: () => {
                this.tone(N.C5, { dur: 0.12, gain: 0.18 });
                this.tone(N.F4 + 12, { at: 0.1, dur: 0.26, gain: 0.18 });
            },

            // A new scene arriving on the big screen.
            scene: () => {
                this.noise({ dur: 0.5, gain: 0.12, freq: 900, q: 0.7, type: 'lowpass' });
                this.tone(N.G4, { at: 0.08, dur: 0.5, gain: 0.14, type: 'sine' });
                this.tone(N.D5, { at: 0.16, dur: 0.5, gain: 0.12, type: 'sine' });
            },

            // The storyteller is working.
            thinking: () => {
                this.tone(N.A4, { dur: 0.18, gain: 0.1, type: 'sine' });
                this.tone(N.E5, { at: 0.12, dur: 0.22, gain: 0.08, type: 'sine' });
            },

            // Curtain call.
            fanfare: () => {
                [[N.C5, 0], [N.E5, 0.1], [N.G5, 0.2], [N.C6, 0.3], [N.G5, 0.46], [N.C6, 0.56]]
                    .forEach(([n, at]) => this.tone(n, { at, dur: 0.5, gain: 0.22 }));
            },
        }[name];

        fx?.();
    }

    // ---- Lobby music -----------------------------------------------------

    /**
     * A looping, bouncy four-bar theme, scheduled a beat at a time.
     *
     * Audio timing cannot ride on setInterval, so the timer only decides when
     * to queue notes; the notes themselves are stamped against the audio
     * clock, which is what keeps the groove from drifting.
     */
    startMusic() {
        this.wantsMusic = true;

        if (this.muted || this.music) return;
        if (!this.wake()) return;

        const ctx = this.ctx;
        const bpm = 132;
        const beat = 60 / bpm;
        const step = beat / 4;              // sixteenths
        const bars = 4;
        const steps = bars * 16;

        // I - vi - IV - V, one chord per bar: cheerful and hard to dislike.
        const roots = [N.C3, N.A3 - 12, N.F3, N.G3];

        const melody = [
            N.C5, null, N.E5, null, N.G5, null, N.E5, null,
            N.D5, null, null, N.E5, N.D5, null, N.C5, null,

            N.A4, null, N.C5, null, N.E5, null, N.C5, null,
            N.B4, null, null, N.C5, N.A4, null, null, null,

            N.F4, null, N.A4, null, N.C5, null, N.A4, null,
            N.G4, null, N.A4, null, N.C5, null, N.D5, null,

            N.G4, null, N.B4, null, N.D5, null, N.G5, null,
            N.D5, null, N.B4, null, N.G4, null, null, null,
        ];

        this.musicGain.gain.cancelScheduledValues(ctx.currentTime);
        this.musicGain.gain.setValueAtTime(0.0001, ctx.currentTime);
        this.musicGain.gain.exponentialRampToValueAtTime(0.5, ctx.currentTime + 1.2);

        let nextStep = 0;
        let nextTime = ctx.currentTime + 0.1;

        const schedule = () => {
            // Queue everything due in the next 150ms of audio time.
            while (nextTime < ctx.currentTime + 0.15) {
                const i = nextStep % steps;
                const bar = Math.floor(i / 16);
                const inBar = i % 16;
                const root = roots[bar];

                // Bass on the beat, with a skip into the next bar.
                if (inBar % 4 === 0) {
                    this.musicTone(root, { at: nextTime, dur: beat * 0.5, type: 'sine', gain: 0.34 });
                }
                if (inBar === 14) {
                    this.musicTone(root + 7, { at: nextTime, dur: step * 1.5, type: 'sine', gain: 0.22 });
                }

                // Marimba melody.
                const note = melody[i];
                if (note !== null && note !== undefined) {
                    this.musicTone(note, { at: nextTime, dur: step * 3, type: 'triangle', gain: 0.17 });
                    this.musicTone(note + 12, { at: nextTime, dur: step * 1.6, type: 'sine', gain: 0.05 });
                }

                // Kick on 1 and 3, hats on the offbeats.
                if (inBar === 0 || inBar === 8) {
                    this.noise({ at: nextTime, dur: 0.1, gain: 0.3, freq: 120, q: 0.8, type: 'lowpass', bus: this.musicGain });
                }
                if (inBar % 4 === 2) {
                    this.noise({ at: nextTime, dur: 0.035, gain: 0.07, freq: 7000, q: 1.5, bus: this.musicGain });
                }

                nextTime += step;
                nextStep++;
            }
        };

        schedule();
        this.music = setInterval(schedule, 40);
    }

    stopMusic({ keepWanting = false } = {}) {
        if (!keepWanting) this.wantsMusic = false;

        if (this.music) {
            clearInterval(this.music);
            this.music = null;
        }

        if (this.musicGain && this.ctx) {
            this.musicGain.gain.cancelScheduledValues(this.ctx.currentTime);
            this.musicGain.gain.setTargetAtTime(0.0001, this.ctx.currentTime, 0.1);
        }
    }

    toggleMute() {
        this.muted = !this.muted;
        this.persistMuted();

        if (this.muted) {
            this.stopMusic({ keepWanting: true });
        } else if (this.wantsMusic) {
            this.startMusic();
        }

        syncToggles();

        return this.muted;
    }
}

const audio = new Audio;

// ---- Wiring --------------------------------------------------------------

function syncToggles() {
    document.querySelectorAll('[data-sound-toggle]').forEach((button) => {
        button.setAttribute('aria-pressed', audio.muted ? 'true' : 'false');
        button.setAttribute('aria-label', audio.muted ? 'Turn sound on' : 'Turn sound off');

        const on = button.querySelector('[data-sound-on]');
        const off = button.querySelector('[data-sound-off]');

        if (on) on.hidden = audio.muted;
        if (off) off.hidden = !audio.muted;
    });
}

/** The first gesture anywhere unlocks audio and, on the lobby, the music. */
function unlock() {
    audio.wake();

    if (document.querySelector('[data-lobby-music]')) {
        audio.startMusic();
    }

    window.removeEventListener('pointerdown', unlock);
    window.removeEventListener('keydown', unlock);
}

window.addEventListener('pointerdown', unlock, { once: false });
window.addEventListener('keydown', unlock, { once: false });

document.addEventListener('click', (event) => {
    const toggle = event.target.closest('[data-sound-toggle]');

    if (toggle) {
        event.preventDefault();
        audio.toggleMute();
        if (!audio.muted) audio.play('tap');

        return;
    }

    // A press on anything clickable gets a tick, unless it declares its own.
    const pressable = event.target.closest('button, [role="button"]');

    if (pressable && !pressable.hasAttribute('data-silent')) {
        audio.play(pressable.dataset.sfx ?? 'tap');
    }
});

document.addEventListener('livewire:navigated', () => {
    syncToggles();

    if (document.querySelector('[data-lobby-music]')) {
        audio.startMusic();
    } else {
        audio.stopMusic();
    }
});

document.addEventListener('DOMContentLoaded', syncToggles);

/**
 * Server-driven cues. A component dispatches sfx with a name, and optionally
 * a follow-up that lands after the dice have finished rattling.
 */
document.addEventListener('livewire:init', () => {
    window.Livewire.on('sfx', (payload) => {
        const data = Array.isArray(payload) ? payload[0] : payload;
        if (!data) return;

        audio.play(data.name);

        if (data.then) {
            setTimeout(() => audio.play(data.then), data.delay ?? 450);
        }
    });
});

window.Adventure = audio;

export default audio;
