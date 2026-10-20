#!/usr/bin/env python3
import json
import os
import sys

os.environ.setdefault("FLAGS_use_mkldnn", "0")
os.environ.setdefault("FLAGS_enable_pir_api", "0")
os.environ.setdefault("PADDLE_PDX_DISABLE_MODEL_SOURCE_CHECK", "True")

from paddleocr import PaddleOCR

def main():
    if len(sys.argv) != 2:
        print(json.dumps({"ok": False, "error": "image path required"}))
        return 2
    path = sys.argv[1]
    if not os.path.isfile(path):
        print(json.dumps({"ok": False, "error": "image not found"}))
        return 2

    ocr = PaddleOCR(
        lang="en",
        use_doc_orientation_classify=False,
        use_doc_unwarping=False,
        use_textline_orientation=False,
        enable_mkldnn=False,
    )
    texts, scores = [], []
    for result in ocr.predict(path):
        payload = result.json
        data = payload.get("res", payload)
        for text, score in zip(data.get("rec_texts", []) or [], data.get("rec_scores", []) or []):
            text = str(text).strip()
            if text:
                texts.append(text)
                try:
                    scores.append(float(score))
                except Exception:
                    pass
    print(json.dumps({
        "ok": True,
        "text": "\n".join(texts),
        "mean_confidence": (sum(scores) / len(scores)) if scores else None,
        "line_count": len(texts),
    }, ensure_ascii=False))
    return 0

if __name__ == "__main__":
    raise SystemExit(main())
