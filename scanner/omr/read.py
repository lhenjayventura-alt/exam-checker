"""Reads scanned answer sheets: alignment, QR identification, bubble fill and write-in boxes.

Confidence model (per question, 0..1):
  Each bubble's fill is the fraction of dark pixels inside its inner circle. Fills below
  EMPTY_MAX or above FILLED_MIN are unambiguous (confidence 1). Fills in between (light
  marks, erasures, ticks) lower the confidence below 0.85, so the answer goes to review.
  A bubble counts as marked when its fill is at least MARK_AT.
"""

import os

import cv2
import fitz  # PyMuPDF
import numpy as np

from . import layout as L

SCALE = 2.5            # pixels per point in the aligned page (about 180 dpi)
RENDER_DPI = 200       # rasterization of PDF input
EMPTY_MAX = 0.15
FILLED_MIN = 0.55
MARK_AT = 0.35
INNER = 0.72           # measure inside 72% of the bubble radius, away from the printed ring
BLANK_INK_MAX = 0.004  # write-in box treated as empty below this ink fraction
MAX_PAGES = 200


class SheetError(Exception):
    pass


def load_pages(path):
    """Returns a list of BGR images. PDFs are rasterized; images are decoded from bytes
    (cv2.imread cannot open some Windows paths)."""
    if path.lower().endswith(".pdf"):
        doc = fitz.open(path)
        if doc.page_count > MAX_PAGES:
            raise SheetError(f"The PDF has {doc.page_count} pages; the limit is {MAX_PAGES}.")
        images = []
        for page in doc:
            pix = page.get_pixmap(dpi=RENDER_DPI, colorspace=fitz.csRGB, alpha=False)
            arr = np.frombuffer(pix.samples, dtype=np.uint8).reshape(pix.height, pix.width, 3)
            images.append(cv2.cvtColor(arr, cv2.COLOR_RGB2BGR))
        doc.close()
        return images
    data = np.fromfile(path, dtype=np.uint8)
    img = cv2.imdecode(data, cv2.IMREAD_COLOR)
    if img is None:
        raise SheetError("The image could not be decoded.")
    return [img]


def find_markers(gray):
    """Finds the four solid corner squares; returns centers ordered TL, TR, BR, BL."""
    h, w = gray.shape
    blur = cv2.GaussianBlur(gray, (5, 5), 0)
    _, binary = cv2.threshold(blur, 0, 255, cv2.THRESH_BINARY_INV + cv2.THRESH_OTSU)
    # RETR_LIST: a dark background around the paper must not hide the markers inside it.
    contours, _ = cv2.findContours(binary, cv2.RETR_LIST, cv2.CHAIN_APPROX_SIMPLE)

    area_img = float(h * w)
    candidates = []
    for c in contours:
        area = cv2.contourArea(c)
        if not (area_img * 0.0003 <= area <= area_img * 0.006):
            continue
        x, y, cw, ch = cv2.boundingRect(c)
        if not (0.7 <= cw / float(ch) <= 1.4):
            continue
        if area / float(cw * ch) < 0.8:  # solid, square-ish
            continue
        m = cv2.moments(c)
        candidates.append((m["m10"] / m["m00"], m["m01"] / m["m00"]))

    corners = [(0, 0), (w, 0), (w, h), (0, h)]
    found = []
    for i, (cx, cy) in enumerate(corners):
        in_quadrant = [
            p for p in candidates
            if (p[0] < w / 2) == (i in (0, 3)) and (p[1] < h / 2) == (i in (0, 1))
        ]
        if not in_quadrant:
            raise SheetError("Could not find all four corner markers. Make sure the whole sheet is visible and in focus.")
        found.append(min(in_quadrant, key=lambda p: (p[0] - cx) ** 2 + (p[1] - cy) ** 2))
    return np.array(found, dtype=np.float32)


def align(image):
    gray = cv2.cvtColor(image, cv2.COLOR_BGR2GRAY)
    if gray.shape[1] > gray.shape[0]:  # landscape scan of a portrait sheet
        gray = cv2.rotate(gray, cv2.ROTATE_90_CLOCKWISE)
    src = find_markers(gray)
    dst = np.array([[x * SCALE, y * SCALE] for x, y in L.marker_centers()], dtype=np.float32)
    size = (int(L.PAGE_W * SCALE), int(L.PAGE_H * SCALE))
    matrix = cv2.getPerspectiveTransform(src, dst)
    return cv2.warpPerspective(gray, matrix, size, flags=cv2.INTER_LINEAR, borderValue=255)


def decode_qr(aligned):
    """Returns (payload or None, aligned image in upright orientation)."""
    detector = cv2.QRCodeDetector()
    for candidate in (aligned, cv2.rotate(aligned, cv2.ROTATE_180)):
        qx, qy, qs = L.QR_X, L.QR_Y, L.QR_SIZE
        pad = 12
        roi = candidate[int((qy - pad) * SCALE):int((qy + qs + pad) * SCALE),
                        int((qx - pad) * SCALE):int((qx + qs + pad) * SCALE)]
        text, _, _ = detector.detectAndDecode(roi)
        if text:
            return text, candidate
    return None, aligned


