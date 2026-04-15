#!/usr/bin/env python3
import sys
import textwrap
import time
import os
sys.path.insert(0, "/home/llm/e-Paper/RaspberryPi_JetsonNano/python/lib")
from waveshare_epd import epd7in5
from PIL import Image, ImageDraw, ImageFont

# e-paper native: 640x384, displayed portrait: 384 wide x 640 tall
P_WIDTH  = 384
P_HEIGHT = 640

MARGIN_X = 28
MARGIN_Y = 36

FONT_PATH = "/home/llm/limbo_font.ttf"
FONT_FALLBACK = "/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf"

SIZE_TITLE  = 48
SIZE_CYCLE  = 15
SIZE_BODY   = 14
PAGE_PAUSE  = 8

def load_font(size, bold=False):
    path = FONT_PATH if os.path.exists(FONT_PATH) else FONT_FALLBACK
    # Try bold variant for title
    if bold:
        bold_path = FONT_PATH.replace(".ttf", "-Bold.ttf") if os.path.exists(FONT_PATH) else "/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf"
        try:
            return ImageFont.truetype(bold_path, size)
        except:
            pass
    try:
        return ImageFont.truetype(path, size)
    except:
        return ImageFont.load_default()

def render(cycle_num, text, mark=""):
    epd = epd7in5.EPD()
    epd.init()
    epd.Clear()

    font_title = load_font(SIZE_TITLE, bold=True)
    font_cycle = load_font(SIZE_CYCLE)
    font_body  = load_font(SIZE_BODY)

    # Measure header height
    header_h = MARGIN_Y + SIZE_TITLE + 8 + SIZE_CYCLE + 32

    # Available height for body text
    body_h = P_HEIGHT - header_h - MARGIN_Y
    line_h = SIZE_BODY + 5
    lines_per_page = body_h // line_h

    # Estimate chars per line
    avg_char_w = SIZE_BODY * 0.52
    max_chars = int((P_WIDTH - MARGIN_X * 2) / avg_char_w)

    all_lines = []
    for para in text.split("\n"):
        wrapped = textwrap.wrap(para.strip(), width=max_chars)
        all_lines.extend(wrapped if wrapped else [""])

    pages = [all_lines[i:i+lines_per_page] for i in range(0, len(all_lines), lines_per_page)]
    if not pages:
        pages = [[]]

    font_cycle_bold = load_font(SIZE_CYCLE, bold=True)

    def render_page(page_lines, page_idx):
        img = Image.new("1", (P_WIDTH, P_HEIGHT), 0)
        d = ImageDraw.Draw(img)

        y = MARGIN_Y

        # LIM / BO
        d.text((MARGIN_X, y), "LIM", font=font_title, fill=255)
        y += int(SIZE_TITLE * 0.92)
        d.text((MARGIN_X, y), "BO", font=font_title, fill=255)
        y += SIZE_TITLE + 20

        # Zyklus #N
        d.text((MARGIN_X, y), "Zyklus ", font=font_cycle, fill=255)
        prefix_w = int(len("Zyklus ") * SIZE_CYCLE * 0.52)
        d.text((MARGIN_X + prefix_w, y), f"#{cycle_num}", font=font_cycle_bold, fill=255)
        y += SIZE_CYCLE + 20

        # Mark (10 chars)
        if mark:
            font_mark = load_font(SIZE_CYCLE)
            d.text((MARGIN_X, y), mark, font=font_mark, fill=255)
            y += SIZE_CYCLE + 20

        y += 12

        # Body
        for line in page_lines:
            d.text((MARGIN_X, y), line, font=font_body, fill=255)
            y += line_h

        # Page indicator
        if len(pages) > 1:
            d.text((P_WIDTH - MARGIN_X - 30, P_HEIGHT - MARGIN_Y), f"{page_idx+1}/{len(pages)}", font=font_cycle, fill=255)

        img = img.rotate(90, expand=True)
        epd.display(epd.getbuffer(img))

    if len(pages) == 1:
        render_page(pages[0], 0)
        epd.sleep()
    else:
        while True:
            for i, page_lines in enumerate(pages):
                render_page(page_lines, i)
                time.sleep(PAGE_PAUSE)
                epd.init()

if __name__ == "__main__":
    title_arg = sys.argv[1] if len(sys.argv) > 1 else "Cycle #0"
    mark_arg  = sys.argv[2] if len(sys.argv) > 2 else ""
    try:
        cycle_num = title_arg.split("#")[1].strip()
    except:
        cycle_num = "0"

    import re, io

    raw = io.TextIOWrapper(sys.stdin.buffer, encoding='utf-8', errors='replace').read()

    # Normalize literal \n sequences to real newlines
    raw = raw.replace('\\n', '\n')

    # Strip ANSI escape codes
    raw = re.sub(r'\x1b\[[0-9;?]*[a-zA-Z]', '', raw)
    raw = re.sub(r'\x1b[^a-zA-Z]*[a-zA-Z]', '', raw)

    # Keep printable ASCII + German umlauts, no MARK line
    raw = re.sub(r'[^\x20-\x7eäöüÄÖÜß\n]', '', raw)
    raw = re.sub(r'(?m)^MARK:.*$', '', raw)
    raw = re.sub(r' +', ' ', raw).strip()

    render(cycle_num, raw, mark_arg)
