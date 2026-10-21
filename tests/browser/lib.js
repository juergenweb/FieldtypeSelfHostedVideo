// Small helpers shared by the browser tests: the report, and what to watch on a page.
const pw = require('playwright');
const browserName = process.env.SHV_BROWSER || 'chromium';
const origin = (process.env.SHV_TEST_URL || '').replace(/\/+$/, '');
const state = JSON.parse(process.env.SHV_BROWSER_STATE || '{}');

let pass = 0, fail = 0, skipped = 0;
const fails = [];
const section = title => console.log('\n== ' + title);
const ok = (condition, label, detail = '') => {
  if(condition) { pass++; console.log('  ok   ' + label); }
  else { fail++; fails.push(label); console.log('  FAIL ' + label + (detail ? ' — ' + detail : '')); }
};
const skip = label => { skipped++; console.log('  skip ' + label); };
const summary = () => {
  console.log('\n' + pass + ' passed, ' + fail + ' failed' + (skipped ? ', ' + skipped + ' skipped' : ''));
  fails.forEach(f => console.log('  FAILED: ' + f));
  return fail ? 1 : 0;
};

/** Watch a page: errors of the script, errors in the console, answers of the web server that mean "not found" or worse */
function watch(page) {
  const watched = { errors: [], failed: [], requests: [] };
  page.on('pageerror', e => watched.errors.push(e.message));
  // (a browser asks for /favicon.ico by itself and tells the console when a website has none: that is not the page's)
  page.on('console', m => {
    if(m.type() !== 'error') return;
    const where = m.location() && m.location().url || '';
    if(/\/favicon\.ico(\?|$)/.test(where)) return;
    // CKEditor of the website names a plugin it does not have (a setting of a field of the website): not this module's
    if(/^\[CKEDITOR\.resourceManager\.load\] Resource name "(?!pwselfhostedvideo")/.test(m.text())) return;
    watched.errors.push(m.text() + (where ? ' (' + where + ')' : ''));
  });
  page.on('response', r => { if(r.status() >= 400) watched.failed.push(r.status() + ' ' + r.url()); });
  page.on('request', r => watched.requests.push({ method: r.method(), url: r.url(), body: r.postData() || '' }));
  return watched;
}

/**
 * Start the browser the run is for (chromium, firefox or webkit; see run.js). A browser that is not installed ends the
 * test with the code 3, which run.js counts as skipped.
 */
async function launch(options = {}) {
  const type = pw[browserName];
  if(!type) { console.error('Unknown browser: ' + browserName); process.exit(2); }
  const o = Object.assign({}, options);
  if(browserName === 'chromium') o.args = ['--autoplay-policy=no-user-gesture-required'].concat(o.args || []);
  if(browserName === 'firefox') o.firefoxUserPrefs = { 'media.autoplay.default': 0, 'media.autoplay.blocking_policy': 0 };
  try {
    const browser = await type.launch(o);
    console.log('browser: ' + browserName + ' ' + browser.version());
    return browser;
  } catch(e) {
    if(/Executable doesn't exist|install/i.test(e.message)) {
      console.log('skip ' + browserName + ' is not installed (npx playwright install ' + browserName + ')');
      process.exit(3);
    }
    throw e;
  }
}

/** Settings of a phone for a new context (Firefox has no mobile mode: it gets the width only) */
function phone() {
  const base = { viewport: { width: 375, height: 700 }, deviceScaleFactor: 2 };
  return browserName === 'firefox' ? base : Object.assign(base, { isMobile: true, hasTouch: true });
}

/**
 * Give a page time to finish loading, but do not wait for it: WebKit may never report the end of the loading of a page with
 * videos (they are fetched when they play), and the tests wait for what they need by itself.
 */
const settle = page => page.waitForLoadState('load', { timeout: 15000 }).catch(() => {});

/**
 * Open a page and wait for something on it. If it does not come, say what the browser got instead (the answer of the web
 * server, the address it ended at, the title and the first words), so that the reason is not a guess.
 */
async function open(page, url, selector, timeout = 20000) {
  let response = null;
  try {
    response = await page.goto(url, { waitUntil: 'domcontentloaded', timeout });
  } catch(e) {
    throw new Error('The browser could not open ' + url + ': ' + e.message.split('\n')[0]);
  }
  try {
    // (attached, not visible: the field of the videos may lie in a tab of its own that is not the one shown)
    await page.waitForSelector(selector, { state: 'attached', timeout });
  } catch(e) {
    const text = await page.evaluate(() => (document.body ? document.body.innerText : '').replace(/\s+/g, ' ').slice(0, 300)).catch(() => '');
    throw new Error('Opened ' + url + ' (answer ' + (response ? response.status() : '?') + ', ended at ' + page.url() + ', title "' + await page.title() + '") but there is no ' + selector + ' on it. The page says: ' + text);
  }
  return response;
}

/**
 * Sign in to the admin as the test user. Success is the login form being gone and a logout link being there (the masthead
 * is on the login page too). If it fails, say what the browser got: the address, the notice of the page and the cookies
 * (names and flags only), because WebKit may refuse a session cookie that Chromium and Firefox take.
 */
async function signIn(page) {
  await open(page, origin + state.admin, 'input[name=login_name]');
  await page.fill('input[name=login_name]', state.user);
  await page.fill('input[name=login_pass]', state.password);
  await Promise.all([
    page.waitForSelector('input[name=login_name]', { state: 'detached', timeout: 20000 }).catch(() => {}),
    page.click('button[name=login_submit]')
  ]);
  await settle(page);
  const inAdmin = await page.locator('a[href*="logout"]').count() > 0 && await page.locator('input[name=login_name]').count() === 0;
  let detail = '';
  if(!inAdmin) {
    const cookies = (await page.context().cookies()).map(c => c.name + ' (secure=' + c.secure + ', httpOnly=' + c.httpOnly + ', sameSite=' + c.sameSite + ', domain=' + c.domain + ')').join('; ');
    const notice = await page.evaluate(() => Array.from(document.querySelectorAll('.NoticeError, .uk-alert-danger, .ui-state-error')).map(e => e.innerText.trim()).join(' | ')).catch(() => '');
    detail = 'at ' + page.url() + '; notice: ' + (notice || 'none') + '; cookies: ' + (cookies || 'none');
  }
  return { inAdmin, detail };
}

module.exports = { signIn, origin, state, browserName, launch, phone, open, settle, section, ok, skip, summary, watch };
