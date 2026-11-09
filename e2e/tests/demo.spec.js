// @ts-check
const { test, expect } = require('@playwright/test');
const fs = require('node:fs');

const shots = 'screenshots';
const number = async (locator) => Number((await locator.innerText()).replace(/[^0-9]/g, ''));

test.beforeAll(() => fs.mkdirSync(shots, { recursive: true }));

test('the page loads and shows the running system', async ({ page }) => {
  await page.goto('/');
  await expect(page.getByRole('heading', { level: 1 })).toContainText('PHP never left');
  await expect(page.locator('#status-text')).toHaveText('All systems normal');
  await expect(page.locator('[data-fact="php"]').first()).toContainText('PHP 8.4');
  await expect(page.locator('[data-fact="server"]').first()).toContainText('worker');
});

test('an import runs from the first upload to the result card', async ({ page }) => {
  await page.goto('/');
  await page.locator('#run-form select[name="zips"]').selectOption('50');
  await page.locator('#run-form select[name="faults"]').selectOption('0.03');
  await page.locator('#run-start').click();

  // the tiles appear and move
  await expect(page.locator('#run-tiles .tile')).toHaveCount(50);
  await page.locator('#import').scrollIntoViewIfNeeded();
  await page.screenshot({ path: `${shots}/import-running.png` });

  // the result card is the end of the run
  await expect(page.locator('#run-result')).toBeVisible({ timeout: 150_000 });
  expect(await number(page.locator('#p-imported'))).toBe(50);

  // every tile ended as booked or booked with rejected statements, none is waiting, importing or refused
  const states = await page.locator('#run-tiles .tile').evaluateAll((tiles) => tiles.map((t) => ['queued', 'running', 'done', 'problems', 'failed', 'refused'].find((c) => t.classList.contains(c))));
  expect(states.filter((s) => s === 'queued' || s === 'running' || s === 'refused')).toEqual([]);

  // 50 ZIPs of 10 statements: what was booked and what was rejected adds up
  const booked = await number(page.locator('#s-booked'));
  const rejected = await number(page.locator('#s-rejected'));
  const rolledBack = await number(page.locator('#s-rolled'));
  expect(booked + rejected + rolledBack).toBe(500);
  expect(booked).toBeGreaterThan(400);

  // the tiles of the uploads that came with a token carry a shield
  expect(await page.locator('#run-tiles .tile.jwt').count()).toBeGreaterThan(40);

  await page.locator('#import').scrollIntoViewIfNeeded();
  await page.screenshot({ path: `${shots}/import-done.png`, fullPage: false });
});

test('a broken token is refused for its reason, a valid one is accepted', async ({ page }) => {
  await page.goto('/');
  const send = async (kind) => {
    await page.locator('#upload-form select[name="kind"]').selectOption(kind);
    await page.locator('#upload-start').click();
    await expect(page.locator('#upload-start')).toBeEnabled();
    return page.locator('#upload-results dd').first();
  };

  await expect(await send('valid')).toContainText('Accepted');
  await expect(await send('expired')).toContainText('token was not accepted');
  await expect(await send('missing_scope')).toContainText('no permission');
  await expect(await send('sandbox')).toContainText('Accepted');
});

test('the dark theme works and is remembered', async ({ page }) => {
  await page.goto('/');
  await page.locator('#theme-toggle').click();
  await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');
  await page.reload();
  await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');
  await page.screenshot({ path: `${shots}/page-dark.png` });
});

test('the live numbers and the batch list fill in', async ({ page }) => {
  await page.goto('/');
  await expect(page.locator('#batch-rows tr').first()).toBeVisible({ timeout: 15_000 });
  await expect(page.locator('#kpi-total')).not.toHaveText('–');
});
