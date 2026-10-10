import { expect, test } from '@playwright/test';

const PREMISE =
    'Every chart says the atoll is empty. The brass gulls say otherwise: they wheel ' +
    'over the lagoon each day and never land. The tide is going out.';

/** A phone joins the table, takes a portrait, and picks a character. */
async function seatPlayer(browser, code, name, archetype, photo = null) {
    const context = await browser.newContext({
        viewport: { width: 390, height: 844 },
        isMobile: true,
        hasTouch: true,
    });
    const page = await context.newPage();

    await page.goto(`/play/${code}`);
    await expect(page.getByRole('heading')).toBeVisible();

    if (photo) {
        // The camera input is visually hidden behind its label, which is what
        // a phone actually presents; the file still goes in the same way.
        await page.locator('#photo').setInputFiles(photo);
        await expect(page.getByText('Looking good.')).toBeVisible();
    }

    await page.getByLabel('Your name').fill(name);
    await page.getByText(archetype, { exact: true }).click();
    await page.getByRole('button', { name: /join the party/i }).click();

    await expect(page.getByText(/you.{0,3}re in/i)).toBeVisible();

    return { context, page };
}

test('a host and two phones play through two rounds', async ({ page, browser }) => {
    // ---- The host opens a table -------------------------------------------
    await page.goto('/');
    await expect(page.getByRole('heading', { name: /islands to cross/i })).toBeVisible();

    await page.getByLabel('Title').fill('The Clockwork Atoll');
    await page.getByLabel('Premise').fill(PREMISE);
    await page.getByRole('button', { name: /set sail/i }).click();

    await expect(page).toHaveURL(/\/host\/[A-Z0-9]{5}$/);

    const code = (await page.url()).split('/').pop();
    expect(code).toMatch(/^[A-Z0-9]{5}$/);

    // The lobby should be showing the code and a scannable QR.
    await expect(page.getByText('Join code')).toBeVisible();
    await expect(page.locator('svg').first()).toBeVisible();
    await expect(page.getByText(/waiting for the first phone/i)).toBeVisible();

    // ---- Two phones join --------------------------------------------------
    const ana = await seatPlayer(browser, code, 'Ana', 'Scout', 'e2e/fixtures/ana.jpg');
    const ben = await seatPlayer(browser, code, 'Ben', 'Keeper');   // skips the camera

    // Both appear on the main screen's roster, not merely in the log.
    const party = page.getByTestId('party');
    await expect(party.getByText('Ana', { exact: true })).toBeVisible();
    await expect(party.getByText('Ben', { exact: true })).toBeVisible();
    await expect(party.getByText('Scout')).toBeVisible();
    await expect(party.getByText('Keeper')).toBeVisible();
    await expect(page.getByText('The party (2)')).toBeVisible();

    // Ana's face made it to the roster; Ben skipped the camera and gets his
    // initial instead. Both walk the trail either way.
    await expect(party.getByRole('img', { name: 'Ana' })).toBeVisible();
    await expect(party.getByText('B', { exact: true })).toBeVisible();
    await expect(page.locator('[wire\\:key^="walker-"]')).toHaveCount(2);

    // ---- The host begins --------------------------------------------------
    await page.getByRole('button', { name: /begin the adventure/i }).click();

    await expect(page.getByText('Scene 1', { exact: true })).toBeVisible();
    await expect(page.getByRole('article').getByText(/the jetty gives way/i)).toBeVisible();

    // ---- Round one --------------------------------------------------------
    // Ana picks a listed option.
    await expect(ana.page.getByText(/what do you do/i)).toBeVisible();
    await ana.page.getByTestId('options').getByRole('button', { name: /press forward carefully/i }).click();

    await expect(ana.page.getByText('You chose')).toBeVisible();
    await expect(ana.page.getByText(/d20 \d+/)).toBeVisible();
    await expect(ana.page.getByText('Waiting on 1 more player')).toBeVisible();

    // The host screen shows who is still deciding.
    await expect(party.getByText('Locked in')).toHaveCount(1);
    await expect(party.getByText(/deciding/i)).toHaveCount(1);

    // Ben types something of his own instead.
    await ben.page.getByLabel(/do something else/i).fill('I bless the road ahead and walk first');
    await ben.page.getByRole('button', { name: /try it/i }).click();

    // Both are in, so the round resolves and scene two opens.
    await expect(page.getByText('Scene 2', { exact: true })).toBeVisible({ timeout: 45_000 });

    // ---- Round two --------------------------------------------------------
    // Both phones should be asked to decide again, not stuck on the old scene.
    await expect(ana.page.getByText(/scene 2 .* what do you do/i)).toBeVisible();
    await expect(ben.page.getByText(/scene 2 .* what do you do/i)).toBeVisible();

    await ana.page.getByTestId('options').getByRole('button', { name: /scout ahead alone/i }).click();
    await expect(ana.page.getByText('You chose')).toBeVisible();

    // A player may change their mind while the scene is still open.
    await ana.page.getByRole('button', { name: /change my mind/i }).click();
    await expect(ana.page.getByText(/what do you do/i)).toBeVisible();

    await ana.page.getByTestId('options').getByRole('button', { name: /hold position and listen/i }).click();
    await expect(ana.page.getByText('You chose')).toBeVisible();

    // The host can resolve without waiting for Ben.
    await page.getByRole('button', { name: /resolve now/i }).click();
    await expect(page.getByText('Scene 3', { exact: true })).toBeVisible({ timeout: 45_000 });

    // ---- The host ends the session ----------------------------------------
    page.on('dialog', (dialog) => dialog.accept());
    await page.getByRole('button', { name: /^end$/i }).click();

    await expect(page.getByRole('main').getByText(/session is closed|party prevails|party falls/i)).toBeVisible();
    await expect(ben.page.getByText(/that.{0,3}s a wrap|you made it/i)).toBeVisible();

    await ana.context.close();
    await ben.context.close();
});

test('the host screen refuses a visitor without the host secret', async ({ page, browser }) => {
    await page.goto('/');
    await page.getByLabel('Premise').fill(PREMISE);
    await page.getByRole('button', { name: /set sail/i }).click();
    await expect(page).toHaveURL(/\/host\//);

    const code = (await page.url()).split('/').pop();

    // A different browser has no host token in its session.
    const stranger = await browser.newContext();
    const strangerPage = await stranger.newPage();
    const response = await strangerPage.goto(`/host/${code}`);

    expect(response.status()).toBe(403);

    await stranger.close();
});
