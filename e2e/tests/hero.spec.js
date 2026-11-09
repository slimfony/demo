// @ts-check
// Makes the picture and the recording for the README from a real run of 1,000 ZIPs. Only when asked for:
//   HERO=1 npx playwright test hero
const { test, expect } = require('@playwright/test');
const fs = require('node:fs');

test.skip(!process.env.HERO, 'only when making the README picture and recording');
test.use({ viewport: { width: 1280, height: 760 }, video: { mode: 'on', size: { width: 960, height: 570 } } });

test('a full import, recorded', async ({ page }) => {
  fs.mkdirSync('screenshots', { recursive: true });
  await page.goto('/');
  await expect(page.locator('#status-text')).toHaveText('All systems normal');
  await page.waitForTimeout(800);

  await page.locator('#import').scrollIntoViewIfNeeded();
  await page.evaluate(() => window.scrollBy(0, 40));
  await page.locator('#run-form select[name="zips"]').selectOption('1000');
  await page.locator('#run-form select[name="faults"]').selectOption('0.03');
  await page.locator('#run-start').click();

  await expect(page.locator('#run-result')).toBeVisible({ timeout: 170_000 });
  await page.waitForTimeout(1500);

  // the finished run: the form, the tiles and the numbers
  await page.locator('#run-form').scrollIntoViewIfNeeded();
  await page.setViewportSize({ width: 1280, height: 1000 });
  await page.locator('#run-form').evaluate((form) => form.scrollIntoView({ block: 'start' }));
  await page.waitForTimeout(500);
  await page.screenshot({ path: 'screenshots/hero.png' });

  await page.locator('#theme-toggle').click();
  await page.waitForTimeout(500);
  await page.screenshot({ path: 'screenshots/hero-dark.png' });
});
