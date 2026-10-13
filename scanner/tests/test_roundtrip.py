"""Round-trip tests: render a sheet, mark it like a student, distort it like a photo, read it back.

Run: scanner/.venv/Scripts/python -m unittest discover -s scanner/tests
"""

import os
import shutil
import sys
import tempfile
import unittest

import cv2
import fitz
import numpy as np

sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))

from omr import layout as L  # noqa: E402
from omr import read, render  # noqa: E402

DPI = 200
PX = DPI / 72.0

SPEC = {
    "title": "Synthetic Quiz",
    "subtitle": "DEMO-101 A · Version 1",
    "questions": [
        {"number": 1, "type": "multiple_choice", "labels": ["A", "B", "C", "D"]},
        {"number": 2, "type": "multiple_choice", "labels": ["A", "B", "C", "D"]},
        {"number": 3, "type": "true_false"},
        {"number": 4, "type": "multiple_choice", "labels": ["A", "B", "C", "D", "E"]},
        {"number": 5, "type": "true_false"},
        {"number": 6, "type": "identification"},
        {"number": 7, "type": "problem_solving"},
    ],
}


def rasterize(pdf_path):
    doc = fitz.open(pdf_path)
    pages = []
    for page in doc:
        pix = page.get_pixmap(dpi=DPI, colorspace=fitz.csRGB, alpha=False)
        arr = np.frombuffer(pix.samples, dtype=np.uint8).reshape(pix.height, pix.width, 3)
        pages.append(cv2.cvtColor(arr, cv2.COLOR_RGB2BGR).copy())
    doc.close()
    return pages


def fill(img, item, label, strength=1.0, x_mark=False):
    b = next(b for b in item["bubbles"] if b["label"] == label)
    c = (int(b["x"] * PX), int(b["y"] * PX))
    r = int(L.BUBBLE_R * PX * 0.85)
    if x_mark:  # a light tick/cross: the ambiguous case
        cv2.line(img, (c[0] - r, c[1] - r), (c[0] + r, c[1] + r), (60, 60, 60), 3)
        cv2.line(img, (c[0] - r, c[1] + r), (c[0] + r, c[1] - r), (60, 60, 60), 3)
    else:
        shade = int(255 * (1 - strength))
        cv2.circle(img, c, r, (shade, shade, shade), -1)


def write(img, item, text):
    x0, y0, _, y1 = item["box"]
    cv2.putText(img, text, (int((x0 + 30) * PX), int((y1 - 10) * PX)), cv2.FONT_HERSHEY_SCRIPT_SIMPLEX, 1.6, (30, 30, 30), 3)


def photograph(img, seed=1):
    """Perspective skew, slight rotation, blur, noise and JPEG compression."""
    rng = np.random.default_rng(seed)
    h, w = img.shape[:2]
    canvas = np.full((h + 400, w + 400, 3), 120, np.uint8)  # dark table around the paper
    canvas[200:200 + h, 200:200 + w] = img
    src = np.float32([[200, 200], [200 + w, 200], [200 + w, 200 + h], [200, 200 + h]])
    jitter = rng.uniform(-60, 60, (4, 2)).astype(np.float32)
    m = cv2.getPerspectiveTransform(src, src + jitter)
    out = cv2.warpPerspective(canvas, m, (canvas.shape[1], canvas.shape[0]), borderValue=(120, 120, 120))
    out = cv2.GaussianBlur(out, (3, 3), 0)
    out = np.clip(out.astype(np.int16) + rng.normal(0, 6, out.shape).astype(np.int16), 0, 255).astype(np.uint8)
    ok, enc = cv2.imencode(".jpg", out, [cv2.IMWRITE_JPEG_QUALITY, 70])
    return cv2.imdecode(enc, cv2.IMREAD_COLOR)


class RoundTripTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.mkdtemp()
        self.layout = L.build_layout(SPEC)
        self.items = {i["number"]: i for i in self.layout["items"]}
        pdf = os.path.join(self.tmp, "sheet.pdf")
        render.render(self.layout, [{"qr": ["EC1|7|42|1|abcdef0123"], "name": "Dela Cruz, Juan", "student_number": "TEST-0001"}], pdf)
        self.page = rasterize(pdf)[0]

    def tearDown(self):
        shutil.rmtree(self.tmp, ignore_errors=True)

    def marked_sheet(self):
        img = self.page.copy()
        fill(img, self.items[1], "B")
        fill(img, self.items[2], "A")
        fill(img, self.items[2], "C")          # two marks
        fill(img, self.items[3], "F", strength=0.75)  # pencil-grey
        fill(img, self.items[4], "D", x_mark=True)    # ambiguous cross
        # 5 left blank
        write(img, self.items[6], "Ohms law")
        # 7 left blank
        return img

    def run_read(self, image, name="scan.jpg"):
        path = os.path.join(self.tmp, name)
        cv2.imencode(os.path.splitext(name)[1], image)[1].tofile(path)
        return read.read({"input": path, "work_dir": os.path.join(self.tmp, "out"),
                          "layouts": {"7": self.layout}, "default_layout": "7", "review_below": 0.85})

    def assert_expected(self, page):
        self.assertTrue(page["ok"], page["error"])
        self.assertEqual(page["qr"], "EC1|7|42|1|abcdef0123")
        a = page["answers"]
        self.assertEqual(a["1"]["marked"], ["B"])
        self.assertEqual(a["1"]["confidence"], 1.0)
        self.assertEqual(a["2"]["marked"], ["A", "C"])
        self.assertIsNotNone(a["2"]["crop"])
        self.assertEqual(a["3"]["marked"], ["F"])
        self.assertGreaterEqual(a["3"]["confidence"], 0.85)
        self.assertLess(a["4"]["confidence"], 0.85, a["4"]["fills"])  # ambiguous mark goes to review
        self.assertEqual(a["5"]["marked"], [])
        self.assertEqual(a["5"]["confidence"], 1.0)
        self.assertFalse(a["6"]["blank"], a["6"]["ink"])
        self.assertTrue(a["7"]["blank"], a["7"]["ink"])
        for key in ("2", "4", "6", "7"):
            if a[key]["crop"]:
                self.assertTrue(os.path.exists(os.path.join(self.tmp, "out", a[key]["crop"])))

    def test_clean_scan(self):
        result = self.run_read(self.marked_sheet(), "scan.png")
        self.assert_expected(result["pages"][0])

    def test_phone_photo_with_perspective_and_noise(self):
        for seed in (1, 2, 3):
            with self.subTest(seed=seed):
                result = self.run_read(photograph(self.marked_sheet(), seed), f"photo{seed}.jpg")
                self.assert_expected(result["pages"][0])

    def test_upside_down_and_landscape(self):
        upside = cv2.rotate(self.marked_sheet(), cv2.ROTATE_180)
        self.assert_expected(self.run_read(upside, "upside.png")["pages"][0])
        sideways = cv2.rotate(self.marked_sheet(), cv2.ROTATE_90_COUNTERCLOCKWISE)
        self.assert_expected(self.run_read(sideways, "sideways.png")["pages"][0])

    def test_pdf_with_multiple_pages(self):
        pdf = os.path.join(self.tmp, "batch.pdf")
        doc = fitz.open()
        for img in (self.marked_sheet(), self.page):
            ok, enc = cv2.imencode(".png", img)
            page = doc.new_page(width=L.PAGE_W, height=L.PAGE_H)
            page.insert_image(page.rect, stream=enc.tobytes())
        doc.save(pdf)
        doc.close()
        result = read.read({"input": pdf, "work_dir": os.path.join(self.tmp, "out"),
                            "layouts": {"7": self.layout}, "default_layout": "7"})
        self.assertEqual(len(result["pages"]), 2)
        self.assert_expected(result["pages"][0])
        self.assertEqual(result["pages"][1]["answers"]["1"]["marked"], [])

    def test_missing_markers_is_reported(self):
        blank = np.full((2000, 1400, 3), 255, np.uint8)
        page = self.run_read(blank, "blank.png")["pages"][0]
        self.assertFalse(page["ok"])
        self.assertIn("corner markers", page["error"])

    def test_foreign_layout_is_rejected(self):
        path = os.path.join(self.tmp, "scan.png")
        cv2.imencode(".png", self.marked_sheet())[1].tofile(path)
        result = read.read({"input": path, "work_dir": os.path.join(self.tmp, "out"), "layouts": {"99": self.layout}})
        self.assertIn("does not belong", result["pages"][0]["error"])

    def test_layout_paginates_long_exams(self):
        many = {"questions": [{"number": n, "type": "multiple_choice", "labels": list("ABCD")} for n in range(1, 201)]
                + [{"number": 201, "type": "identification"}]}
        lay = L.build_layout(many)
        self.assertGreater(lay["pages"], 1)
        for item in lay["items"]:
            x0, y0, x1, y1 = item["box"]
            self.assertGreaterEqual(y0, L.BODY_TOP - 10)
            self.assertLessEqual(y1, L.BODY_BOTTOM + 1)
            self.assertLessEqual(x1, L.CONTENT_X1 + 6)


if __name__ == "__main__":
    unittest.main()
