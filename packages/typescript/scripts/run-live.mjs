// Runs the LIVE TEST suite against https://api-test.ksef.mf.gov.pl (opt-in: KSEF_LIVE_TEST=1).
import { spawnSync } from 'node:child_process';

if (process.env.KSEF_LIVE_TEST !== '1') {
  console.log('SKIPPED (live TEST disabled) - set KSEF_LIVE_TEST=1 to run against api-test.ksef.mf.gov.pl');
  process.exit(0);
}
const r = spawnSync('npx', ['vitest', 'run', '--project', 'live', '--reporter=verbose'], {
  stdio: 'inherit',
  shell: true,
});
process.exit(r.status ?? 1);
