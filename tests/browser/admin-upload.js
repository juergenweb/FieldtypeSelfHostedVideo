// An editor adds a video to a page in the admin: the field loads its files, the upload goes through, the thumbnail comes,
// the page can be saved, and the video is on the website.
const path = require('path');
const { origin, state, launch, signIn, open, settle, section, ok, summary, watch } = require('./lib');

(async () => {
  const browser = await launch();
  const context = await browser.newContext({ viewport: { width: 1280, height: 1000 } });
  const page = await context.newPage();
  const watched = watch(page);
  page.on('dialog', d => d.accept());

  section('signing in');
  const login = await signIn(page);
  const signedIn = login.inAdmin;
  ok(signedIn, 'the test user is in the admin', login.detail);
  if(!signedIn) { await browser.close(); process.exit(summary()); }

  section('the page with the field');
  const editor = origin + state.admin + 'page/edit/?id=' + state.upload.id;
  const wrap = '#wrap_Inputfield_shvtest_videos';
  await open(page, editor, wrap, 30000);
  await settle(page);
  ok(await page.locator(wrap).count() === 1, 'the video field is in the form');
  const loaded = await page.evaluate(() => ({ css: Array.from(document.styleSheets).some(s => /assets\/InputfieldSelfHostedVideo\.css/.test(s.href || '')),
    js: Array.from(document.scripts).some(s => /assets\/InputfieldSelfHostedVideo\.js/.test(s.src)) }));
  ok(loaded.css && loaded.js, 'its stylesheet and script from assets/ are loaded', JSON.stringify(loaded));
  const input = page.locator(wrap + ' .InputfieldFileUpload input[type=file]');
  ok(await input.count() === 1, 'one field to choose a file');

  section('uploading');
  await input.setInputFiles(path.join(__dirname, 'samples', 'upload.webm'));
  const box = wrap + ' .InputfieldSelfHostedVideoBox[data-name="upload.webm"]';
  // (a video in a field of several is an item of an accordion that may be closed: it is there, not necessarily open)
  // (a browser that cannot play the test video, WebM, asks before the upload whether to go on: the module warns the editor.
  // That is its job; the test confirms it, and goes on with the upload as an editor would)
  const anyway = wrap + ' .InputfieldSelfHostedVideoAnyway:not([hidden])';
  const first = await Promise.race([
    page.waitForSelector(box, { state: 'attached', timeout: 30000 }).then(() => 'box'),
    page.waitForSelector(anyway, { state: 'attached', timeout: 30000 }).then(() => 'warning')
  ]).catch(() => 'none');
  if(first === 'warning') {
    ok(true, 'this browser cannot play the test video: the module warns before the upload and offers to go on');
    await page.locator(anyway).first().evaluate(e => e.click());
  }
  const posts = [];
  page.on('response', async r => { if(r.request().method() === 'POST') posts.push(r.status() + ' ' + (r.url().match(/shv=\w+/) || ['form'])[0] + ' ' + (await r.text().catch(() => '')).replace(/\s+/g, ' ').slice(0, 200)); });
  try {
    await page.waitForSelector(box, { state: 'attached', timeout: 60000 });
  } catch(e) {
    const fieldText = await page.evaluate(w => (document.querySelector(w) || { innerText: '' }).innerText.replace(/\s+/g, ' ').slice(0, 500), wrap).catch(() => '');
    ok(false, 'the video arrives in the page', 'the field says: ' + fieldText + ' | requests: ' + (posts.join(' || ') || 'none') + ' | console: ' + watched.errors.join(' | '));
    await browser.close(); process.exit(summary());
  }
  ok(true, 'the video arrives in the page');
  await page.waitForFunction(s => { const b = document.querySelector(s); return b && (b.getAttribute('data-needs-thumb') === '0' || b.classList.contains('InputfieldSelfHostedVideoNoThumb')); }, box, { timeout: 60000 });
  ok(true, 'its thumbnail is made or asked for');

  section('saving');
  await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded' }), page.click('#Inputfield_submit, button[name=submit_save]')]);
  await settle(page);
  await page.goto(editor, { waitUntil: 'domcontentloaded' });
  await settle(page);
  ok(await page.locator(box).count() === 1, 'after saving the video is still there when the page is opened again');

  section('on the website');
  const visitor = await browser.newContext();
  const site = await visitor.newPage();
  const watchedSite = watch(site);
  await site.goto(origin + state.upload.path, { waitUntil: 'domcontentloaded' });
  ok(await site.locator('video').count() === 1, 'the page shows the video');
  ok(/upload\.webm/.test(await site.locator('video').first().evaluate(v => v.currentSrc || v.src || (v.querySelector('source') || {}).src || '')), 'it is the file that was uploaded');
  ok(watchedSite.failed.length === 0, 'every file the page asked for was found', watchedSite.failed.join(' | '));
  await visitor.close();

  section('the admin page');
  ok(watched.errors.length === 0, 'no error of the script or in the console', watched.errors.join(' | '));
  const missing = watched.failed.filter(f => /FieldtypeSelfHostedVideo/.test(f));
  ok(missing.length === 0, 'every file of the module was found', missing.join(' | '));

  await browser.close();
  process.exit(summary());
})().catch(e => { console.error(e); process.exit(1); });
