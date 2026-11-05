#!/usr/bin/env python3
"""DEV-ONLY: BaurPDF vs Chromium side-by-side scorecard (speed, memory, size, fidelity, CSS coverage).
Chromium is the visual reference and is NOT a dependency of the package.
    python3 tools/compare/scorecard.py [--chrome google-chrome] [--runs 3]
Writes tools/compare/out/scorecard-full.md, scorecard.json and side-by-side PNGs.
Requires: php, google-chrome, poppler-utils (pdftoppm/pdftotext/pdfinfo), qpdf, python3 numpy + Pillow."""
import argparse, json, os, re, statistics, subprocess, threading, time
import numpy as np
from PIL import Image

HERE = os.path.dirname(os.path.abspath(__file__))
OUT = os.path.join(HERE, 'out'); os.makedirs(OUT, exist_ok=True)
FEAT = os.path.join(OUT, 'features'); os.makedirs(FEAT, exist_ok=True)
ap = argparse.ArgumentParser(); ap.add_argument('--chrome', default='google-chrome'); ap.add_argument('--runs', type=int, default=3)
ap.add_argument('--feat', default=None, help='regex: only run matching CSS features (quick check, no report)')
ap.add_argument('--docs', default=None, help='regex: only run matching documents (quick check, no features, no report)')
A = ap.parse_args()

def tree_rss(pid):
    """Summed RSS (KB) of a process and all its descendants, read from /proc."""
    kids = {}
    for d in os.listdir('/proc'):
        if not d.isdigit(): continue
        try:
            with open(f'/proc/{d}/stat') as f: st = f.read()
            ppid = int(st[st.rfind(')') + 2:].split()[1]); kids.setdefault(ppid, []).append(int(d))
        except Exception: pass
    tot, pss, stack = 0, 0, [pid]
    while stack:
        p = stack.pop(); stack += kids.get(p, [])
        try:
            with open(f'/proc/{p}/status') as f:
                for line in f:
                    if line.startswith('VmRSS:'): tot += int(line.split()[1])
            with open(f'/proc/{p}/smaps_rollup') as f:
                for line in f:
                    if line.startswith('Pss:'): pss += int(line.split()[1])
        except Exception: pass
    return tot, pss

PSS = []
def run(cmd):
    """Run a command; return (wall ms, peak tree RSS MB, stdout)."""
    t = time.perf_counter(); p = subprocess.Popen(cmd, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL)
    peak = [0, 0]; done = threading.Event()
    def poll():
        while not done.is_set():
            r, q = tree_rss(p.pid); peak[0] = max(peak[0], r); peak[1] = max(peak[1], q); time.sleep(0.01)
    th = threading.Thread(target=poll); th.start()
    out = p.communicate()[0]; done.set(); th.join()
    PSS.append(peak[1] / 1024)
    return (time.perf_counter() - t) * 1000, peak[0] / 1024, out.decode('utf-8', 'replace')

def render_baur(html, pdf, theme=False):
    return run(['php', os.path.join(HERE, 'render.php'), html, pdf] + (['--theme'] if theme else []))

def render_chrome(html, pdf):
    return run([A.chrome, '--headless', '--no-sandbox', '--disable-gpu', '--no-pdf-header-footer',
                f'--print-to-pdf={pdf}', 'file://' + html])

def sh(cmd): return subprocess.run(cmd, capture_output=True, text=True).stdout
def pages(pdf):
    m = re.search(r'Pages:\s+(\d+)', sh(['pdfinfo', pdf])); return int(m.group(1)) if m else 0
def qpdf_ok(pdf): return subprocess.run(['qpdf', '--check', pdf], capture_output=True).returncode in (0, 3)
def words(pdf): return re.findall(r'\w+', sh(['pdftotext', pdf, '-']).lower())

def raster(pdf, page, dpi, tag):
    base = os.path.join(OUT, tag)
    subprocess.run(['pdftoppm', '-r', str(dpi), '-gray', '-png', '-f', str(page), '-l', str(page), '-singlefile', pdf, base], capture_output=True)
    return np.asarray(Image.open(base + '.png').convert('L'), dtype=np.uint8) if os.path.exists(base + '.png') else None

