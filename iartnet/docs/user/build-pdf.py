#!/usr/bin/env python3
"""Genera il PDF del manuale utente da file Markdown (solo stdlib + Microsoft Edge)."""

from __future__ import annotations

import html
import re
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent
SOURCE_FILES = [
    "README.md",
    "01-setup-mirror.md",
    "02-import-mirror.md",
    "03-promozione-master.md",
    "04-gestione-master.md",
    "05-translation.md",
    "06-interviews.md",
    "07-salon.md",
    "08-narrations.md",
]
OUTPUT_HTML = ROOT / "manuale-iartnet.html"
OUTPUT_PDF = ROOT / "manuale-iartnet.pdf"
EDGE_PATH = Path(r"C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe")

INLINE_RE = re.compile(
    r"(\*\*(.+?)\*\*|`([^`]+)`|\*([^*]+?)\*|\[([^\]]+)\]\(([^)]+)\))"
)


def resolve_image(relative_path: str) -> Path | None:
    candidate = ROOT / relative_path
    if candidate.exists():
        return candidate
    svg = candidate.with_suffix(".svg")
    if svg.exists():
        return svg
    return None


def file_uri(path: Path) -> str:
    return path.resolve().as_uri()


def inline_markup(text: str) -> str:
    result: list[str] = []
    pos = 0
    for match in INLINE_RE.finditer(text):
        result.append(html.escape(text[pos : match.start()]))
        if match.group(2):
            result.append(f"<strong>{html.escape(match.group(2))}</strong>")
        elif match.group(3):
            result.append(f"<code>{html.escape(match.group(3))}</code>")
        elif match.group(4):
            result.append(f"<em>{html.escape(match.group(4))}</em>")
        elif match.group(5):
            label = html.escape(match.group(5))
            href = match.group(6)
            if href.endswith(".md"):
                result.append(f'<span class="md-link">{label}</span>')
            else:
                result.append(f'<a href="{html.escape(href)}">{label}</a>')
        pos = match.end()
    result.append(html.escape(text[pos:]))
    return "".join(result)


def is_table_row(line: str) -> bool:
    stripped = line.strip()
    return stripped.startswith("|") and stripped.endswith("|")


def is_table_separator(line: str) -> bool:
    stripped = line.strip().strip("|")
    cells = [cell.strip() for cell in stripped.split("|")]
    if not cells:
        return False
    return all(re.fullmatch(r":?-{3,}:?", cell or "-") for cell in cells)


def parse_table_row(line: str) -> list[str]:
    return [cell.strip() for cell in line.strip().strip("|").split("|")]


def render_table(rows: list[list[str]]) -> str:
    if not rows:
        return ""
    header = rows[0]
    body = rows[1:]
    parts = ["<table>", "<thead><tr>"]
    for cell in header:
        parts.append(f"<th>{inline_markup(cell)}</th>")
    parts.append("</tr></thead>")
    if body:
        parts.append("<tbody>")
        for row in body:
            parts.append("<tr>")
            for cell in row:
                parts.append(f"<td>{inline_markup(cell)}</td>")
            parts.append("</tr>")
        parts.append("</tbody>")
    parts.append("</table>")
    return "".join(parts)


