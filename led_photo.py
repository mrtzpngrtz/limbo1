#!/usr/bin/env python3
# Usage: python3 led_photo.py "#1234"
# Phase 1: live camera preview + 20s countdown → takes photo
# Phase 2: still image + blinking INFERENCE (until killed)
# Writes: /tmp/limbo_cam.jpg, /tmp/limbo_cam_desc.txt, /tmp/limbo_cam_b64.txt

import sys
import time
import subprocess
import threading
import numpy as np
from PIL import Image, ImageDraw
import adafruit_blinka_raspberry_pi5_piomatter as piomatter
import bdf

W, H    = 64, 64
FONT_DIR = '/home/llm/rpi-rgb-led-matrix/fonts/'
WHITE   = (192, 192, 192)
BLACK   = (0,   0,   0)
HEADER_H = 14
X       = 2
COUNTDOWN = 60

LIVE_FILE  = '/tmp/led_live.jpg'
PHOTO_FILE = '/tmp/limbo_cam.jpg'
DESC_FILE  = '/tmp/limbo_cam_desc.txt'
B64_FILE   = '/tmp/limbo_cam_b64.txt'


def capture_to(path):
    try:
        subprocess.run([
            'libcamera-still', '-o', path, '--nopreview',
            '-t', '400', '--gain', '8', '--width', '320', '--height', '240'
        ], capture_output=True, timeout=6)
        return True
    except Exception:
        pass
    try:
        subprocess.run(['fswebcam', '-r', '320x240', '--no-banner', path],
                       capture_output=True, timeout=6)
        return True
    except Exception:
        return False


def dither(path):
    try:
        img = Image.open(path).convert('L').rotate(-90, expand=True).resize((W, H), Image.LANCZOS)
        return img.convert('1', dither=Image.FLOYDSTEINBERG).convert('RGB')
    except Exception:
        return Image.new('RGB', (W, H), BLACK)


def process_in_background():
    try:
        with open(B64_FILE, 'w') as f:
            subprocess.run(['python3', '/home/llm/cam_dither.py', PHOTO_FILE],
                           stdout=f, stderr=subprocess.DEVNULL, timeout=30)
    except Exception:
        pass


def run(cycle_label):
    font = bdf.load(FONT_DIR + '4x6.bdf')

    framebuffer = np.zeros((H, W, 4), dtype=np.uint8)
    matrix = piomatter.PioMatter(
        colorspace=piomatter.Colorspace.RGB888,
        pinout=piomatter.Pinout.Active3,
        framebuffer=framebuffer,
        geometry=piomatter.Geometry(W, H, 5, rotation=piomatter.Orientation.R180),
    )

    # Shared state updated by camera thread
    current_frame = [Image.new('RGB', (W, H), BLACK)]
    capture_lock  = threading.Lock()

    def camera_loop():
        while True:
            if capture_to(LIVE_FILE):
                img = dither(LIVE_FILE)
                with capture_lock:
                    current_frame[0] = img
            time.sleep(2.0)

    t = threading.Thread(target=camera_loop, daemon=True)
    t.start()

    # --- Phase 1: live preview + countdown ---
    start = time.time()
    while True:
        now       = time.time()
        remaining = max(0, COUNTDOWN - (now - start))

        with capture_lock:
            img = current_frame[0].copy()
        d = ImageDraw.Draw(img)
        d.rectangle([(0, 0), (W - 1, HEADER_H - 1)], fill=WHITE)
        bdf.draw_text(d, font, (X, 1), cycle_label, fill=BLACK, spacing=2)
        cnt = str(int(remaining) + 1)
        cw  = bdf.textwidth(font, cnt)
        bdf.draw_text(d, font, (W - cw - X, 1), cnt, fill=BLACK)

        framebuffer[:] = np.array(img.convert('RGBA'))
        matrix.show()

        if remaining <= 0:
            break
        time.sleep(0.05)

    # --- Take final photo ---
    capture_to(PHOTO_FILE)
    still = dither(PHOTO_FILE)
    threading.Thread(target=process_in_background, daemon=True).start()
    open('/tmp/limbo_photo_ready', 'w').close()

    # --- Phase 2: still + blinking INFERENCE ---
    base = np.array(still)

    def make(show_inf):
        img = Image.fromarray(base.copy())
        d   = ImageDraw.Draw(img)
        d.rectangle([(0, 0), (W - 1, HEADER_H - 1)], fill=WHITE)
        bdf.draw_text(d, font, (X, 1), cycle_label, fill=BLACK, spacing=2)
        if show_inf:
            bdf.draw_text(d, font, (X, 8), 'INFERENCE', fill=BLACK)
        return np.array(img.convert('RGBA'))

    f_on  = make(True)
    f_off = make(False)
    blink = True

    while True:
        framebuffer[:] = f_on if blink else f_off
        matrix.show()
        blink = not blink
        time.sleep(0.6)


if __name__ == '__main__':
    cycle_label = sys.argv[1] if len(sys.argv) > 1 else ''
    run(cycle_label)
