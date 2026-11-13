import { defineConfig } from 'vitest/config';

export default defineConfig({
  test: {
    projects: [
      { test: { name: 'unit', include: ['test/unit/**/*.test.ts'], testTimeout: 20_000 } },
      { test: { name: 'contract', include: ['test/contract/**/*.test.ts'], testTimeout: 20_000 } },
      { test: { name: 'invoice', include: ['test/invoice/**/*.test.ts'], testTimeout: 60_000 } },
      { test: { name: 'fault', include: ['test/fault/**/*.test.ts'], testTimeout: 30_000 } },
      {
        test: {
          name: 'live',
          include: ['test/live/**/*.test.ts'],
          testTimeout: 180_000,
          hookTimeout: 120_000,
          fileParallelism: false,
        },
      },
    ],
  },
});
