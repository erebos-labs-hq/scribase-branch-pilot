#!/usr/bin/env python3
"""Builds the 1920x1080 demo video from real asciinema recordings of demo/run.sh.

    asciinema rec --cols 120 --rows 34 -c "demo/run.sh tools"  rec/tools.cast
    asciinema rec --cols 120 --rows 34 -c "demo/run.sh unsafe" rec/unsafe.cast
    asciinema rec --cols 120 --rows 34 -c "demo/run.sh safe y" rec/safe.cast
    python3 demo/make_video.py rec/ out.mp4

Terminal output is never edited, only re-timed: long API waits are shortened and short holds are added so
each step can be read. Requires agg, ffmpeg and Pillow.
"""
import json, os, subprocess, sys
from PIL import Image, ImageDraw, ImageFont

W, H, BAR = 1920, 1080, 130
SANS = '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf'
SANS_BOLD = '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf'
PROMPT = '\u001b[1;32m$'

# scene: (holds after an event containing a marker, captions starting at a marker)
SCENES = {
    'tools': (
        {'prompt   review-migration': 7.5},
        [(None, 'symfony/mcp-bundle server: 7 tools, 4 resources, 1 prompt (stdio and HTTP)')],
    ),
    'unsafe': (
        {');': 5.0, '[ERROR]': 6.0, 'Connected to': 1.5, 'lint_migration ->': 1.5},
        [(None, 'A migration that looks fine, but has no row level security'),
         ('Connected to', 'Linted against production through MCP: blocked before anything runs')],
    ),
    'safe': (
        {'Connected to': 1.5, '"status":"clean"': 2.5, 'branch_create ->': 2.5, 'apply_on_branch ->': 3.0,
         'bookmarks | on': 4.5, 'Approve? [y/N]': 4.5, '-> approved': 1.0, 'promote_branch ->': 4.0,
         'Promotion status': 3.5},
        [(None, 'The fixed migration: RLS plus an owner policy'),
         ('"status":"clean"', 'Lint is clean, so it is applied on a throwaway branch, never production'),
         ('Branch schema', 'The branch schema, read through an MCP resource'),
         ('human approval', 'Promotion gate: the MCP server asks a human through elicitation (y/N)'),
         ('-> approved', 'Only a human yes merges. The branch is discarded and a lesson is stored')],
    ),
}


def retime(path, holds):
    lines = open(path).read().splitlines()
    header, events = lines[0], [json.loads(l) for l in lines[1:] if l.strip()]
    out, marks, t, prev, seen_prompt = [], [], 0.0, 0.0, False
    expanded = []
    for ts, kind, data in events:  # reveal multi-line chunks line by line
        parts = data.split('\r\n')
        if len(parts) > 2 and PROMPT not in data:
            for j, part in enumerate(parts):
                chunk = part + ('\r\n' if j < len(parts) - 1 else '')
                if chunk:
                    expanded.append([ts, kind, chunk, 0.08])
        else:
            expanded.append([ts, kind, data, None])
    for ts, kind, data, step in expanded:
        d = ts - prev
        prev = ts
        if PROMPT in data:
            d = 0.3 if not seen_prompt else 1.0
            seen_prompt = True
        elif step is not None and d < step:
            d = step
        elif len(data) <= 2 and '\u001b' not in data:
            d = 0.04  # typing
        else:
            d = min(d, 0.45)  # API round trips
        t += d
        out.append([round(t, 3), kind, data])
        marks.append((t, data))
        for marker, hold in holds.items():
            if marker in data:
                t += hold
    last = out[-1][0]
    return header, out, marks, t + 0.5, t + 0.5 - last


