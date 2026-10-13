"""Command-line entry point used by the Laravel app.

Usage: python omr_cli.py <layout|render|read>
Reads one JSON object from stdin and writes one JSON object to stdout. Errors are reported
as {"ok": false, "error": "..."} with exit code 1; nothing else is printed to stdout.
"""

import json
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from omr import layout, read, render  # noqa: E402


def main():
    if len(sys.argv) != 2 or sys.argv[1] not in ("layout", "render", "read", "version"):
        print(json.dumps({"ok": False, "error": "usage: omr_cli.py <layout|render|read|version>"}))
        return 2
    command = sys.argv[1]
    try:
        if command == "version":
            import cv2
            out = {"ok": True, "layout_version": layout.LAYOUT_VERSION, "opencv": cv2.__version__}
        else:
            spec = json.loads(sys.stdin.read() or "{}")
            if command == "layout":
                out = {"ok": True, "layout": layout.build_layout(spec)}
            elif command == "render":
                out = {"ok": True, **render.render(spec["layout"], spec["sheets"], spec["output"])}
            else:
                out = read.read(spec)
    except Exception as e:  # report every failure as JSON so the app can show it
        print(json.dumps({"ok": False, "error": f"{type(e).__name__}: {e}"}))
        return 1
    print(json.dumps(out))
    return 0


if __name__ == "__main__":
    sys.exit(main())