def dilate(m, r):
    out = m.copy()
    for dy in range(-r, r + 1):
        for dx in range(-r, r + 1):
            out |= np.roll(np.roll(m, dy, 0), dx, 1)
    return out

def ink_f1(a, b, r=2):
    """F1 of 'ink' pixels with an r-pixel tolerance: 1.0 = same marks in the same places."""
    h, w = max(a.shape[0], b.shape[0]), max(a.shape[1], b.shape[1])
    pad = lambda x: np.pad(x, ((0, h - x.shape[0]), (0, w - x.shape[1])), constant_values=255)
    A_, B_ = pad(a) < 235, pad(b) < 235
    if not A_.any() and not B_.any(): return 1.0
    if not A_.any() or not B_.any(): return 0.0
    p = (A_ & dilate(B_, r)).sum() / A_.sum(); rc = (B_ & dilate(A_, r)).sum() / B_.sum()
    return float(2 * p * rc / (p + rc)) if p + rc else 0.0

def side_by_side(a, b, path):
    h = max(a.shape[0], b.shape[0]); w = a.shape[1] + b.shape[1] + 10
    c = np.full((h, w), 200, np.uint8); c[:a.shape[0], :a.shape[1]] = a; c[:b.shape[0], a.shape[1] + 10:] = b
    Image.fromarray(c).save(path)

# ---------------- documents ----------------
docs = sorted(f[:-5] for f in os.listdir(os.path.join(HERE, 'docs')) if f.endswith('.html'))
res = {'docs': {}, 'features': {}}
if A.docs: docs = [d for d in docs if re.search(A.docs, d)]
for d in (docs if A.feat is None else []):
    html = os.path.join(HERE, 'docs', d + '.html')
    r = {}
    for eng, fn in (('BaurPDF', render_baur), ('Chromium', render_chrome)):
        pdf = os.path.join(OUT, f'{d}-{eng.lower()}.pdf'); walls, mems, psss, inproc = [], [], [], []
        for _ in range(A.runs):
            if os.path.exists(pdf): os.remove(pdf)
            ms, mb, out = fn(html, pdf); walls.append(ms); mems.append(mb); psss.append(PSS[-1])
            if eng == 'BaurPDF':
                try: inproc.append(json.loads(out.strip().splitlines()[-1])['ms'])
                except Exception: pass
        r[eng] = {'wall_ms': round(statistics.median(walls)), 'inproc_ms': round(statistics.median(inproc)) if inproc else None,
                  'rss_mb': round(statistics.median(mems), 1), 'pss_mb': round(statistics.median(psss), 1), 'kb': round(os.path.getsize(pdf) / 1024, 1) if os.path.exists(pdf) else None,
                  'pages': pages(pdf), 'valid': qpdf_ok(pdf), 'pdf': pdf}
    wb, wc = words(r['BaurPDF']['pdf']), words(r['Chromium']['pdf'])
    sb, sc = set(wb), set(wc)
    r['text_jaccard'] = round(len(sb & sc) / max(1, len(sb | sc)), 3)
    f1s = []
    for pg in range(1, min(3, r['BaurPDF']['pages'], r['Chromium']['pages']) + 1):
        a = raster(r['BaurPDF']['pdf'], pg, 40, f'{d}-b{pg}'); b = raster(r['Chromium']['pdf'], pg, 40, f'{d}-c{pg}')
        if a is None or b is None: continue
        f1s.append(ink_f1(a, b))
        if pg == 1: side_by_side(raster(r['BaurPDF']['pdf'], 1, 60, f'{d}-B'), raster(r['Chromium']['pdf'], 1, 60, f'{d}-C'), os.path.join(OUT, f'{d}-side-by-side.png'))
    r['visual_f1'] = round(statistics.mean(f1s), 3) if f1s else 0.0
    res['docs'][d] = r
    print(d, json.dumps({k: v for k, v in r.items()}, ensure_ascii=False, default=str), flush=True)

# ---------------- CSS coverage (per-feature visual agreement with Chromium) ----------------
WRAP = ('<!DOCTYPE html><html lang="ru"><head><meta charset="utf-8"><style>@page{size:A5;margin:10mm}'
        'body{font-family:"PT Sans";font-size:11pt;line-height:1.3;margin:0;color:#000}.b{background:#93c5fd;padding:6pt}%s</style></head><body>%s</body></html>')