def dark_threshold(aligned):
    t, _ = cv2.threshold(aligned, 0, 255, cv2.THRESH_BINARY + cv2.THRESH_OTSU)
    return float(min(170.0, max(90.0, t)))


def bubble_fill(aligned, x, y, r, threshold):
    cx, cy, rr = int(round(x * SCALE)), int(round(y * SCALE)), max(2, int(r * INNER * SCALE))
    patch = aligned[cy - rr:cy + rr + 1, cx - rr:cx + rr + 1]
    yy, xx = np.ogrid[-rr:rr + 1, -rr:rr + 1]
    mask = (xx * xx + yy * yy) <= rr * rr
    return float(np.mean(patch[mask] < threshold))


def bubble_confidence(fill):
    if fill <= EMPTY_MAX or fill >= FILLED_MIN:
        return 1.0
    # Inside the ambiguous band: at most 0.84, lowest at the marking threshold.
    return round(0.84 * abs(fill - MARK_AT) / max(MARK_AT - EMPTY_MAX, FILLED_MIN - MARK_AT), 3)


def crop(aligned, box, path, pad=4):
    x0, y0, x1, y1 = box
    region = aligned[int((y0 - pad) * SCALE):int((y1 + pad) * SCALE), int((x0 - pad) * SCALE):int((x1 + pad) * SCALE)]
    cv2.imencode(".png", region)[1].tofile(path)


def read_items(aligned, layout, page, threshold, work_dir, prefix, review_below):
    answers = {}
    r = layout["bubble_radius"]
    for item in L.items_on_page(layout, page):
        key = str(item["number"])
        if item["kind"] == "bubbles":
            fills = {b["label"]: round(bubble_fill(aligned, b["x"], b["y"], r, threshold), 3) for b in item["bubbles"]}
            marked = [label for label, f in fills.items() if f >= MARK_AT]
            confidence = min(bubble_confidence(f) for f in fills.values())
            entry = {"kind": "bubbles", "marked": marked, "fills": fills, "confidence": confidence, "crop": None}
            if confidence < review_below or len(marked) > 1:
                entry["crop"] = f"{prefix}-q{key}.png"
                crop(aligned, item["box"], os.path.join(work_dir, entry["crop"]))
        else:
            x0, y0, x1, y1 = item["box"]
            inner = aligned[int((y0 + 13) * SCALE):int((y1 - 4) * SCALE), int((x0 + 4) * SCALE):int((x1 - 4) * SCALE)]
            ink = float(np.mean(inner < threshold))
            blank = ink < BLANK_INK_MAX
            entry = {"kind": "write", "blank": blank, "ink": round(ink, 4),
                     "confidence": 0.95 if blank else 0.0, "crop": f"{prefix}-q{key}.png"}
            crop(aligned, item["box"], os.path.join(work_dir, entry["crop"]))
        answers[key] = entry
    return answers


def read(spec):
    """spec: {"input", "work_dir", "layouts": {id: layout}, "default_layout": id|None, "review_below": 0.85}"""
    os.makedirs(spec["work_dir"], exist_ok=True)
    layouts = {str(k): v for k, v in spec["layouts"].items()}
    default = str(spec["default_layout"]) if spec.get("default_layout") is not None else None
    review_below = float(spec.get("review_below", 0.85))

    try:
        images = load_pages(spec["input"])
    except SheetError as e:
        return {"ok": False, "error": str(e), "pages": []}

    results = []
    for index, image in enumerate(images, start=1):
        result = {"index": index, "ok": False, "error": None, "warnings": [], "qr": None,
                  "layout_id": None, "sheet_page": None, "aligned": None, "answers": {}}
        try:
            aligned = align(image)
            qr, aligned = decode_qr(aligned)
            result["qr"] = qr
            layout_id, sheet_page = _parse_qr(qr)
            if layout_id is None:
                result["warnings"].append("QR code not readable; the sheet could not be identified automatically.")
                layout_id, sheet_page = default, 1
            if layout_id not in layouts:
                raise SheetError("This sheet does not belong to this exam (unknown answer-sheet layout).")
            result["layout_id"], result["sheet_page"] = layout_id, sheet_page

            aligned_name = f"page-{index}.jpg"
            cv2.imencode(".jpg", aligned, [cv2.IMWRITE_JPEG_QUALITY, 80])[1].tofile(os.path.join(spec["work_dir"], aligned_name))
            result["aligned"] = aligned_name

            threshold = dark_threshold(aligned)
            result["answers"] = read_items(aligned, layouts[layout_id], sheet_page, threshold,
                                           spec["work_dir"], f"p{index}", review_below)
            result["ok"] = True
        except SheetError as e:
            result["error"] = str(e)
        results.append(result)
    return {"ok": True, "error": None, "pages": results}


def _parse_qr(qr):
    """Payload: EC1|layout_id|student_id|page|signature (the signature is verified by the app)."""
    if not qr:
        return None, None
    parts = qr.split("|")
    if len(parts) != 5 or parts[0] != "EC1" or not parts[3].isdigit():
        return None, None
    return parts[1], int(parts[3])
