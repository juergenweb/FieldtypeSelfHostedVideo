# Tests of Video Files for ProcessWire

The folder `tests/` is for development; the module does not need it. Over the module: [README](../README.md).

## Unit tests

The folder `tests/` holds unit tests for the parts of the module that decide things without a
browser: times and addresses of chapters and overlays, caption files (WebVTT and SubRip), the
answer to a request for a video file (ranges, validators), the rules for embedding on other
websites, reading MP4 and WebM files, preparing an MP4 for streaming, and small helpers such as
the check for a link to another website, the tags in texts (`[[video …]]`), what a video keeps
(texts, chapters, overlays, captions, reuse) and the markup it produces, including that texts
cannot break out of it. They build their own tiny video files and change no
page, field or file of the website, so they can be run on a site that is in use.

```
cd site/modules/FieldtypeSelfHostedVideo
php tests/run.php              # all of them
php tests/run.php Delivery     # only tests whose class or name contains "Delivery"
```

They run as they are, with a small stand-in for PHPUnit. To run them with PHPUnit 10 or 11, run
`composer install` in the folder of the module (it has a `composer.json` for this), then
`composer test` or `vendor/bin/phpunit`. Leave the `vendor` folder
out when you pass the module on.
The tests find ProcessWire from the folder of the module; to test another website, set the
environment variable `PW_ROOT` to its folder (the one with `index.php` and `wire/`).
New tests are classes in `tests/unit/` whose file name ends in `Test.php`.

Most tests only read. Some save pages to find out whether counts, tags and reused videos follow
a video that is renamed or merged (`StatsStoreTest`, `UsageStoreTest`, `MergeAndRenameTest`, `VideoDataTest`, `VideoRenderTest`, `PermissionsTest` (who may merge or see what), `LifecycleTest` (deleting, trashing and cloning pages with videos), `FinderTest` for the search
and choices of the Video Manager, `LanguagesTest` (needs a second language, otherwise skipped) and `OddFilesTest` for empty, cut off
or misnamed files). `MigrationTest` only reads: it tests the renaming from the old module's names, not the move itself.
Those are **skipped** unless you allow them, because they write to the database of the website:
they make two fields, a template and a few pages named `shvtest…`, and remove all of it again
when they are done (also what an interrupted run left behind). Run them on a test copy of the
website, or at least not while editors are working:

```
set SHV_TEST_WRITE=1        (Windows)        export SHV_TEST_WRITE=1        (Linux, macOS)
composer test
```

**Integration tests** (`tests/integration/`) ask the running website over HTTP, as a visitor and a player do: the page with
its player, the video file and parts of it (`Range`), the counting of plays, the video sitemap, and pages and files that must
stay closed (`WebsiteTest`); the titles of each language at its own address (`WebsiteLanguagesTest`, needs a second language
with `LanguageSupportPageNames`, otherwise skipped); asking again whether a file has changed (ETag, `304`), the thumbnail of
the player and of the structured data, and addresses with dots or of module files that must give nothing away
(`WebsiteFilesTest`; the `304` tests are skipped when the web server sends no validators); captions as tracks and files, transcript, chapters, overlays, structured data and the options of the tag in a text (`WebsiteContentTest`); the admin: the page with the field, the video manager and who may open it (`WebsiteAdminTest`, which makes two users of its own with a password made for the test, signs in over HTTP and removes them at the end). `WebsiteFilesTest` also checks that every file in `assets/` is sent with its type. `WebsiteManagerTest` (pages, order, tag and search of the list, made-up addresses), `WebsiteLifecycleTest` (what the website shows after a page or a video is deleted, renamed, replaced or hidden: file, page, sitemap, counted plays) and `WebsiteAjaxRightsTest` (the requests of the page editor's script change nothing for visitors, for users who may not edit the page, and for requests without the token of the form) use the same users. They need, besides `SHV_TEST_WRITE=1`, the address of the website in `SHV_TEST_URL`; without it they are skipped:

```
set SHV_TEST_URL=http://webseite2.test/      (Windows)        export SHV_TEST_URL=http://webseite2.test/
set SHV_TEST_WRITE=1
php tests/run.php Website
```

They put a template file `site/templates/shvtest.php` in place for the test pages and remove it at the end. The video
sitemap file is put back as it was (the tests save pages from the command line, which would write it with other addresses).

**Browser tests** (`tests/browser/`, optional) open the website in a real browser (Chromium, through [Playwright](https://playwright.dev/)).
They see what the tests over HTTP cannot: the JavaScript of the player and of the admin.

- `playback.js`: a visitor plays a video. The file plays and its time moves on, the script reports the play, a click on a
  chapter jumps there, overlays appear and disappear at their times (also the one at the end), the page has no errors in the
  console and no file that is not found, and on a phone's width it does not scroll sideways.
- `ckeditor.js`: the button of the module in CKEditor (needs a website with CKEditor, otherwise skipped): its icon, the dialog with
  the videos of the page, the tag it puts into the text, changing a tag that is there, and the page on the website that shows
  the video in its place.
- `admin-upload.js`: an editor signs in, opens a page, loads the video field with its files from `assets/`, uploads a video,
  waits for its thumbnail, saves, opens the page again and looks at the video on the website.

They need Node.js, and once (in `tests/browser/`):

```
npm install
npx playwright install chromium
```

Then, with the address of the website as for the other integration tests:

```
set SHV_TEST_URL=http://webseite2.test/      (Windows)        export SHV_TEST_URL=http://webseite2.test/
node run.js                                  (all)            node run.js playback   (or admin-upload, ckeditor)
```

To try the other browsers (Firefox, and WebKit, the engine of Safari), install them once and name them:

```
npx playwright install firefox webkit
node run.js --browser=all                    (or --browser=firefox, --browser=chromium,webkit)
```

A browser that is not installed is skipped. `playback.js` also checks that the browser sees the captions of the test video
and loads their file (a web server that sends `.vtt` without the type `text/vtt` may be accepted by one browser and not by
another), and it skips itself in a browser that cannot play the WebM video of the tests.

Tried in Chromium, Firefox and WebKit (Playwright 1.5x): all three pass `admin-upload.js` and `ckeditor.js`, Chromium and Firefox
also `playback.js`. The WebKit of Playwright does not really play the WebM video (it moves a clock but never learns the length), so
`playback.js` skips itself there; the same test in a browser with H.264 and MP4 files is a job for a real Safari. In a browser that
cannot play the video, `admin-upload.js` confirms the warning of the module before the upload and goes on with "Upload anyway".

`run.js` makes test pages, a template file `site/templates/shvtest.php` and a user `shvtest-browser` with a password made for
the run (the test pages and fields are the same as for `php tests/run.php`), runs the tests and removes everything, also when a
test fails. It sets `SHV_TEST_WRITE=1` itself, and `PHP` names the PHP program if it is not `php` in the path. The test videos are
WebM (`tests/browser/samples/`): the Chromium of Playwright plays VP9, but not the H.264 of most MP4 files.
