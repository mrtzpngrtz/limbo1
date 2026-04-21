#!/usr/bin/env python3
# Converts camera image to B&W Floyd-Steinberg dithered PNG, outputs base64.
# Usage: python3 cam_dither.py <image_path>
import sys, base64, io
from PIL import Image

path = sys.argv[1]
img = Image.open(path).convert('L')
img = img.rotate(-90, expand=True)
img = img.resize((320, 240), Image.LANCZOS)
img = img.convert('1', dither=Image.FLOYDSTEINBERG)
buf = io.BytesIO()
img.save(buf, format='PNG', optimize=True)
print(base64.b64encode(buf.getvalue()).decode())
