#!/usr/bin/env python3
# Usage: echo "body text" | python3 led_display.py "LIMBO — Cycle #N"
#        echo "body text" | python3 led_display.py "LIMBO — Cycle #N" --loop
# Normal: hold 5s → scroll to end → wait 2s → exit
# Loop:   scroll endlessly with --- message start/end --- markers

import sys
import re
import time
import numpy as np
from PIL import Image, ImageDraw
import adafruit_blinka_raspberry_pi5_piomatter as piomatter
import bdf

W, H = 64, 64
FONT_DIR = '/home/llm/rpi-rgb-led-matrix/fonts/'
HEADER_H = 8
LINE_H   = 7

WHITE = (192, 192, 192)
DIM   = (60,  60,  60)
BLACK = (0,   0,   0)

HOLD_SECS     = 5
END_WAIT_SECS = 2
SCROLL_PX_S   = 8  # pixels per second


def word_wrap(glyphs, text, max_w):
    words = text.split()
    lines, cur = [], ''
    for w in words:
        test = (cur + ' ' + w).strip()
        if bdf.textwidth(glyphs, test) > max_w and cur:
            lines.append(cur)
            cur = w
        else:
            cur = test
    if cur:
        lines.append(cur)
    return lines


def run(title, text, loop=False):
    font_hdr  = bdf.load(FONT_DIR + '5x7.bdf')
    font_body = bdf.load(FONT_DIR + '4x6.bdf')

    cycle_num  = '#' + title.split('#')[-1].strip() if '#' in title else title[:8]
    lines      = word_wrap(font_body, text, W - 4)
    total_h    = HEADER_H + len(lines) * LINE_H
    max_scroll = max(0, total_h - H + 4)

    framebuffer = np.zeros((H, W, 4), dtype=np.uint8)
    matrix = piomatter.PioMatter(
        colorspace=piomatter.Colorspace.RGB888,
        pinout=piomatter.Pinout.Active3,
        framebuffer=framebuffer,
        geometry=piomatter.Geometry(W, H, 5, rotation=piomatter.Orientation.R180),
    )

    scroll_pos = 0
    state      = 'hold'
    state_t    = time.time()

    while True:
        now = time.time()

        if state == 'hold':
            if now - state_t >= HOLD_SECS:
                state   = 'scroll'
                state_t = now

        elif state == 'scroll':
            scroll_pos = min(int((now - state_t) * SCROLL_PX_S), max_scroll)
            if scroll_pos >= max_scroll:
                state   = 'end_wait'
                state_t = now

        elif state == 'end_wait':
            if now - state_t >= END_WAIT_SECS:
                if loop:
                    scroll_pos = 0
                    state      = 'hold'
                    state_t    = now
                else:
                    break

        img = Image.new('RGB', (W, H), BLACK)
        d   = ImageDraw.Draw(img)

        for i, line in enumerate(lines):
            y = HEADER_H + i * LINE_H - scroll_pos
            if -LINE_H < y < H:
                bdf.draw_text(d, font_body, (2, y), line, fill=WHITE)

        d.rectangle([(0, 0), (W - 1, HEADER_H - 1)], fill=WHITE)
        bdf.draw_text(d, font_hdr, (2, 1), cycle_num, fill=BLACK, spacing=2)

        framebuffer[:] = np.array(img.convert('RGBA'))
        matrix.show()
        time.sleep(0.05)


if __name__ == '__main__':
    args      = sys.argv[1:]
    loop_mode = '--loop' in args
    args      = [a for a in args if a != '--loop']
    title     = args[0] if args else 'LIMBO'
    raw       = sys.stdin.read().strip()
    text      = re.sub(r'(?m)^MARK:.*\n?', '', raw).strip()
    if loop_mode:
        text = '--- message start ---\n\n' + text + '\n\n--- message end ---'
    run(title, text, loop=loop_mode)