def markdown_to_html(markdown: str) -> str:
    lines = markdown.splitlines()
    output: list[str] = []
    i = 0

    while i < len(lines):
        line = lines[i]
        stripped = line.strip()

        if not stripped:
            i += 1
            continue

        if stripped == "---":
            output.append("<hr>")
            i += 1
            continue

        if stripped.startswith("```"):
            lang = stripped[3:].strip()
            code_lines: list[str] = []
            i += 1
            while i < len(lines) and not lines[i].strip().startswith("```"):
                code_lines.append(lines[i])
                i += 1
            if i < len(lines):
                i += 1
            cls = f' class="language-{html.escape(lang)}"' if lang else ""
            code = html.escape("\n".join(code_lines))
            output.append(f"<pre><code{cls}>{code}</code></pre>")
            continue

        if stripped.startswith("#"):
            level = len(stripped) - len(stripped.lstrip("#"))
            title = stripped[level:].strip()
            tag = f"h{min(level, 6)}"
            chapter_class = ' class="chapter-title"' if level == 1 and title.startswith("Capitolo") else ""
            output.append(f"<{tag}{chapter_class}>{inline_markup(title)}</{tag}>")
            i += 1
            continue

        if stripped.startswith("> "):
            quote_lines: list[str] = []
            while i < len(lines) and lines[i].strip().startswith(">"):
                quote_lines.append(lines[i].strip()[2:].strip())
                i += 1
            quote_html = "<br>".join(inline_markup(part) for part in quote_lines)
            output.append(f"<blockquote><p>{quote_html}</p></blockquote>")
            continue

        image_match = re.fullmatch(r"!\[([^\]]*)\]\(([^)]+)\)", stripped)
        if image_match:
            alt = image_match.group(1)
            rel = image_match.group(2)
            image_path = resolve_image(rel)
            if image_path:
                output.append(
                    f'<figure><img src="{file_uri(image_path)}" alt="{html.escape(alt)}">'
                    f'<figcaption>{html.escape(alt)}</figcaption></figure>'
                )
            else:
                output.append(
                    f'<p class="missing-image">[Immagine non trovata: {html.escape(rel)}]</p>'
                )
            i += 1
            continue

        if is_table_row(line):
            table_rows: list[list[str]] = []
            while i < len(lines) and is_table_row(lines[i]):
                if not is_table_separator(lines[i]):
                    table_rows.append(parse_table_row(lines[i]))
                i += 1
            output.append(render_table(table_rows))
            continue

        if re.match(r"^[-*] \[[ xX]\] ", stripped):
            items: list[str] = []
            while i < len(lines):
                current = lines[i].strip()
                match = re.match(r"^[-*] \[([ xX])\] (.+)$", current)
                if not match:
                    break
                checked = "checked" if match.group(1).lower() == "x" else ""
                items.append(
                    f'<li class="checklist-item"><input type="checkbox" disabled {checked}> '
                    f"{inline_markup(match.group(2))}</li>"
                )
                i += 1
            output.append("<ul class='checklist'>" + "".join(items) + "</ul>")
            continue

        if stripped.startswith("- ") or stripped.startswith("* "):
            items = []
            while i < len(lines):
                current = lines[i]
                match = re.match(r"^(\s*)[-*] (.+)$", current)
                if not match:
                    break
                indent = len(match.group(1))
                if indent > 0:
                    items.append(
                        f'<li class="nested" style="margin-left:{indent * 12}px">'
                        f"{inline_markup(match.group(2))}</li>"
                    )
                else:
                    items.append(f"<li>{inline_markup(match.group(2))}</li>")
                i += 1
            output.append("<ul>" + "".join(items) + "</ul>")
            continue

        paragraph_lines = [stripped]
        i += 1
        while i < len(lines):
            nxt = lines[i].strip()
            if (
                not nxt
                or nxt.startswith("#")
                or nxt == "---"
                or nxt.startswith("```")
                or nxt.startswith("> ")
                or nxt.startswith("- ")
                or nxt.startswith("* ")
                or is_table_row(lines[i])
                or re.fullmatch(r"!\[([^\]]*)\]\(([^)]+)\)", nxt)
            ):
                break
            paragraph_lines.append(nxt)
            i += 1
        output.append(f"<p>{inline_markup(' '.join(paragraph_lines))}</p>")

    return "\n".join(output)


