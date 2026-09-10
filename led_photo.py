#!/usr/bin/env python3
# Usage: python3 led_photo.py "#1234"
# Phase 1: live camera preview + countdown → takes photo
# Phase 2: still image + blinking INFERENCE (until killed)
# Writes: /tmp/limbo_cam.jpg (fresh photo), /tmp/limbo_photo_ready (flag for limbo.sh)
# The blog dither (cam_dither.py) is run by limbo.sh once the flag exists.

import os
import sys
import time
import shutil
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
READY_FILE = '/tmp/limbo_photo_ready'
LIVE_SIZE  = (320, 240)    # preview frames, dithered down to 64x64 anyway
PHOTO_SIZE = (1296, 972)   # final photo: native 2x2-binned mode of the ov5647, full field of view


def log(msg):
    sys.stderr.write(f'[led_photo] {msg}\n')
    sys.stderr.flush()


def capture_to(path, size=LIVE_SIZE):
    """Capture one frame to path. True only if a new file was really written."""
    w, h = size
    tmp = path + '.part'
    for cmd in (
        ['libcamera-still', '-o', tmp, '--nopreview', '-t', '400', '--gain', '8',
         '--width', str(w), '--height', str(h)],
        ['fswebcam', '-r', f'{w}x{h}', '--no-banner', tmp],
    ):
        try:
            os.remove(tmp)
        except FileNotFoundError:
            pass
        try:
            r = subprocess.run(cmd, capture_output=True, timeout=8)
        except Exception:
            continue
        if r.returncode == 0 and os.path.exists(tmp) and os.path.getsize(tmp) > 0:
            os.replace(tmp, path)   # atomic: readers never see a half-written file
            return True
    return False


def capture_fresh(path, attempts=3):
    for i in range(attempts):
        if capture_to(path, PHOTO_SIZE):
            return True
        log(f'capture attempt {i + 1} failed')
        time.sleep(0.7)
    return False


def dither(path):
    try:
        img = Image.open(path).convert('L').rotate(-90, expand=True).resize((W, H), Image.LANCZOS)
        return img.convert('1', dither=Image.FLOYDSTEINBERG).convert('RGB')
    except Exception:
        return Image.new('RGB', (W, H), BLACK)


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
    stop_live     = threading.Event()

    def camera_loop():
        while not stop_live.is_set():
            if capture_to(LIVE_FILE):
                img = dither(LIVE_FILE)
                with capture_lock:
                    current_frame[0] = img
            stop_live.wait(2.0)

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
    # Stop the live loop first. Two libcamera-still processes at the same time make
    # BOTH fail ("failed to allocate capture buffers"), and the old code did not check
    # the exit code, so the previous cycle's photo silently stayed in place.
    stop_live.set()
    t.join(timeout=12)

    if not capture_fresh(PHOTO_FILE):
        live_age = time.time() - os.path.getmtime(LIVE_FILE) if os.path.exists(LIVE_FILE) else 1e9
        if live_age < 30:
            shutil.copyfile(LIVE_FILE, PHOTO_FILE)
            log(f'WARNING: photo capture failed, using last live frame ({live_age:.0f}s old)')
        else:
            log('WARNING: photo capture failed and no recent live frame, previous photo stays')
    still = dither(PHOTO_FILE)
    open(READY_FILE, 'w').close()

    # --- Phase 2: still + blinking INFERENCE (camera stays off, CPU goes to inference) ---
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
