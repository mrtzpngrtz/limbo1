#!/usr/bin/env python3
# Calls ollama API with an image + prompt, streams response to stdout
# Usage: python3 run_with_image.py <model> <image_path> <prompt>
#        or: echo "prompt" | python3 run_with_image.py <model> <image_path>

import sys
import json
import base64
import urllib.request

model      = sys.argv[1]
image_path = sys.argv[2]
prompt     = sys.argv[3] if len(sys.argv) > 3 else sys.stdin.read()

try:
    with open(image_path, 'rb') as f:
        image_b64 = base64.b64encode(f.read()).decode()
except Exception as e:
    sys.stderr.write(f"Image error: {e}\n")
    sys.exit(1)

payload = {
    "model": model,
    "prompt": prompt,
    "images": [image_b64],
    "stream": True,
    "think": True
}

req = urllib.request.Request(
    "http://localhost:11434/api/generate",
    data=json.dumps(payload).encode(),
    headers={"Content-Type": "application/json"}
)

try:
    with urllib.request.urlopen(req, timeout=600) as resp:
        for line in resp:
            chunk = json.loads(line.decode())
            sys.stdout.write(chunk.get("thinking", "") + chunk.get("response", ""))
            sys.stdout.flush()
            if chunk.get("done"):
                break
except Exception as e:
    sys.stderr.write(f"API error: {e}\n")
    sys.exit(1)
