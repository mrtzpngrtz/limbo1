#!/usr/bin/env python3
# Usage: echo "body text" | python3 epaper_display.py "LIMBO — Cycle #N" "MARK12345"

import sys
import re
import time

sys.path.insert(0, '/home/llm/.local/lib/python3.11/site-packages/epaper/e-Paper/RaspberryPi_JetsonNano/python/lib/')
from waveshare_epd import epd7in5bc
from PIL import Image, ImageDraw, ImageFont

W, H = 384, 640  # portrait
MARGIN_X = 18
MARGIN_Y = 16
PAGE_PAUSE = 8000
FONT_DIR = '/usr/share/fonts/truetype/dejavu/'

SIZE_TITLE  = 88
SIZE_CYCLE  = 18
SIZE_MARK   = 22
SIZE_BODY   = 17
LINE_H      = 21


def load_font(size, bold=False):
    name = 'DejaVuSans-Bold.ttf' if bold else 'DejaVuSans.ttf'
    try:
        return ImageFont.truetype(FONT_DIR + name, size)
    except Exception:
        return ImageFont.load_default()


def wrap(text, font, max_w):
    dummy = ImageDraw.Draw(Image.new('1', (1, 1), 0))
    words = text.split()
    lines, cur = [], ''
    for w in words:
        test = (cur + ' ' + w).strip()
        if dummy.textbbox((0, 0), test, font=font)[2] > max_w and cur:
            lines.append(cur)
            cur = w
        else:
            cur = test
    if cur:
        lines.append(cur)
    return lines


def build_page(lines, title, mark, page_num, total_pages):
    img = Image.new('1', (W, H), 0)  # black background
    d = ImageDraw.Draw(img)

    font_title = load_font(SIZE_TITLE, bold=True)
    font_cycle = load_font(SIZE_CYCLE)
    font_mark  = load_font(SIZE_MARK, bold=True)
    font_body  = load_font(SIZE_BODY)

    y = MARGIN_Y

    # LIM / BO
    d.text((MARGIN_X, y), 'LIM', font=font_title, fill=255)
    y += int(SIZE_TITLE * 0.92)
    d.text((MARGIN_X, y), 'BO', font=font_title, fill=255)
    y += int(SIZE_TITLE * 0.92) + 10

    # separator
    d.line([(MARGIN_X, y), (W - MARGIN_X, y)], fill=255, width=1)
    y += 8

    # cycle title
    d.text((MARGIN_X, y), title, font=font_cycle, fill=255)
    y += SIZE_CYCLE + 10

    # mark
    if mark:
        d.text((MARGIN_X, y), mark, font=font_mark, fill=255)
        y += SIZE_MARK + 12

    # separator
    d.line([(MARGIN_X, y), (W - MARGIN_X, y)], fill=255, width=1)
    y += 10

    # body lines
    for line in lines:
        if y + LINE_H > H - MARGIN_Y:
            break
        d.text((MARGIN_X, y), line, font=font_body, fill=255)
        y += LINE_H

    # page indicator
    if total_pages > 1:
        pi = f'{page_num}/{total_pages}'
        d.text((W - MARGIN_X - 40, H - MARGIN_Y - 16), pi, font=font_cycle, fill=255)

    return img


def render(title, text, mark=''):
    font_body = load_font(SIZE_BODY)
    max_w = W - MARGIN_X * 2

    # estimate lines that fit after header
    font_title = load_font(SIZE_TITLE, bold=True)
    header_h = (MARGIN_Y
                + int(SIZE_TITLE * 0.92) * 2 + 10
                + 1 + 8
                + SIZE_CYCLE + 10
                + (SIZE_MARK + 12 if mark else 0)
                + 1 + 10)
    lines_per_page = max(1, (H - header_h - MARGIN_Y) // LINE_H)

    all_lines = wrap(text, font_body, max_w)
    if not all_lines:
        all_lines = ['']

    pages = [all_lines[i:i + lines_per_page]
             for i in range(0, len(all_lines), lines_per_page)]

    epd = epd7in5bc.EPD()
    epd.init()

    page_idx = 0
    while True:
        img = build_page(pages[page_idx], title, mark, page_idx + 1, len(pages))
        red = Image.new('1', (W, H), 255)
        epd.display(epd.getbuffer(img), epd.getbuffer(red))

        if len(pages) <= 1:
            break
        epd.delay_ms(PAGE_PAUSE)
        page_idx = (page_idx + 1) % len(pages)

    epd.sleep()


if __name__ == '__main__':
    title = sys.argv[1] if len(sys.argv) > 1 else 'LIMBO'
    mark  = sys.argv[2] if len(sys.argv) > 2 else ''
    raw   = sys.stdin.read().strip()
    text  = re.sub(r'(?m)^MARK:.*\n?', '', raw).strip()
    render(title, text, mark)
