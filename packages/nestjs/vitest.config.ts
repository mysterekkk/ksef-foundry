import { defineConfig } from 'vitest/config';

export default defineConfig({
  test: {
    projects: [
      {
        test: { name: 'default', include: ['test/*.test.ts'], exclude: ['test/live/**'], testTimeout: 30_000, hookTimeout: 30_000 },
      },
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
