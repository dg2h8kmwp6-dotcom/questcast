/**
 * Normalise a phone selfie before it is ever uploaded.
 *
 * Three things go wrong when a phone camera talks straight to PHP. iPhones
 * shoot HEIC, which neither GD nor this server's Imagick can decode. The
 * originals are three to five megabytes, which quietly exceeds a default
 * upload_max_filesize and arrives as a truncated file. And both failures
 * surface identically — getimagesize() returns false and the player is told
 * their photo is in a format we cannot read.
 *
 * The browser already has a decoder for whatever its own camera produced, so
 * the picture is drawn to a canvas and re-encoded as a small JPEG here. What
 * reaches the server is always a format it can read, and always tiny.
 */

/** Plenty for a portrait the server will crop to 256px anyway. */
const MAX_EDGE = 768;
const QUALITY = 0.85;

async function decode(file) {
    // createImageBitmap handles HEIC on Safari and applies EXIF rotation, but
    // the orientation option is not everywhere, so an <img> is the fallback —
    // browsers have oriented those by default for years.
    try {
        return await createImageBitmap(file, { imageOrientation: 'from-image' });
    } catch {
        return await new Promise((resolve, reject) => {
            const url = URL.createObjectURL(file);
            const img = new Image();

            img.onload = () => { URL.revokeObjectURL(url); resolve(img); };
            img.onerror = () => { URL.revokeObjectURL(url); reject(new Error('undecodable')); };
            img.src = url;
        });
    }
}

async function toSmallJpeg(file) {
    const source = await decode(file);
    const w = source.width;
    const h = source.height;

    if (!w || !h) throw new Error('empty image');

    const scale = Math.min(1, MAX_EDGE / Math.max(w, h));
    const canvas = document.createElement('canvas');

    canvas.width = Math.round(w * scale);
    canvas.height = Math.round(h * scale);
    canvas.getContext('2d').drawImage(source, 0, 0, canvas.width, canvas.height);

    const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', QUALITY));

    if (!blob) throw new Error('could not re-encode');

    return new File([blob], 'portrait.jpg', { type: 'image/jpeg', lastModified: Date.now() });
}

/**
 * Livewire listens on the bubble phase, so taking the event during capture and
 * stopping it there is what keeps the original from being uploaded while the
 * conversion is still running.
 */
async function intercept(event) {
    const input = event.target;

    if (!input.matches?.('input[type="file"][data-normalise-image]')) return;
    if (input.dataset.normalised === '1') {
        delete input.dataset.normalised;

        return;
    }

    const file = input.files?.[0];

    if (!file) return;

    event.stopImmediatePropagation();
    event.preventDefault();

    let replacement = file;

    try {
        replacement = await toSmallJpeg(file);
    } catch (error) {
        // Better to let the server have its say than to swallow the photo.
        console.warn('[portrait] could not normalise, sending the original.', error);
    }

    const carrier = new DataTransfer();

    carrier.items.add(replacement);
    input.files = carrier.files;
    input.dataset.normalised = '1';
    input.dispatchEvent(new Event('change', { bubbles: true }));
}

document.addEventListener('change', intercept, true);