T = 'Съешь же ещё этих мягких французских булок, да выпей чаю.'
FEATURES = {
  'margin/padding/border': ('div{margin:10pt 20pt;padding:8pt;border:3pt solid #1d4ed8}', f'<div>{T}</div>'),
  'border-radius': ('div{border-radius:14pt;background:#2563eb;height:60pt;width:160pt}', '<div></div>'),
  'border-style dashed/dotted/double': ('div{border:3pt dashed #000;height:30pt;margin:6pt}', '<div></div><div style="border-style:dotted"></div><div style="border-style:double;border-width:5pt"></div>'),
  'linear-gradient': ('div{height:60pt;background:linear-gradient(90deg,#ef4444,#3b82f6)}', '<div></div>'),
  'radial-gradient': ('div{height:90pt;width:180pt;background:radial-gradient(circle,#facc15,#7c3aed)}', '<div></div>'),
  'box-shadow': ('div{margin:20pt;height:50pt;width:150pt;background:#fff;box-shadow:4pt 4pt 0 #111}', '<div></div>'),
  'opacity/rgba': ('div{height:50pt;background:rgba(220,38,38,.5)} p{opacity:.4}', f'<div></div><p>{T}</p>'),
  'text-align/indent': ('p{text-align:center} .j{text-align:justify;text-indent:20pt}', f'<p>{T}</p><p class=j>{T} {T} {T}</p>'),
  'font-weight/style/size': ('', f'<p style="font-weight:bold">{T}</p><p style="font-style:italic">{T}</p><p style="font-size:16pt">{T}</p>'),
  'text-decoration': ('', f'<p style="text-decoration:underline">{T}</p><p style="text-decoration:line-through">{T}</p>'),
  'text-transform/letter-spacing': ('', f'<p style="text-transform:uppercase">{T}</p><p style="letter-spacing:2pt">{T}</p>'),
  'line-height': ('p{line-height:2}', f'<p>{T} {T} {T}</p>'),
  'white-space: pre': ('pre{font-family:"PT Sans"}', '<pre>a    b\n    c\n\td</pre>'),
  'ul/ol lists': ('', '<ul><li>один</li><li>два</li></ul><ol><li>три</li><li>четыре</li></ol>'),
  'list-style-type': ('', '<ol style="list-style-type:upper-roman"><li>a</li><li>b</li><li>c</li></ol><ul style="list-style-type:square"><li>x</li></ul>'),
  'table basic': ('table{border-collapse:collapse}td{border:1pt solid #000;padding:4pt}', '<table><tr><td>a</td><td>bb</td></tr><tr><td>ccc</td><td>d</td></tr></table>'),
  'table colspan/rowspan': ('table{border-collapse:collapse;width:100%}td{border:1pt solid #000;padding:4pt}', '<table><tr><td colspan=2>a</td><td rowspan=2>b</td></tr><tr><td>c</td><td>d</td></tr></table>'),
  'float': ('.f{float:left;width:80pt;height:60pt;background:#f59e0b;margin-right:8pt}', f'<div class=f></div><p>{T} {T} {T} {T}</p>'),
  'position: absolute': ('.r{position:relative;height:100pt;background:#e5e7eb}.a{position:absolute;right:10pt;bottom:10pt;width:50pt;height:30pt;background:#000}', '<div class=r><div class=a></div></div>'),
  'position: relative offset': ('.x{position:relative;left:30pt;top:10pt;background:#000;height:20pt;width:60pt}', '<div class=x></div>'),
  'display: inline-block': ('span{display:inline-block;width:60pt;height:30pt;background:#16a34a;margin:3pt}', '<span></span><span></span><span></span>'),
  'flex row + gap': ('.f{display:flex;gap:10pt}.f div{flex:1;height:40pt;background:#2563eb}', '<div class=f><div></div><div></div><div></div></div>'),
  'flex justify/align': ('.f{display:flex;justify-content:space-between;align-items:center;height:80pt;background:#eee}.f div{width:40pt;height:20pt;background:#000}', '<div class=f><div></div><div style="height:50pt"></div><div></div></div>'),
  'flex wrap/grow': ('.f{display:flex;flex-wrap:wrap;gap:6pt}.f div{flex:1 1 120pt;height:30pt;background:#9333ea}', '<div class=f>' + '<div></div>' * 5 + '</div>'),
  'flex column': ('.f{display:flex;flex-direction:column;gap:6pt}.f div{height:20pt;background:#000}', '<div class=f><div></div><div></div></div>'),
  'grid fr/gap': ('.g{display:grid;grid-template-columns:1fr 2fr 1fr;gap:6pt}.g div{height:30pt;background:#0891b2}', '<div class=g>' + '<div></div>' * 6 + '</div>'),
  'grid areas': ('.g{display:grid;grid-template-areas:"h h" "s m";grid-template-columns:80pt 1fr;gap:4pt}.g div{background:#0891b2;height:30pt}', '<div class=g><div style="grid-area:h"></div><div style="grid-area:s;height:60pt"></div><div style="grid-area:m"></div></div>'),
  'grid span/dense': ('.g{display:grid;grid-template-columns:repeat(3,1fr);gap:4pt;grid-auto-flow:dense}.g div{background:#0891b2;height:25pt}', '<div class=g><div style="grid-column:span 2"></div><div style="grid-column:span 2"></div><div></div><div style="grid-row:span 2;height:auto"></div><div></div></div>'),
  'grid auto-fill minmax': ('.g{display:grid;grid-template-columns:repeat(auto-fill,minmax(70pt,1fr));gap:4pt}.g div{background:#0891b2;height:25pt}', '<div class=g>' + '<div></div>' * 7 + '</div>'),
  'multi-column': ('.c{columns:2;column-gap:14pt;column-rule:1pt solid #000}', f'<div class=c><p>{T*4}</p><p>{T*3}</p></div>'),
  'column-span': ('.c{columns:2;column-gap:14pt}.c h3{column-span:all;background:#000;color:#fff}', f'<div class=c><p>{T*2}</p><h3>Заголовок</h3><p>{T*2}</p></div>'),
  'transform rotate': ('div{width:120pt;height:40pt;background:#dc2626;margin:40pt;transform:rotate(25deg)}', '<div></div>'),
  'transform scale/translate': ('div{width:120pt;height:40pt;background:#dc2626;margin:20pt;transform:translate(30pt,10pt) scale(1.3)}', '<div></div>'),
  'transform-origin + skew': ('div{width:120pt;height:40pt;background:#dc2626;margin:30pt;transform-origin:0 0;transform:skewX(-20deg)}', '<div></div>'),
  '::before/::after content': ('p::before{content:"→ ";color:#dc2626}p::after{content:" ←"}', f'<p>{T}</p>'),
  'counters': ('body{counter-reset:c}h4{counter-increment:c;margin:4pt 0}h4::before{content:"Глава " counter(c, upper-roman) ". "}', '<h4>Один</h4><h4>Два</h4><h4>Три</h4>'),
  'attr() + quotes': ('a::after{content:" (" attr(href) ")"}q{quotes:"«" "»" "„" "“"}', '<p><a href="https://e.kz">ссылка</a> <q>внешняя <q>внутренняя</q></q></p>'),
  'selectors :nth-child/:not/+/~': ('li:nth-child(odd){background:#fde68a}li:not(.x){font-weight:bold}h5+p{color:#dc2626}h5~div{border:1pt solid #000}', '<ul><li>1</li><li class=x>2</li><li>3</li></ul><h5>h</h5><p>после</p><div>sib</div>'),
  'attribute selectors': ('[data-k]{background:#bbf7d0}[href^=https]{color:#dc2626}[class~=b2]{border:2pt solid #000}', '<p data-k=1>a</p><p><a href="https://x">b</a></p><p class="a b2">c</p>'),
  'var() + calc()': (':root{--c:#7c3aed;--w:100pt}div{background:var(--c);width:calc(var(--w) * 2 - 20pt);height:30pt}', '<div></div>'),
  'em/rem/% units': ('html{font-size:12pt}.e{width:50%;height:3em;background:#000}.r{width:10rem;height:2rem;background:#555;margin-top:4pt}', '<div class=e></div><div class=r></div>'),
  'hsl()/named colors': ('', '<div style="height:20pt;background:hsl(200,80%,50%)"></div><div style="height:20pt;background:tomato"></div>'),
  'max-width/min-height': ('div{max-width:150pt;min-height:60pt;background:#000}', '<div></div>'),
  'overflow hidden': ('div{width:100pt;height:30pt;overflow:hidden;background:#e5e7eb}', f'<div>{T*3}</div>'),
  'vertical-align sup/sub': ('', '<p>E = mc<sup>2</sup>, H<sub>2</sub>O</p>'),
  'img (PNG data URI)': ('img{width:80pt;height:80pt}', '<img src="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAAFklEQVR4nGP4z8DAwMDAxMDAwMDAAAANHQEDasKb6QAAAABJRU5ErkJggg==">'),
  'inline SVG': ('', '<svg width="120" height="80" viewBox="0 0 120 80"><rect x="5" y="5" width="60" height="40" fill="#2563eb"/><circle cx="90" cy="50" r="25" fill="#dc2626"/><path d="M5 75 L115 60" stroke="#000" stroke-width="3"/></svg>'),
  'form controls': ('input,select{width:150pt}', '<p>Имя: <input value="Бауржан"></p><p><input type=checkbox checked> да</p><p><select><option>Алматы</option></select></p>'),
  'break-before: page': ('.p{break-before:page}', '<p>стр 1</p><p class=p>стр 2</p>'),
  'hyphens:auto (ru)': ('p{width:120pt;hyphens:auto;text-align:justify}', '<p lang="ru">Электрификация промышленности государственного регулирования экономики.</p>'),
}
passed = 0
for name, (css, body) in FEATURES.items():
    if A.docs or (A.feat and not re.search(A.feat, name)): continue
    slug = re.sub(r'[^a-z0-9]+', '-', name.lower()).strip('-')
    html = os.path.join(FEAT, slug + '.html')
    with open(html, 'w') as f: f.write(WRAP % (css, body))
    pb, pc = os.path.join(FEAT, slug + '-b.pdf'), os.path.join(FEAT, slug + '-c.pdf')
    render_baur(html, pb); render_chrome(html, pc)
    a, b = raster(pb, 1, 40, f'features/{slug}-b'), raster(pc, 1, 40, f'features/{slug}-c')
    f1 = ink_f1(a, b) if a is not None and b is not None else 0.0
    ok = f1 >= 0.80 and pages(pb) == pages(pc)
    passed += ok
    res['features'][name] = {'f1': round(f1, 3), 'pages': [pages(pb), pages(pc)], 'pass': bool(ok)}
    print(f'{name:36s} F1={f1:.3f} {"PASS" if ok else "diff"}', flush=True)

