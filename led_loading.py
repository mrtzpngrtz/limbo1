#!/usr/bin/env python3
# Usage: python3 led_loading.py "Cycle #1413"

import sys
import time
import numpy as np
from PIL import Image, ImageDraw
import adafruit_blinka_raspberry_pi5_piomatter as piomatter
import bdf

W, H = 64, 64
FONT_DIR = '/home/llm/rpi-rgb-led-matrix/fonts/'

WHITE = (255, 255, 255)
DIM   = (80,  80,  80)
BLACK = (0,   0,   0)

def build_perimeter():
    pts  = [(x, 0)      for x in range(W)]
    pts += [(W-1, y)    for y in range(1, H)]
    pts += [(x, H-1)    for x in range(W-2, -1, -1)]
    pts += [(0, y)      for y in range(H-2, 0, -1)]
    return pts

PERIMETER = build_perimeter()
PLEN = len(PERIMETER)


def run(cycle_label):
    font = bdf.load(FONT_DIR + '6x10.bdf')

    framebuffer = np.zeros((H, W, 4), dtype=np.uint8)
    matrix = piomatter.PioMatter(
        colorspace=piomatter.Colorspace.RGB888,
        pinout=piomatter.Pinout.Active3,
        framebuffer=framebuffer,
        geometry=piomatter.Geometry(W, H, 5, rotation=piomatter.Orientation.R180),
    )

    matrix.show()

    tw = bdf.textwidth(font, cycle_label)
    tx = (W - tw) // 2
    ty = (H - 10) // 2

    img = Image.new('RGB', (W, H), BLACK)
    bdf.draw_text(ImageDraw.Draw(img), font, (tx, ty), cycle_label, fill=DIM)
    base = np.array(img.convert('RGBA'))

    frame = 0
    while True:
        buf = base.copy()
        px, py = PERIMETER[frame % PLEN]
        buf[py, px] = (255, 255, 255, 255)
        framebuffer[:] = buf
        matrix.show()

        frame += 1
        time.sleep(0.03)


if __name__ == '__main__':
    label = sys.argv[1] if len(sys.argv) > 1 else ''
    cycle_label = '#' + label.split('#')[-1].strip() if '#' in label else label[:8]
    run(cycle_label)
