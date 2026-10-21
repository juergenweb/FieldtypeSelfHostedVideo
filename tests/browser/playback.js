// A visitor plays a video: the player, its chapters and overlays, the count of plays, and a phone's width.
const { origin, state, browserName, launch, phone, open, settle, section, ok, skip, summary, watch } = require('./lib');

(async () => {
  // (the browsers of Playwright play WebM; the H.264 of MP4 files not all of them have)
  const browser = await launch();
  const url = origin + state.play.path;
  // a video that is muted may start without a click in every browser
  // (play() is not waited for: in WebKit its answer may not come while the file is still being fetched)
  const start = async page => {
    await page.evaluate(() => { const v = document.querySelector('video'); v.muted = true; v.play().catch(() => {}); });
  };
  const until = (page, fn, arg, timeout = 15000) => page.waitForFunction(fn, arg, { timeout }).catch(async e => {
    const v = await page.evaluate(() => { const v = document.querySelector('video'); return v ? { paused: v.paused, time: v.currentTime, readyState: v.readyState, networkState: v.networkState, error: v.error && v.error.code, src: v.currentSrc } : null; }).catch(() => null);
    throw new Error('Waiting for the video timed out after ' + timeout + ' ms. The video is: ' + JSON.stringify(v));
  });
  // (not waiting for the event alone: WebKit may not send "seeked" for a file that is still loading; the time reached counts)
  const seek = (page, time) => page.evaluate(t => new Promise(done => {
    const v = document.querySelector('video');
    let finished = false;
    const end = () => { if(!finished) { finished = true; setTimeout(done, 80); } };
    v.addEventListener('seeked', end, { once: true });
    v.currentTime = t;
    const look = () => { if(finished) return; if(Math.abs(v.currentTime - t) < 0.3 && !v.seeking) end(); else setTimeout(look, 100); };
    look();
    setTimeout(end, 4000);
  }), time);
  const overlays = page => page.evaluate(() => Array.from(document.querySelectorAll('.shv-video__overlay')).map(o => ({ text: o.textContent.trim(), hidden: o.hidden || getComputedStyle(o).display === 'none' || getComputedStyle(o).visibility === 'hidden' })));
  const shown = async page => (await overlays(page)).filter(o => !o.hidden).map(o => o.text);

  const context = await browser.newContext({ viewport: { width: 1200, height: 900 } });
  const page = await context.newPage();
  const watched = watch(page);
  await open(page, url, 'video');
  // (canPlayType says "maybe" in browsers that cannot really play the file: the test tries it on a video of its own)
  const canPlay = await page.evaluate(() => new Promise(done => {
    const src = (document.querySelector('video source') || document.querySelector('video')).getAttribute('src');
    const v = document.createElement('video');
    v.muted = true;
    v.preload = 'auto';
    v.addEventListener('error', () => done(false));
    // (really playing: the time moves on AND the browser knows the length; some builds move a clock without playing)
    v.addEventListener('timeupdate', () => { if(v.currentTime > 0.3 && Number.isFinite(v.duration) && v.readyState >= 2) { v.pause(); done(true); } });
    v.src = src;
    v.play().catch(() => done(false));
    setTimeout(() => done(false), 10000);
  }));
  if(!canPlay) {
    skip(browserName + ' cannot play the VP9 video of the tests (WebM)');
    await browser.close();
    process.exit(summary());
  }

  section('the player');
  ok(await page.locator('video').count() === 1, 'one video on the page');
  const title = await page.title();
  ok(/Browser test: playing$/.test(title), 'the page is the test page', 'title "' + title + '", address ' + page.url());
  // (the file is fetched when it is played: a page leaves it alone until then to save the visitor's data)
  section('playing');
  await start(page);
  await until(page, () => document.querySelector('video').currentTime > 0.8);
  await until(page, () => Number.isFinite(document.querySelector('video').duration));
  const state1 = await page.evaluate(() => { const v = document.querySelector('video'); return { paused: v.paused, time: v.currentTime, duration: v.duration, error: v.error && v.error.code, src: v.currentSrc }; });
  ok(!state1.error, 'the browser can play the file', String(state1.error));
  ok(!state1.paused && state1.time > 0.8, 'it plays and the time moves on', JSON.stringify(state1));
  ok(Math.abs(state1.duration - 6) < 0.6, 'it is six seconds long', String(state1.duration));
  ok(/\/files\/\d+\/play\.webm/.test(state1.src), 'it is the file of the page', state1.src);
  // (a report goes out as a beacon, whose content the browser does not show: the request is what can be seen)
  await page.waitForTimeout(800);
  ok(watched.requests.some(r => r.method === 'POST' && /selfhostedvideo-stats/.test(r.url)), 'the script reports the play to the website');
  await page.evaluate(() => document.querySelector('video').pause());

  section('captions');
  const tracks = await page.evaluate(() => Array.from(document.querySelector('video').textTracks).map(t => t.language + ':' + t.kind));
  ok(tracks.length === 1 && /^en:(subtitles|captions)$/.test(tracks[0]), 'the browser sees the English captions', tracks.join(','));
  const cues = await page.evaluate(() => new Promise(done => {
    const t = document.querySelector('video').textTracks[0];
    t.mode = 'hidden';
    const look = () => { if(t.cues && t.cues.length) done(t.cues.length); else setTimeout(look, 100); };
    look();
    setTimeout(() => done(t.cues ? t.cues.length : 0), 8000);
  }));
  ok(cues === 2, 'it loads the file: two captions', String(cues));

  section('chapters');
  const chapters = page.locator('.shv-video__chapter-list [data-shv-seek]');
  ok(await chapters.count() === 2, 'two chapters are listed', String(await chapters.count()));
  await chapters.nth(1).click();
  await until(page, () => document.querySelector('video').currentTime >= 2.8);
  const at = await page.evaluate(() => document.querySelector('video').currentTime);
  ok(Math.abs(at - 3) < 0.6, 'a click on the second chapter goes to three seconds', String(at));

  section('overlays');
  await page.evaluate(() => document.querySelector('video').pause());
  await seek(page, 1);
  let now = await shown(page);
  ok(now.includes('Always shown') && !now.includes('Only in the middle'), 'at one second: the one that is always there', now.join('|'));
  await seek(page, 3);
  now = await shown(page);
  ok(now.includes('Only in the middle'), 'at three seconds: the one for the middle', now.join('|'));
  await seek(page, 5);
  now = await shown(page);
  ok(!now.includes('Only in the middle') && !now.includes('Thanks for watching'), 'at five seconds: the middle one is gone, the last not there yet', now.join('|'));
  await start(page);
  await until(page, () => document.querySelector('video').ended, null, 20000);
  await page.waitForTimeout(400);
  now = await shown(page);
  ok(now.includes('Thanks for watching'), 'at the end: the thanks appear', now.join('|'));

  section('the page itself');
  ok(watched.errors.length === 0, 'no error of the script or in the console', watched.errors.join(' | '));
  ok(watched.failed.length === 0, 'every file the page asked for was found', watched.failed.join(' | '));
  await context.close();

  section('on a phone');
  const phoneContext = await browser.newContext(phone());
  const small = await phoneContext.newPage();
  const watchedSmall = watch(small);
  await small.goto(url, { waitUntil: 'domcontentloaded' });
  await small.waitForSelector('video');
  const sizes = await small.evaluate(() => ({ page: document.documentElement.scrollWidth, window: window.innerWidth, video: document.querySelector('video').getBoundingClientRect().width }));
  ok(sizes.page <= sizes.window + 1, 'the page does not scroll sideways', JSON.stringify(sizes));
  ok(sizes.video > 250 && sizes.video <= sizes.window, 'the video is as wide as the screen allows', JSON.stringify(sizes));
  ok(watchedSmall.errors.length === 0, 'no errors', watchedSmall.errors.join(' | '));
  await phoneContext.close();

  await browser.close();
  process.exit(summary());
})().catch(e => { console.error(e); process.exit(1); });