if A.feat or A.docs: raise SystemExit(0)
# ---------------- report ----------------
def ratio(a, b): return f'{b / a:.1f}×' if a and b else '—'
docs_r = res['docs']
md = ['# BaurPDF vs Chromium: side-by-side scorecard', '',
      f'Chromium {sh([A.chrome, "--version"]).strip()} (headless `--print-to-pdf`) vs BaurPDF; median of {A.runs} runs per document. Both engines use the same PT Sans font and the same HTML, and BaurPDF runs with `theme(false)`.', '',
      '## Per document', '', '| Document | Engine | Wall, ms (cold process) | In-process, ms | Peak RSS, MB | Peak PSS, MB | Size, KB | Pages | qpdf | Text Jaccard | Visual F1 |', '|---|---|---:|---:|---:|---:|---:|---:|---|---:|---:|']
for d, r in docs_r.items():
    for eng in ('BaurPDF', 'Chromium'):
        e = r[eng]
        md.append(f"| {d} | {eng} | {e['wall_ms']} | {e['inproc_ms'] if e['inproc_ms'] is not None else '—'} | {e['rss_mb']} | {e['pss_mb']} | {e['kb']} | {e['pages']} | {'ok' if e['valid'] else 'FAIL'} | {r['text_jaccard'] if eng == 'BaurPDF' else '1 (ref)'} | {r['visual_f1'] if eng == 'BaurPDF' else '1 (ref)'} |")
