// Runs the browser tests: makes the test pages and a test user, runs each test file in a browser, removes everything.
//
//   cd tests/browser && npm install && npx playwright install chromium
//   set SHV_TEST_URL=http://webseite2.test/        (Windows)       export SHV_TEST_URL=http://webseite2.test/
//   node run.js [playback|admin-upload|ckeditor] [--browser=chromium|firefox|webkit|all]
//
// Other browsers: npx playwright install firefox webkit. A browser that is not installed is skipped.
const { spawnSync } = require('child_process');
const path = require('path');
const fs = require('fs');

if(!process.env.SHV_TEST_URL) { console.error('Set SHV_TEST_URL to the address of the website (for example http://webseite2.test/).'); process.exit(2); }
if(!/^https?:\/\/[^\s]+$/.test(process.env.SHV_TEST_URL.trim())) {
  // (in the Windows shell "set NAME=value" takes the rest of the line: a second command on the same line becomes part of the address)
  console.error('SHV_TEST_URL is not an address: "' + process.env.SHV_TEST_URL + '"\nSet it on a line of its own: set SHV_TEST_URL=http://webseite2.test/   and start the tests on the next line.');
  process.exit(2);
}
process.env.SHV_TEST_URL = process.env.SHV_TEST_URL.trim();
const args = process.argv.slice(2);
const only = args.find(a => !a.startsWith('--'));
const choice = ((args.find(a => a.startsWith('--browser=')) || '').slice(10) || process.env.SHV_BROWSER || 'chromium');
const browsers = choice === 'all' ? ['chromium', 'firefox', 'webkit'] : choice.split(',').map(b => b.trim()).filter(Boolean);
const env = Object.assign({}, process.env, { SHV_TEST_WRITE: '1' });
const php = process.env.PHP || 'php';
const setup = path.join(__dirname, 'setup.php');

const up = spawnSync(php, [setup, 'up'], { env, encoding: 'utf8' });
if(up.status !== 0) { console.error((up.stderr || '') + (up.stdout || '')); spawnSync(php, [setup, 'down'], { env }); process.exit(up.status || 1); }
const json = up.stdout.slice(up.stdout.indexOf('{'));

let failed = 0, skipped = 0;
try {
  const tests = fs.readdirSync(__dirname).filter(f => /\.js$/.test(f) && !['run.js', 'lib.js'].includes(f)).map(f => f.replace(/\.js$/, '')).filter(t => !only || t === only);
  for(const browser of browsers) {
    for(const test of tests) {
      console.log('\n######## ' + test + ' (' + browser + ')');
      const r = spawnSync(process.execPath, [path.join(__dirname, test + '.js')], { env: Object.assign({}, env, { SHV_BROWSER: browser, SHV_BROWSER_STATE: json }), stdio: 'inherit' });
      if(r.status === 3) skipped++;
      else if(r.status !== 0) failed++;
    }
  }
} finally {
  const down = spawnSync(php, [setup, 'down'], { env, encoding: 'utf8' });
  if(down.status !== 0) console.error('Removing the test pages failed:\n' + (down.stderr || '') + (down.stdout || ''));
}
console.log('\n' + (failed ? failed + ' test file(s) failed' : 'all test files passed') + (skipped ? ', ' + skipped + ' skipped (browser not installed)' : ''));
process.exit(failed ? 1 : 0);
