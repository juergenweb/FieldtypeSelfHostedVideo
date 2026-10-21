# Change Log
All notable changes to this project will be documented in this file.

## [1.0.0] - 2026-10-21

### Added
- The field for self-hosted video files (single and multiple videos) on top of the core file field, with duration,
  dimensions, titles and descriptions per language, tags, custom fields and searchable video properties
- The video input in the page editor: upload of large files in parts that goes on after an interruption, replacing a file,
  thumbnail from a frame of the video or from an image, a checklist per video, captions (WebVTT and SubRip), an editor for
  chapters and overlays, and the preparation of MP4 files for streaming
- Output on the website with `render()`: lazy loading, stable size, transcript, chapters, overlays, going on where the
  visitor stopped, an end of the video (watch again, next video, link), background videos and links to a moment (`?t=83`)
- Structured data (`VideoObject`), Open Graph tags and a video sitemap for search engines
- Videos in a text with the text formatter "Videos in text" and a button in TinyMCE and CKEditor
- Protected videos for pages that only some visitors may view, and a setting against embedding on other websites
- Anonymous play statistics per video, without cookies
- Reusing a video on other pages without a second copy of the file, and a list of the places of each video
- The Video Manager (Setup > Video Manager): all videos of the site with filters, actions for several videos at once and
  the finding of videos that were uploaded more than once
- Class names for the CSS frameworks UIkit 3, Bootstrap 5 and Bulma; more frameworks as one file each
- German translation, also for RockLanguage

### Changed
- The stylesheets and scripts of the module are in the folder `assets/`
- The README is an overview; the complete documentation is in `docs/DOCUMENTATION.md`
- Tests are left out of the download of the module (`.gitattributes`)
- License: MIT

### Tested
- ProcessWire 3.0.227 and 3.0.259 with PHP 8.3
- Browsers: Chromium, Firefox and WebKit (the playback of the test video is not tried in the WebKit of Playwright, which
  does not play WebM files)
