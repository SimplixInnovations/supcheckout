import { defineConfig } from '@playwright/test';

export default defineConfig({
  testDir: '.',
  timeout: 60_000,
  retries: 0,
  workers: 1,
  reporter: [['line']],
  use: {
    baseURL: process.env.R6_BASE_URL || 'http://127.0.0.1:8080',
    trace: 'off',
    screenshot: 'only-on-failure',
  },
  outputDir: 'artifacts/test-results',
});
