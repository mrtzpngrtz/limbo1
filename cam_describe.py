#!/usr/bin/env python3
# Camera scene description using YOLOv8s (onnxruntime, CPU).
# Falls back to Pillow brightness/color analysis if YOLO unavailable.
# Usage: python3 cam_describe.py <image_path>
import sys
import os
import numpy as np

ONNX_MODEL = "/home/llm/yolov8s.onnx"
CONF_THRESHOLD = 0.15
IOU_THRESHOLD  = 0.45
INPUT_SIZE     = 320   # smaller = faster on Pi

COCO_NAMES = [
    "person","bicycle","car","motorcycle","airplane","bus","train","truck","boat",
    "traffic light","fire hydrant","stop sign","parking meter","bench","bird","cat",
    "dog","horse","sheep","cow","elephant","bear","zebra","giraffe","backpack",
    "umbrella","handbag","tie","suitcase","frisbee","skis","snowboard","sports ball",
    "kite","baseball bat","baseball glove","skateboard","surfboard","tennis racket",
    "bottle","wine glass","cup","fork","knife","spoon","bowl","banana","apple",
    "sandwich","orange","broccoli","carrot","hot dog","pizza","donut","cake","chair",
    "couch","potted plant","bed","dining table","toilet","tv","laptop","mouse",
    "remote","keyboard","cell phone","microwave","oven","toaster","sink",
    "refrigerator","book","clock","vase","scissors","teddy bear","hair drier",
    "toothbrush"
]

def pillow_fallback(path):
    import colorsys
    from PIL import Image
    img = Image.open(path).convert('RGB').resize((64, 64))
    pixels = list(img.getdata())
    brightness = sum(0.299*r + 0.587*g + 0.114*b for r, g, b in pixels) / len(pixels)
    ar = sum(p[0] for p in pixels) // len(pixels)
    ag = sum(p[1] for p in pixels) // len(pixels)
    ab = sum(p[2] for p in pixels) // len(pixels)
    h, s, v = colorsys.rgb_to_hsv(ar/255, ag/255, ab/255)
    hue_deg = h * 360
    if s < 0.15:
        tone = 'grey' if v > 0.3 else 'dark'
    elif hue_deg < 30:   tone = 'red/orange'
    elif hue_deg < 75:   tone = 'yellow'
    elif hue_deg < 165:  tone = 'green'
    elif hue_deg < 260:  tone = 'blue'
    elif hue_deg < 330:  tone = 'purple/magenta'
    else:                tone = 'red'
    level = 'bright' if brightness > 180 else 'dim' if brightness > 80 else 'dark'
    return f"{level} {tone} light, no objects detected"

def preprocess(path):
    from PIL import Image
    img = Image.open(path).convert('RGB')
    w, h = img.size
    # letterbox to INPUT_SIZE x INPUT_SIZE
    scale = INPUT_SIZE / max(w, h)
    nw, nh = int(w * scale), int(h * scale)
    img = img.resize((nw, nh), Image.BILINEAR)
    canvas = np.full((INPUT_SIZE, INPUT_SIZE, 3), 114, dtype=np.uint8)
    pad_x = (INPUT_SIZE - nw) // 2
    pad_y = (INPUT_SIZE - nh) // 2
    canvas[pad_y:pad_y+nh, pad_x:pad_x+nw] = np.array(img)
    x = canvas.astype(np.float32) / 255.0
    x = x.transpose(2, 0, 1)[np.newaxis]   # (1, 3, H, W)
    return x, scale, pad_x, pad_y

def nms(boxes, scores, iou_thresh):
    x1, y1, x2, y2 = boxes[:,0], boxes[:,1], boxes[:,2], boxes[:,3]
    areas = (x2 - x1) * (y2 - y1)
    order = scores.argsort()[::-1]
    keep = []
    while order.size:
        i = order[0]
        keep.append(i)
        xx1 = np.maximum(x1[i], x1[order[1:]])
        yy1 = np.maximum(y1[i], y1[order[1:]])
        xx2 = np.minimum(x2[i], x2[order[1:]])
        yy2 = np.minimum(y2[i], y2[order[1:]])
        inter = np.maximum(0, xx2 - xx1) * np.maximum(0, yy2 - yy1)
        iou = inter / (areas[i] + areas[order[1:]] - inter + 1e-6)
        order = order[1:][iou <= iou_thresh]
    return keep

def yolo_describe(path):
    import onnxruntime as ort
    sess = ort.InferenceSession(ONNX_MODEL, providers=["CPUExecutionProvider"])
    inp, scale, pad_x, pad_y = preprocess(path)
    out = sess.run(None, {sess.get_inputs()[0].name: inp})[0]  # (1, 84, N)
    out = out[0].T   # (N, 84)
    cx, cy, bw, bh = out[:,0], out[:,1], out[:,2], out[:,3]
    class_scores = out[:, 4:]
    class_ids = class_scores.argmax(axis=1)
    confs = class_scores[np.arange(len(class_ids)), class_ids]
    mask = confs >= CONF_THRESHOLD
    if not mask.any():
        return None
    cx, cy, bw, bh = cx[mask], cy[mask], bw[mask], bh[mask]
    class_ids, confs = class_ids[mask], confs[mask]
    x1 = cx - bw/2; y1 = cy - bh/2; x2 = cx + bw/2; y2 = cy + bh/2
    boxes = np.stack([x1, y1, x2, y2], axis=1)
    keep = nms(boxes, confs, IOU_THRESHOLD)
    class_ids, confs = class_ids[keep], confs[keep]
    # Aggregate by class, keep max confidence per class
    seen = {}
    for cid, conf in zip(class_ids, confs):
        name = COCO_NAMES[cid] if cid < len(COCO_NAMES) else f"cls{cid}"
        if name not in seen or conf > seen[name]:
            seen[name] = float(conf)
    top = sorted(seen.items(), key=lambda x: -x[1])[:6]
    return "detected: " + ", ".join(f"{n} ({c:.2f})" for n, c in top)

path = sys.argv[1]
if not os.path.exists(ONNX_MODEL):
    print(pillow_fallback(path))
    sys.exit(0)
try:
    desc = yolo_describe(path)
    if desc:
        print(desc)
    else:
        print(pillow_fallback(path))
except Exception as e:
    sys.stderr.write(f"YOLO error: {e}\n")
    print(pillow_fallback(path))
