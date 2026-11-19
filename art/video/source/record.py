"""Records each scene (served by the demo app) to webm with Playwright."""
import os
import shutil
import sys
from pathlib import Path

from playwright.sync_api import sync_playwright

ROOT = Path(os.environ.get('VIDEO_WORKDIR', '.')).resolve()
CLIPS = ROOT / 'clips'
BASE = 'http://127.0.0.1:8765/_video/'
only = sys.argv[1:]

scenes = sorted(p.stem for p in (ROOT / 'scenes').glob('*.html'))
CLIPS.mkdir(exist_ok=True)
with sync_playwright() as pw:
    browser = pw.chromium.launch()
    for name in scenes:
        if only and name not in only:
            continue
        tmp = ROOT / 'tmp-rec'
        shutil.rmtree(tmp, ignore_errors=True)
        ctx = browser.new_context(viewport={'width': 1280, 'height': 720}, record_video_dir=str(tmp),
                                  record_video_size={'width': 1280, 'height': 720}, device_scale_factor=1)
        page = ctx.new_page()
        page.goto(BASE + name + '.html')
        page.wait_for_function('window.done === true', timeout=120_000, polling=100)
        if name == '01-title':
            page.screenshot(path=str(ROOT / 'poster.png'))
        video = page.video
        ctx.close()
        dest = CLIPS / f'{name}.webm'
        Path(video.path()).replace(dest)
        print('recorded', dest.name)
    browser.close()
