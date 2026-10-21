// An editor puts a video into a text with the button of the module in CKEditor: the button and its icon, the dialog, the
// tag it makes, changing a tag that is there, and the page on the website that shows the video in its place.
const { signIn, origin, state, launch, open, settle, section, ok, skip, summary, watch } = require('./lib');

(async () => {
  if(!state.editor.ckeditor) { skip('this website has no CKEditor, so there is no button of the module to try'); process.exit(summary()); }
  const browser = await launch();
  const context = await browser.newContext({ viewport: { width: 1280, height: 1000 } });
  const page = await context.newPage();
  const watched = watch(page);
  page.on('dialog', d => { watched.errors.push('alert: ' + d.message()); d.accept(); });

  section('signing in');
  const login = await signIn(page);
  ok(login.inAdmin, 'the test user is in the admin', login.detail);
  if(!login.inAdmin) { await browser.close(); process.exit(summary()); }

  section('the editor');
  const editorUrl = origin + state.admin + 'page/edit/?id=' + state.editor.id;
  await open(page, editorUrl, '#wrap_Inputfield_shvtest_body', 30000);
  await settle(page);
  await page.waitForFunction(() => window.CKEDITOR && Object.keys(CKEDITOR.instances).some(k => /shvtest_body/.test(k) && CKEDITOR.instances[k].status === 'ready'), null, { timeout: 30000 });
  const name = await page.evaluate(() => Object.keys(CKEDITOR.instances).find(k => /shvtest_body/.test(k)));
  const data = () => page.evaluate(n => CKEDITOR.instances[n].getData(), name);
  const setData = html => page.evaluate(([n, h]) => new Promise(done => CKEDITOR.instances[n].setData(h, { callback: done })), [name, html]);
  const focusEnd = () => page.evaluate(n => { const e = CKEDITOR.instances[n]; e.focus(); const r = e.createRange(); r.moveToElementEditEnd(e.editable().getLast()); e.getSelection().selectRanges([r]); }, name);
  const button = page.locator('.cke_button__pwselfhostedvideo');
  ok(await button.count() === 1, 'the toolbar has the button of the module');
  const icon = await page.evaluate(() => { const i = document.querySelector('.cke_button__pwselfhostedvideo_icon'); return i ? getComputedStyle(i).backgroundImage : ''; });
  ok(/pwselfhostedvideo-ckeditor\.svg/.test(icon), 'its icon is the picture of the module', icon);
  ok(watched.failed.filter(f => /pwselfhostedvideo/.test(f)).length === 0, 'and the file of the icon was found', watched.failed.join(' | '));

  section('inserting a video');
  await setData('<p>Intro</p>');
  await focusEnd();
  await button.click();
  await page.waitForSelector('.cke_dialog', { state: 'visible', timeout: 15000 });
  const options = await page.evaluate(() => { const d = CKEDITOR.dialog.getCurrent(); return d.getContentElement('main', 'video').getInputElement().$.options.length ? Array.from(d.getContentElement('main', 'video').getInputElement().$.options).map(o => o.text + '|' + o.value) : []; });
  ok(options.length === 1 && /Dialog test film/.test(options[0]) && new RegExp('\\|' + state.editor.file.replace('.', '\\.') + '$').test(options[0]), 'the dialog offers the video of the page by its title', options.join(' ; '));
  await page.evaluate(() => { const d = CKEDITOR.dialog.getCurrent(); d.setValueOf('main', 'caption', 'none'); d.setValueOf('main', 'layout', 'right'); });
  await page.evaluate(() => CKEDITOR.dialog.getCurrent().getButton('ok').click());
  await page.waitForSelector('.cke_dialog', { state: 'hidden', timeout: 10000 });
  let html = await data();
  ok(html.includes('[[video ' + state.editor.file + ' nocaption align=right width=50]]'), 'the text has the tag with the choices', html);
  ok(html.includes('Intro'), 'and keeps the text that was there', html);

  section('changing a tag');
  await setData('<p>Intro</p><p>[[video ' + state.editor.file + ' nocaption align=left width=30]]</p>');
  await focusEnd();
  await button.click();
  await page.waitForSelector('.cke_dialog', { state: 'visible', timeout: 15000 });
  const shown = await page.evaluate(() => { const d = CKEDITOR.dialog.getCurrent(); return { caption: d.getValueOf('main', 'caption'), layout: d.getValueOf('main', 'layout') }; });
  ok(shown.caption === 'none' && shown.layout === 'left', 'the dialog shows what the tag says', JSON.stringify(shown));
  await page.evaluate(() => { const d = CKEDITOR.dialog.getCurrent(); d.setValueOf('main', 'layout', ''); d.getButton('ok').click(); });
  await page.waitForSelector('.cke_dialog', { state: 'hidden', timeout: 10000 });
  html = await data();
  ok(html.includes('[[video ' + state.editor.file + ' nocaption]]') && !/align=/.test(html), 'full width takes out the place and keeps the rest', html);
  ok((html.match(/\[\[video/g) || []).length === 1, 'one tag, not two', html);

  section('saving and the website');
  await page.evaluate(n => CKEDITOR.instances[n].updateElement(), name);
  await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded' }), page.click('#Inputfield_submit, button[name=submit_save]')]);
  await settle(page);
  const site = await (await browser.newContext()).newPage();
  const watchedSite = watch(site);
  await site.goto(origin + state.editor.path, { waitUntil: 'domcontentloaded' });
  ok(await site.locator('video').count() === 1, 'the page shows the video once');
  ok(!/\[\[video/.test(await site.locator('main').first().innerText()), 'and no tag is left in the text');
  ok(watchedSite.failed.length === 0, 'every file the page asked for was found', watchedSite.failed.join(' | '));

  section('the admin page');
  const mine = watched.errors.filter(e => !/favicon/.test(e));
  ok(mine.length === 0, 'no error of the script, no alert', mine.join(' | '));

  await browser.close();
  process.exit(summary());
})().catch(e => { console.error(e); process.exit(1); });
