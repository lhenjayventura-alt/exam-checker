"""Test helper: fill rendered answer sheets like students would and save them as a scanned PDF.

Usage: python fill_sheets.py <rendered.pdf> <output.pdf>
stdin: {"layout": {...}, "sheets": [{"marks": {"1": ["B"]}, "write": {"2": "Ohms law"}, "photo": false}]}
Sheets map to the rendered PDF in order (layout["pages"] pages each).
"""

import json
import os
import sys

import cv2
import fitz

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from test_roundtrip import fill, photograph, rasterize, write  # noqa: E402


def main():
    src, out = sys.argv[1], sys.argv[2]
    spec = json.loads(sys.stdin.read())
    layout = spec["layout"]
    per_sheet = layout["pages"]
    items = {str(i["number"]): i for i in layout["items"]}
    pages = rasterize(src)

    doc = fitz.open()
    for s, sheet in enumerate(spec["sheets"]):
        for p in range(per_sheet):
            img = pages[s * per_sheet + p]
            for number, labels in sheet.get("marks", {}).items():
                if items[number]["page"] == p + 1:
                    for label in labels:
                        fill(img, items[number], label)
            for number, text in sheet.get("write", {}).items():
                if items[number]["page"] == p + 1:
                    write(img, items[number], text)
            if sheet.get("photo"):
                img = photograph(img, seed=s + 1)
            ok, enc = cv2.imencode(".jpg", img, [cv2.IMWRITE_JPEG_QUALITY, 85])
            h, w = img.shape[:2]
            page = doc.new_page(width=w * 72 / 200, height=h * 72 / 200)
            page.insert_image(page.rect, stream=enc.tobytes())
    doc.save(out)
    print(json.dumps({"ok": True, "pages": doc.page_count}))


if __name__ == "__main__":
    main()