gm = lambda xs: float(np.exp(np.mean(np.log([max(x, 1e-9) for x in xs]))))
sp = gm([r['Chromium']['wall_ms'] / r['BaurPDF']['wall_ms'] for r in docs_r.values()])
spi = gm([r['Chromium']['wall_ms'] / max(1, r['BaurPDF']['inproc_ms'] or r['BaurPDF']['wall_ms']) for r in docs_r.values()])
mem = gm([r['Chromium']['rss_mb'] / r['BaurPDF']['rss_mb'] for r in docs_r.values()])
memp = gm([r['Chromium']['pss_mb'] / r['BaurPDF']['pss_mb'] for r in docs_r.values() if r['BaurPDF']['pss_mb']])
size = gm([r['Chromium']['kb'] / r['BaurPDF']['kb'] for r in docs_r.values()])
size_wins = sum(r['BaurPDF']['kb'] < r['Chromium']['kb'] for r in docs_r.values())
fid = statistics.mean(r['visual_f1'] for r in docs_r.values()); txt = statistics.mean(r['text_jaccard'] for r in docs_r.values())
pg = sum(r['BaurPDF']['pages'] == r['Chromium']['pages'] for r in docs_r.values())
cov = passed / len(FEATURES)
res['summary'] = {'speed_x_cold': round(sp, 2), 'speed_x_inproc': round(spi, 2), 'memory_x': round(mem, 2), 'size_x': round(size, 2), 'size_wins': size_wins,
                  'visual_f1': round(fid, 3), 'text_jaccard': round(txt, 3), 'pages_equal': pg, 'docs': len(docs_r), 'css_pass': passed, 'css_total': len(FEATURES)}
