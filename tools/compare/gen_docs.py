#!/usr/bin/env python3
"""Generates the real-world comparison documents in docs/ (deterministic)."""
import os, random
HERE = os.path.dirname(os.path.abspath(__file__))
R = random.Random(42)
W = 'договор поставка услуга клиент отчёт период выручка анализ сумма показатель региональный эффективность Алматы Астана'.split()
def words(n): return ' '.join(R.choice(W) for _ in range(n))
def page(title, css, body, size='A4', margin='15mm', lang='ru', font="'DejaVu Sans',sans-serif"):
    return (f'<!DOCTYPE html><html lang="{lang}"><head><meta charset="utf-8"><title>{title}</title><style>@page{{size:{size};margin:{margin}}}'
            f'body{{font-family:{font};font-size:10pt;color:#1f2937;margin:0}}{css}</style></head><body>{body}</body></html>')
D = {}
# Bootstrap-like landing: navbar, hero, card grid
cards = ''.join(f'<div class="card"><div class="ch">Тариф {i}</div><div class="cb"><p>{words(14)}</p><a class="btn">Подробнее</a></div></div>' for i in range(1, 7))
D['rw-bootstrap'] = page('Bootstrap', '.nav{display:flex;justify-content:space-between;align-items:center;background:#0d6efd;color:#fff;padding:8pt 12pt}.nav a{color:#fff;margin-left:10pt;text-decoration:none}'
 '.hero{padding:24pt 12pt;background:#f8f9fa;border-bottom:1px solid #dee2e6}.hero h1{margin:0 0 6pt;font-size:24pt}.row{display:flex;flex-wrap:wrap;gap:10pt;padding:12pt}'
 '.card{flex:1 1 30%;border:1px solid #dee2e6;border-radius:6pt}.ch{background:#f1f3f5;padding:6pt 8pt;font-weight:bold;border-bottom:1px solid #dee2e6}.cb{padding:8pt}'
 '.btn{display:inline-block;background:#0d6efd;color:#fff;padding:3pt 8pt;border-radius:4pt}',
 f'<div class="nav"><b>BaurSoft</b><span><a>Главная</a><a>Тарифы</a><a>Контакты</a></span></div><div class="hero"><h1>Платформа отчётов</h1><p>{words(30)}</p></div><div class="row">{cards}</div>')
# Pricing table
rows = ''.join(f'<tr><td>{R.choice(W).capitalize()}</td><td>{"✓" if R.random()>.3 else "—"}</td><td>{"✓" if R.random()>.2 else "—"}</td><td>✓</td></tr>' for _ in range(14))
D['rw-pricing'] = page('Pricing', 'table{border-collapse:collapse;width:100%}th,td{border:1px solid #d1d5db;padding:5pt 8pt;text-align:center}td:first-child{text-align:left}thead th{background:#111827;color:#fff}tbody tr:nth-child(odd){background:#f9fafb}h1{font-size:18pt}',
 f'<h1>Сравнение тарифов</h1><table><thead><tr><th>Функция</th><th>Старт</th><th>Бизнес</th><th>Корпорация</th></tr></thead><tbody>{rows}</tbody></table>')
# Long contract
sec = ''.join(f'<h3>{i}. {words(3).capitalize()}</h3>' + ''.join(f'<p>{i}.{k}. {words(R.randint(30, 70))}.</p>' for k in range(1, R.randint(3, 6))) for i in range(1, 16))
D['rw-contract'] = page('Contract', 'body{font-family:"PT Sans";font-size:10.5pt;line-height:1.4}h1{text-align:center;font-size:14pt}h3{font-size:11pt;margin:10pt 0 4pt}p{margin:0 0 5pt;text-align:justify;text-indent:16pt}.sig{display:flex;justify-content:space-between;margin-top:24pt}.sig div{width:45%;border-top:1px solid #000;padding-top:4pt}',
 f'<h1>ДОГОВОР ПОСТАВКИ № 17/2026</h1><p style="text-indent:0">г. Алматы, 05.11.2026</p>{sec}<div class="sig"><div>Поставщик</div><div>Покупатель</div></div>', font='"PT Sans"')
# Dashboard KPI grid
kpis = ''.join(f'<div class="k"><div class="l">{R.choice(W)}</div><div class="v">{R.randint(10, 999)} {R.choice(["млн ₸", "%", "шт"])}</div><div class="d {R.choice(["up", "dn"])}">{R.randint(1, 30)}%</div></div>' for _ in range(8))
bars = ''.join(f'<div class="b"><span style="width:{R.randint(10, 100)}%"></span><em>{R.choice(W)}</em></div>' for _ in range(10))
D['rw-dashboard'] = page('Dashboard', '.g{display:grid;grid-template-columns:repeat(4,1fr);gap:8pt}.k{border:1px solid #e5e7eb;border-radius:6pt;padding:8pt}.l{color:#6b7280;font-size:8pt}.v{font-size:16pt;font-weight:bold}.up{color:#059669}.dn{color:#dc2626}'
 '.b{position:relative;height:14pt;margin:4pt 0;background:#f3f4f6}.b span{display:block;height:100%;background:#93c5fd}.b em{position:absolute;left:4pt;top:1pt;font-style:normal;font-size:8pt}h2{font-size:13pt}',
 f'<h2>Панель показателей</h2><div class="g">{kpis}</div><h2>Регионы</h2>{bars}', size='A4 landscape')
