"""Minimal BDF bitmap font renderer for PIL."""


def load(path):
    glyphs = {}
    g = None
    in_bitmap = False
    with open(path) as f:
        for line in f:
            line = line.rstrip()
            if line.startswith('ENCODING'):
                g = {'rows': [], 'dwidth': 6, 'bbx': (6, 8, 0, 0)}
                glyphs[int(line.split()[1])] = g
            elif line.startswith('DWIDTH') and g is not None:
                g['dwidth'] = int(line.split()[1])
            elif line.startswith('BBX') and g is not None:
                p = line.split()
                g['bbx'] = (int(p[1]), int(p[2]), int(p[3]), int(p[4]))
            elif line == 'BITMAP':
                in_bitmap = True
            elif line == 'ENDCHAR':
                in_bitmap = False
                g = None
            elif in_bitmap and g is not None:
                g['rows'].append(int(line, 16))
    return glyphs


def textwidth(glyphs, text):
    return sum(glyphs.get(ord(c), glyphs.get(63, {})).get('dwidth', 6) for c in text)


def draw_text(draw, glyphs, pos, text, fill=(255, 255, 255), spacing=0):
    x, y = pos
    for ch in text:
        g = glyphs.get(ord(ch)) or glyphs.get(63)  # fallback to '?'
        if not g:
            x += 6 + spacing
            continue
        bw, bh, bx, by = g['bbx']
        for row_i, row_val in enumerate(g['rows']):
            py = y + row_i
            for bit in range(bw):
                if row_val & (0x80 >> bit):
                    draw.point((x + bx + bit, py), fill=fill)
        x += g['dwidth'] + spacing