def build_html() -> str:
    sections: list[str] = []
    for filename in SOURCE_FILES:
        path = ROOT / filename
        if not path.exists():
            raise FileNotFoundError(f"File mancante: {path}")
        sections.append(markdown_to_html(path.read_text(encoding="utf-8")))

    body = "\n".join(sections)
    return f"""<!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="utf-8">
  <title>Manuale utente IARTNET — Ingestion e Master Data</title>
  <style>
    @page {{
      size: A4;
      margin: 20mm 18mm 22mm 18mm;
    }}
    * {{ box-sizing: border-box; }}
    body {{
      font-family: "Segoe UI", Calibri, Arial, sans-serif;
      font-size: 10.5pt;
      line-height: 1.45;
      color: #1a1a1a;
      max-width: 100%;
      margin: 0;
      padding: 0;
    }}
    h1 {{
      font-size: 22pt;
      color: #0f3d5c;
      border-bottom: 2px solid #0f3d5c;
      padding-bottom: 0.3em;
      margin: 0 0 1em 0;
      page-break-after: avoid;
    }}
    h1.chapter-title {{
      page-break-before: always;
      margin-top: 0;
    }}
    h1:first-of-type {{
      page-break-before: avoid;
    }}
    h2 {{
      font-size: 14pt;
      color: #164a6e;
      margin-top: 1.4em;
      page-break-after: avoid;
    }}
    h3 {{
      font-size: 12pt;
      color: #1f5f87;
      margin-top: 1.1em;
      page-break-after: avoid;
    }}
    h4 {{
      font-size: 11pt;
      color: #2a6f98;
      margin-top: 0.9em;
      page-break-after: avoid;
    }}
    p {{ margin: 0.5em 0 0.8em 0; }}
    blockquote {{
      margin: 0.8em 0;
      padding: 0.6em 1em;
      border-left: 4px solid #7eb8da;
      background: #f3f8fb;
      color: #333;
    }}
    hr {{
      border: none;
      border-top: 1px solid #ccd8e0;
      margin: 1.5em 0;
    }}
    code {{
      font-family: Consolas, "Courier New", monospace;
      font-size: 0.92em;
      background: #f2f4f6;
      padding: 0.1em 0.35em;
      border-radius: 3px;
    }}
    pre {{
      background: #f5f7f9;
      border: 1px solid #dde4ea;
      border-radius: 4px;
      padding: 0.8em 1em;
      overflow-x: auto;
      font-size: 9pt;
      line-height: 1.35;
      page-break-inside: avoid;
    }}
    pre code {{ background: none; padding: 0; }}
    table {{
      width: 100%;
      border-collapse: collapse;
      margin: 0.8em 0 1.2em 0;
      font-size: 9pt;
      page-break-inside: avoid;
    }}
    th, td {{
      border: 1px solid #c5d0d8;
      padding: 0.45em 0.55em;
      vertical-align: top;
      text-align: left;
    }}
    th {{
      background: #e8f0f6;
      font-weight: 600;
    }}
    tr:nth-child(even) td {{ background: #fafbfc; }}
    ul {{
      margin: 0.4em 0 0.9em 0;
      padding-left: 1.4em;
    }}
    ul.checklist {{
      list-style: none;
      padding-left: 0.2em;
    }}
    .checklist-item {{
      margin: 0.25em 0;
    }}
    .checklist-item input {{
      margin-right: 0.45em;
      vertical-align: middle;
    }}
    figure {{
      margin: 1em 0 1.2em 0;
      page-break-inside: avoid;
      text-align: center;
    }}
    figure img {{
      max-width: 100%;
      height: auto;
      border: 1px solid #d0d8de;
      border-radius: 4px;
    }}
    figcaption {{
      font-size: 9pt;
      color: #555;
      margin-top: 0.4em;
      font-style: italic;
    }}
    .md-link {{
      color: #1f5f87;
      font-weight: 600;
    }}
    .missing-image {{
      color: #a33;
      font-style: italic;
      border: 1px dashed #c88;
      padding: 0.6em;
      background: #fff8f8;
    }}
  </style>
</head>
<body>
{body}
</body>
</html>
"""


def print_pdf(html_path: Path, pdf_path: Path) -> None:
    if not EDGE_PATH.exists():
        raise FileNotFoundError(
            f"Microsoft Edge non trovato in {EDGE_PATH}. "
            "Installare Edge o aggiornare EDGE_PATH nello script."
        )
    if pdf_path.exists():
        pdf_path.unlink()
    cmd = [
        str(EDGE_PATH),
        "--headless",
        "--disable-gpu",
        "--no-pdf-header-footer",
        f"--print-to-pdf={pdf_path}",
        html_path.as_uri(),
    ]
    result = subprocess.run(cmd, capture_output=True, text=True, timeout=120)
    if result.returncode != 0 or not pdf_path.exists():
        raise RuntimeError(
            "Generazione PDF fallita.\n"
            f"stdout: {result.stdout}\n"
            f"stderr: {result.stderr}"
        )


def main() -> int:
    html_content = build_html()
    OUTPUT_HTML.write_text(html_content, encoding="utf-8")
    print(f"HTML: {OUTPUT_HTML}")
    print_pdf(OUTPUT_HTML, OUTPUT_PDF)
    size_kb = OUTPUT_PDF.stat().st_size / 1024
    print(f"PDF:  {OUTPUT_PDF} ({size_kb:.1f} KB)")
    return 0


if __name__ == "__main__":
    sys.exit(main())