# CV
D['rw-cv'] = page('CV', '.wrap{display:flex}.side{width:150pt;background:#1e293b;color:#e2e8f0;padding:14pt}.main{flex:1;padding:14pt}h1{margin:0;font-size:20pt}h2{font-size:11pt;border-bottom:2px solid #0ea5e9;padding-bottom:2pt;text-transform:uppercase;letter-spacing:1pt}ul{padding-left:12pt}',
 '<div class="wrap"><div class="side"><h2>Контакты</h2><p>+7 701 000 00 00<br>mail@example.kz<br>Алматы</p><h2>Навыки</h2><ul>' + ''.join(f'<li>{R.choice(W)}</li>' for _ in range(8)) + '</ul></div><div class="main"><h1>Бауржан Атинов</h1><p>Ведущий разработчик</p>'
 + ''.join(f'<h2>{y}</h2><p><b>{words(3)}</b></p><p>{words(40)}</p>' for y in range(2026, 2019, -2)) + '</div></div>', margin='0')
# Business letter
D['rw-letter'] = page('Letter', '.head{display:flex;justify-content:space-between;border-bottom:3pt solid #b91c1c;padding-bottom:6pt}.to{margin:20pt 0 20pt 55%}p{text-align:justify;line-height:1.5}',
 f'<div class="head"><b style="font-size:16pt;color:#b91c1c">BaurSoft</b><span>Исх. № 112<br>05.11.2026</span></div><div class="to">Директору<br>ТОО «Контрагент»<br>г-ну Иванову И.И.</div><p>Уважаемый Иван Иванович!</p>' + ''.join(f'<p>{words(55)}.</p>' for _ in range(4)) + '<p>С уважением,<br><b>Директор</b> ____________</p>')
# Narrow receipt
items = ''.join(f'<tr><td>{R.choice(W)}</td><td class="r">{R.randint(1, 9)}×{R.randint(100, 9000)}</td></tr>' for _ in range(12))
D['rw-receipt'] = page('Receipt', 'body{font-family:"DejaVu Sans Mono",monospace;font-size:8pt}table{width:100%;border-collapse:collapse}.r{text-align:right}hr{border:0;border-top:1px dashed #000}.c{text-align:center}',
 f'<div class="c"><b>ТОО «BaurSoft»</b><br>БИН 123456789012<br>Касса 01</div><hr><table>{items}</table><hr><table><tr><td><b>ИТОГО</b></td><td class="r"><b>48 600</b></td></tr></table><p class="c">Спасибо за покупку!</p>', size='80mm 200mm', margin='4mm', font='"DejaVu Sans Mono",monospace')
# Timetable with rowspan
tt = '<tr><th>Время</th>' + ''.join(f'<th>{d}</th>' for d in ['Пн', 'Вт', 'Ср', 'Чт', 'Пт']) + '</tr>'
for h in range(9, 17):
    tt += f'<tr><td>{h}:00</td>' + ''.join(f'<td rowspan="2" class="ev">{R.choice(W)}</td>' if (h + d) % 4 == 0 and h < 16 else ('' if (h - 1 + d) % 4 == 0 and h > 9 else '<td></td>') for d in range(5)) + '</tr>'
D['rw-timetable'] = page('Timetable', 'table{border-collapse:collapse;width:100%;table-layout:fixed}th,td{border:1px solid #9ca3af;height:28pt;padding:2pt;text-align:center;vertical-align:middle}th{background:#e5e7eb}.ev{background:#dbeafe}', f'<h2>Расписание</h2><table>{tt}</table>')
# Newsletter two columns with floats
D['rw-newsletter'] = page('Newsletter', 'h1{font-family:"PT Serif",serif;font-size:28pt;text-align:center;border-bottom:4px double #000;margin:0 0 8pt}.cols{columns:2;column-gap:16pt}p{margin:0 0 6pt;text-align:justify}.box{float:right;width:90pt;height:70pt;background:#fde68a;margin:0 0 6pt 8pt;padding:4pt}h3{margin:6pt 0 3pt}',
 '<h1>Вестник BaurSoft</h1><div class="cols">' + ''.join(f'<h3>{words(3).capitalize()}</h3>' + (f'<div class="box">{words(8)}</div>' if i % 2 == 0 else '') + ''.join(f'<p>{words(45)}</p>' for _ in range(2)) for i in range(5)) + '</div>')
