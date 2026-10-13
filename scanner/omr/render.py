"""Renders printable answer sheets (PDF) from a layout."""

import io

import fitz  # PyMuPDF
import qrcode

from . import layout as L

BLACK = (0, 0, 0)
INK = (0.15, 0.15, 0.15)
LIGHT = (0.72, 0.72, 0.72)  # bubble letters: light enough not to count as a mark


def _qr_png(payload):
    qr = qrcode.QRCode(error_correction=qrcode.constants.ERROR_CORRECT_M, border=1, box_size=8)
    qr.add_data(payload)
    qr.make(fit=True)
    buf = io.BytesIO()
    qr.make_image(fill_color="black", back_color="white").save(buf, format="PNG")
    return buf.getvalue()


def render(layout, sheets, output_path):
    """sheets: [{"qr": [payload per page], "name": str|None, "student_number": str|None}]"""
    doc = fitz.open()
    pages = layout["pages"]
    for sheet in sheets:
        for p in range(1, pages + 1):
            page = doc.new_page(width=L.PAGE_W, height=L.PAGE_H)
            _draw_frame(page, layout, sheet, p, pages)
            for item in L.items_on_page(layout, p):
                if item["kind"] == "bubbles":
                    _draw_bubble_row(page, item, layout["bubble_radius"])
                else:
                    _draw_write_box(page, item)
    doc.save(output_path, deflate=True)
    count = doc.page_count
    doc.close()
    return {"pages": count}


def _draw_frame(page, layout, sheet, p, pages):
    s = layout["marker_size"]
    for cx, cy in layout["markers"]:
        page.draw_rect(fitz.Rect(cx - s / 2, cy - s / 2, cx + s / 2, cy + s / 2), color=BLACK, fill=BLACK, width=0)

    qx, qy, qs = layout["qr"]
    page.insert_image(fitz.Rect(qx, qy, qx + qs, qy + qs), stream=_qr_png(sheet["qr"][p - 1]))

    x = L.CONTENT_X0
    page.insert_text((x, 70), layout.get("title", "")[:70], fontsize=13, fontname="hebo", color=INK)
    page.insert_text((x, 86), layout.get("subtitle", "")[:90], fontsize=9, fontname="helv", color=INK)

    name = sheet.get("name") or ""
    number = sheet.get("student_number") or ""
    page.insert_text((x, 108), "Name:", fontsize=9, fontname="hebo", color=INK)
    page.draw_line((x + 34, 110), (x + 270, 110), color=INK, width=0.6)
    if name:
        page.insert_text((x + 38, 107), name[:48], fontsize=10, fontname="helv", color=INK)
    page.insert_text((x, 126), "Student no.:", fontsize=9, fontname="hebo", color=INK)
    page.draw_line((x + 58, 128), (x + 270, 128), color=INK, width=0.6)
    if number:
        page.insert_text((x + 62, 125), number[:30], fontsize=10, fontname="cour", color=INK)

    page.insert_text((x, 142), "Fill each bubble completely with a dark pen or pencil. Erase changes cleanly. "
                     "Write answers only inside the boxes.", fontsize=7, fontname="helv", color=INK)
    page.insert_text((L.CONTENT_X1 - 60, L.PAGE_H - 44), f"Page {p} of {pages}", fontsize=8, fontname="helv", color=INK)


def _draw_bubble_row(page, item, r):
    nx, ny = item["number_pos"]
    page.insert_text((nx, ny + 3), f"{item['number']}.", fontsize=9, fontname="hebo", color=INK)
    for b in item["bubbles"]:
        page.draw_circle((b["x"], b["y"]), r, color=INK, width=0.8)
        tw = fitz.get_text_length(b["label"], fontname="helv", fontsize=6.5)
        page.insert_text((b["x"] - tw / 2, b["y"] + 2.3), b["label"], fontsize=6.5, fontname="helv", color=LIGHT)


def _draw_write_box(page, item):
    x0, y0, x1, y1 = item["box"]
    page.draw_rect(fitz.Rect(x0, y0, x1, y1), color=INK, width=0.8)
    page.insert_text((x0 + 4, y0 + 10), f"{item['number']}.", fontsize=9, fontname="hebo", color=INK)
    page.insert_text((x0 + 24, y0 + 9), "Write your final answer here", fontsize=6.5, fontname="helv", color=LIGHT)
