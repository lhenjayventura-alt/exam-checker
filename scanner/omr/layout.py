"""Answer-sheet geometry. The single source of truth shared by rendering and reading.

All coordinates are PDF points (1/72 inch) on an A4 page, origin at the top-left.
"""

PAGE_W, PAGE_H = 595.28, 841.89
MARKER_SIZE = 22.0
MARKER_MARGIN = 28.0
CONTENT_X0 = 56.0
CONTENT_X1 = PAGE_W - 56.0
QR_SIZE = 72.0
QR_X, QR_Y = CONTENT_X1 - QR_SIZE, 56.0
BODY_TOP = 150.0
BODY_BOTTOM = PAGE_H - 62.0
ROW_H = 20.0
BUBBLE_R = 6.5
BUBBLE_STEP = 19.0
NUMBER_W = 26.0
COLUMN_GAP = 14.0
WRITE_BOX_H = 46.0
WRITE_GAP = 8.0
LAYOUT_VERSION = 1


def marker_centers():
    """Centers of the four corner markers: top-left, top-right, bottom-right, bottom-left."""
    h = MARKER_SIZE / 2
    left, right = MARKER_MARGIN + h, PAGE_W - MARKER_MARGIN - h
    top, bottom = MARKER_MARGIN + h, PAGE_H - MARKER_MARGIN - h
    return [(left, top), (right, top), (right, bottom), (left, bottom)]


def build_layout(spec):
    """spec: {"title", "subtitle", "questions": [{"number", "type", "labels"?}]}

    Bubble questions (multiple_choice, true_false) are laid out in columns; written questions
    (identification, problem_solving) get full-width write-in boxes after the bubbles.
    """
    questions = sorted(spec["questions"], key=lambda q: q["number"])
    bubble_qs = [q for q in questions if q["type"] in ("multiple_choice", "true_false")]
    write_qs = [q for q in questions if q["type"] not in ("multiple_choice", "true_false")]

    def labels_for(q):
        return ["T", "F"] if q["type"] == "true_false" else list(q["labels"])

    width = CONTENT_X1 - CONTENT_X0
    max_labels = max([len(labels_for(q)) for q in bubble_qs] or [1])
    col_w = NUMBER_W + max_labels * BUBBLE_STEP + COLUMN_GAP
    ncols = max(1, min(4, int(width // col_w)))
    col_span = width / ncols
    rows_per_col = int((BODY_BOTTOM - BODY_TOP) // ROW_H)

    # Pages hold ncols x rows_per_col bubble rows. On the last bubble page the rows are spread
    # evenly over all columns so the write-in boxes can usually share that page.
    capacity = ncols * rows_per_col
    items = []
    page = 1
    for start in range(0, len(bubble_qs), capacity):
        chunk = bubble_qs[start:start + capacity]
        rows = rows_per_col if len(chunk) == capacity else -(-len(chunk) // ncols)
        page = start // capacity + 1
        for i, q in enumerate(chunk):
            col, row = divmod(i, rows)
            items.append(_bubble_item(q, labels_for(q), page, CONTENT_X0 + col * col_span, row))

    # Written answers start below the lowest bubble row on the last bubble page.
    on_page = [i["box"][3] for i in items if i["page"] == page]
    y = max(on_page) + 14 if on_page else BODY_TOP
    for q in write_qs:
        if y + WRITE_BOX_H > BODY_BOTTOM:
            page, y = page + 1, BODY_TOP
        items.append({
            "number": q["number"], "kind": "write", "page": page,
            "box": [CONTENT_X0, round(y, 2), CONTENT_X1, round(y + WRITE_BOX_H, 2)],
        })
        y += WRITE_BOX_H + WRITE_GAP

    return {
        "layout_version": LAYOUT_VERSION,
        "page_size": [PAGE_W, PAGE_H],
        "pages": page,
        "markers": [list(c) for c in marker_centers()],
        "marker_size": MARKER_SIZE,
        "qr": [QR_X, QR_Y, QR_SIZE],
        "bubble_radius": BUBBLE_R,
        "title": spec.get("title", ""),
        "subtitle": spec.get("subtitle", ""),
        "items": sorted(items, key=lambda i: i["number"]),
    }


def _bubble_item(q, labels, page, x0, row):
    cy = BODY_TOP + row * ROW_H + ROW_H / 2
    bubbles = [
        {"label": label, "x": round(x0 + NUMBER_W + i * BUBBLE_STEP + BUBBLE_R, 2), "y": round(cy, 2)}
        for i, label in enumerate(labels)
    ]
    return {
        "number": q["number"], "kind": "bubbles", "page": page,
        "number_pos": [round(x0, 2), round(cy, 2)],
        "bubbles": bubbles,
        "box": [round(x0 - 2, 2), round(cy - ROW_H / 2, 2),
                round(bubbles[-1]["x"] + BUBBLE_R + 4, 2), round(cy + ROW_H / 2, 2)],
    }


def items_on_page(layout, page):
    return [i for i in layout["items"] if i["page"] == page]