# Catalogue with inline SVG
prods = ''.join(f'<div class="p"><svg width="80" height="60"><rect width="80" height="60" fill="hsl({i*37 % 360},70%,60%)"/><circle cx="40" cy="30" r="18" fill="#fff"/></svg><b>{words(2)}</b><div class="pr">{R.randint(1, 99)} 990 ₸</div></div>' for i in range(12))
D['rw-catalogue'] = page('Catalogue', '.g{display:flex;flex-wrap:wrap;gap:10pt}.p{width:115pt;border:1px solid #e5e7eb;padding:6pt;text-align:center}.p b{display:block;margin-top:4pt}.pr{color:#b91c1c;font-weight:bold}', f'<h2>Каталог</h2><div class="g">{prods}</div>')
# FAQ nested lists
faq = ''.join(f'<li><b>{words(6).capitalize()}?</b><ol type="a">' + ''.join(f'<li>{words(18)}</li>' for _ in range(3)) + '</ol></li>' for _ in range(10))
D['rw-faq'] = page('FAQ', 'ol{margin:4pt 0 8pt;padding-left:18pt}li{margin:2pt 0}', f'<h2>Частые вопросы</h2><ol>{faq}</ol>')
# Application form
fields = ''.join(f'<div class="f"><label>{words(2).capitalize()}</label><input type="text" value="{R.choice(W)}"></div>' for _ in range(10))
D['rw-form'] = page('Form', '.f{display:flex;align-items:center;margin:5pt 0}.f label{width:140pt}.f input{flex:1;border:1px solid #9ca3af;padding:3pt;font-size:10pt}fieldset{border:1px solid #d1d5db;padding:8pt}',
 f'<h2>Заявление</h2><fieldset><legend>Данные</legend>{fields}</fieldset><p><input type="checkbox" checked> Согласен на обработку данных</p>')
# Code listing
code = '\n'.join(f'{i:3d}  ' + R.choice(['$x = foo($y);', 'if ($a && $b) {', '    return [1, 2, 3];', '}', '// comment про код', 'echo "Привет";']) for i in range(1, 70))
D['rw-code'] = page('Code', 'pre{background:#0f172a;color:#e2e8f0;padding:8pt;font-family:"DejaVu Sans Mono",monospace;font-size:8pt;line-height:1.35;border-radius:4pt}', f'<h2>Листинг</h2><pre>{code}</pre>')
# Report with headings + toc
toc = ''.join(f'<li>Раздел {i} — {words(3)}</li>' for i in range(1, 9))
body = ''.join(f'<h2>Раздел {i}</h2><p>{words(80)}</p><blockquote>{words(25)}</blockquote><p>{words(60)}</p>' for i in range(1, 9))
D['rw-annual'] = page('Annual', 'h2{color:#1d4ed8;font-size:14pt;margin:14pt 0 4pt}blockquote{border-left:3pt solid #93c5fd;margin:6pt 0 6pt 10pt;padding-left:8pt;color:#475569;font-style:italic}p{line-height:1.45}', f'<h1>Годовой отчёт 2026</h1><ol>{toc}</ol>{body}')
# Scripts and fallbacks
D['rw-arabic'] = page('Arabic', 'body{font-family:"Noto Sans Arabic","DejaVu Sans";font-size:12pt}p{margin:6pt 0}', '<h2 dir="rtl">تقرير المبيعات</h2>' + ''.join('<p dir="rtl">هذا نص تجريبي باللغة العربية لاختبار الاتجاه من اليمين إلى اليسار في المستند رقم %d.</p>' % i for i in range(1, 9)), lang='ar', font='"Noto Sans Arabic","DejaVu Sans"')
D['rw-cjk'] = page('CJK', 'p{margin:6pt 0;line-height:1.6}', '<h2>报告 / レポート / 보고서</h2>' + ''.join('<p>这是一个用于测试中文字体回退的段落。日本語のテキストも含まれます。한국어 텍스트도 있습니다. Строка %d.</p>' % i for i in range(1, 10)), lang='zh')
D['rw-emoji'] = page('Emoji', 'p{font-size:12pt;margin:6pt 0}', '<h2>Статусы ✅</h2>' + ''.join(f'<p>{e} {words(8)}</p>' for e in ['✅', '⚠️', '❌', '🚀', '📈', '💡', '🔥', '⭐']))
os.makedirs(os.path.join(HERE, 'docs'), exist_ok=True)
for k, v in D.items():
    with open(os.path.join(HERE, 'docs', k + '.html'), 'w', encoding='utf-8') as f: f.write(v)
print(len(D), 'documents')
