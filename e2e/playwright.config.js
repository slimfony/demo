// @ts-check
const { defineConfig, devices } = require('@playwright/test');

module.exports = defineConfig({
  testDir: './tests',
  timeout: 180_000,
  fullyParallel: false, // the tests share one running stack
  workers: 1,
  retries: 0,
  reporter: [['list']],
  outputDir: './results',
  use: {
    baseURL: process.env.BASE_URL || 'http://localhost:8080',
    ...devices['Desktop Chrome'],
    viewport: { width: 1280, height: 900 },
    video: process.env.RECORD ? { mode: 'on', size: { width: 1280, height: 720 } } : 'off',
    colorScheme: 'light',
  },
});