md += ['', '## Scorecard per axis', '', '| Axis | Metric | BaurPDF | Chromium | Verdict |', '|---|---|---:|---:|---|',
       f'| Speed | geo-mean wall time ratio, cold process | **{sp:.1f}× faster** | 1× | {"BaurPDF" if sp > 1 else "Chromium"} |',
       f'| Speed (embedded) | geo-mean ratio, in-process call vs Chromium process | **{spi:.1f}× faster** | 1× | {"BaurPDF" if spi > 1 else "Chromium"} |',
       f'| Memory | geo-mean peak RSS ratio (Chromium process tree) | **{mem:.1f}× less** | 1× | {"BaurPDF" if mem > 1 else "Chromium"} |',
       f'| Memory (PSS) | geo-mean peak PSS ratio (shared pages split across processes) | **{memp:.1f}× less** | 1× | {"BaurPDF" if memp > 1 else "Chromium"} |',
       f'| Size | geo-mean file size ratio | {size:.2f}× {"smaller" if size > 1 else "larger"} ({size_wins}/{len(docs_r)} docs smaller) | 1× | {"BaurPDF" if size > 1 else "Chromium"} |',
       f'| Fidelity | mean visual ink-F1 vs Chromium (40 dpi, 2 px tolerance, ≤3 pages) | {fid:.3f} | 1 (reference) | reference = Chromium |',
       f'| Fidelity (text) | mean word-set Jaccard; documents with the same page count | {txt:.3f}; {pg}/{len(docs_r)} | 1 (reference) | reference = Chromium |',
       f'| CSS coverage | features visually matching Chromium (F1 ≥ 0.80 and same pages) | {passed}/{len(FEATURES)} ({cov:.0%}) | {len(FEATURES)}/{len(FEATURES)} (reference) | reference = Chromium |',
       '', 'Fidelity and coverage use Chromium as the reference, so Chromium scores 1 by definition. They show how close BaurPDF comes to Chromium, not which engine is "better".', '',
       '## CSS coverage detail', '', '| Feature | Ink F1 | Pages (B/C) | Result |', '|---|---:|---|---|']
for n, f in res['features'].items(): md.append(f"| {n} | {f['f1']} | {f['pages'][0]}/{f['pages'][1]} | {'match' if f['pass'] else 'differs'} |")
with open(os.path.join(OUT, 'scorecard-full.md'), 'w') as f: f.write('\n'.join(md) + '\n')
with open(os.path.join(OUT, 'scorecard.json'), 'w') as f: json.dump(res, f, ensure_ascii=False, indent=1, default=str)
print('\n'.join(md))