def card(path, title, lines):
    im = Image.new('RGB', (W, H), (18, 20, 24))
    d = ImageDraw.Draw(im)
    ft, fl = ImageFont.truetype(SANS_BOLD, 76), ImageFont.truetype(SANS, 40)
    y = H // 2 - 60 - 30 * len(lines)
    d.text((W // 2, y), title, font=ft, fill=(240, 240, 240), anchor='mm')
    y += 120
    for line in lines:
        d.text((W // 2, y), line, font=fl, fill=(150, 200, 255), anchor='mm')
        y += 64
    im.save(path)


def run(cmd):
    subprocess.run(cmd, check=True)


def main(rec, out):
    work = os.path.join(rec, 'build')
    os.makedirs(work, exist_ok=True)
    parts = []
    card(f'{work}/title.png', 'Scribase Branch Pilot - Symfony AI + MCP',
         ['Agents rehearse Postgres migrations on throwaway branches.', 'Production changes only on a human yes.'])
    card(f'{work}/end.png', 'Scribase Branch Pilot',
         ['github.com/erebos-labs-hq/scribase-branch-pilot',
          'Symfony AI agent (pilot:review) + symfony/mcp-bundle + mcp/sdk', 'Erebos Labs - MIT'])
    for name, png, secs in (('title', 'title.png', 5), ('end', 'end.png', 6)):
        run(['ffmpeg', '-v', 'error', '-y', '-loop', '1', '-t', str(secs), '-i', f'{work}/{png}', '-vf',
             'fps=25,format=yuv420p,fade=in:0:12,fade=out:st=%s:d=0.5' % (secs - 0.5), '-c:v', 'libx264', f'{work}/{name}.mp4'])

    for scene, (holds, captions) in SCENES.items():
        header, events, marks, total, tail = retime(os.path.join(rec, f'{scene}.cast'), holds)
        cast = f'{work}/{scene}.cast'
        with open(cast, 'w') as f:
            f.write(header + '\n' + ''.join(json.dumps(e) + '\n' for e in events))
        run(['agg', '--quiet', '--font-family', 'DejaVu Sans Mono', '--font-size', '22', '--theme', 'monokai',
             '--idle-time-limit', '30', '--last-frame-duration', f'{tail:.2f}', cast, f'{work}/{scene}.gif'])
        # caption windows
        starts = []
        for marker, text in captions:
            at = 0.0 if marker is None else next(t for t, data in marks if marker in data)
            starts.append((at, text))
        filters = ['[0:v]fps=25,scale=-2:%d:flags=lanczos,pad=%d:%d:(ow-iw)/2:16:color=0x121418[v0]' % (H - BAR - 32, W, H)]
        last = 'v0'
        for i, (at, text) in enumerate(starts):
            end = starts[i + 1][0] if i + 1 < len(starts) else total + 1
            tf = f'{work}/{scene}_{i}.txt'
            open(tf, 'w').write(text)
            filters.append(f"[{last}]drawtext=fontfile={SANS}:textfile={tf}:fontsize=42:fontcolor=white:"
                           f"x=(w-text_w)/2:y=h-{BAR}+(({BAR}-text_h)/2):enable='between(t,{at:.2f},{end:.2f})'[v{i + 1}]")
            last = f'v{i + 1}'
        run(['ffmpeg', '-v', 'error', '-y', '-i', f'{work}/{scene}.gif', '-filter_complex', ';'.join(filters),
             '-map', f'[{last}]', '-pix_fmt', 'yuv420p', '-c:v', 'libx264', '-crf', '20', '-t', f'{total:.2f}', f'{work}/{scene}.mp4'])
        parts.append(scene)

    with open(f'{work}/list.txt', 'w') as f:
        for p in ['title', *parts, 'end']:
            f.write(f"file '{os.path.abspath(work)}/{p}.mp4'\n")
    run(['ffmpeg', '-v', 'error', '-y', '-f', 'concat', '-safe', '0', '-i', f'{work}/list.txt', '-f', 'lavfi', '-i',
         'anullsrc=r=48000:cl=stereo', '-shortest', '-c:v', 'libx264', '-crf', '20', '-pix_fmt', 'yuv420p', '-r', '25',
         '-c:a', 'aac', '-movflags', '+faststart', out])


if __name__ == '__main__':
    main(sys.argv[1], sys.argv[2])
