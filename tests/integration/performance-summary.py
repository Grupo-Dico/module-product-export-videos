#!/usr/bin/env python3
"""Aggregate four fresh-process runs per mode; reject incomplete or incomparable evidence."""
import argparse
import json
import statistics
from pathlib import Path


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("directory", type=Path)
    args = parser.parse_args()
    report = {
        "case": "PERF01-bounded-native-export-diagnostic",
        "acceptance_threshold": None,
        "protocol": "One warmup plus three measured fresh processes per mode; export call only.",
        "modes": {},
        "limits": [
            "No production throughput claim or extrapolation to dense video catalogs.",
            "Queue wait, publication, browser and download are outside these timings.",
            "RSS peak covers the process; PHP peak resets immediately before export and includes its baseline.",
            "Test-only page size 25 is identical for every mode; native pagination remains inherited.",
        ],
    }
    try:
        reference = None
        for mode in ("bss", "all", "with", "without"):
            runs = []
            for iteration in ("warmup", "1", "2", "3"):
                path = args.directory / f"{mode}-{iteration}.json"
                item = json.loads(path.read_text())
                if item.get("result") != "PASS" or not item.get("owned_runtime_removed"):
                    raise ValueError(f"Failed or unclean run: {path.name}")
                if item.get("mode") != mode or item.get("iteration") != iteration:
                    raise ValueError(f"Mode/iteration mismatch: {path.name}")
                comparable = {
                    "php": item["php"], "source_sha256": item["source_sha256"],
                    "dataset": {key: item["dataset"][key] for key in (
                        "sample_products", "store_contexts_per_product", "candidate_contexts",
                        "contexts_with_video", "contexts_without_video", "page_size", "candidate_pages",
                        "universe_sha256", "filter_sha256", "effective_video_snapshot_sha256",
                    )},
                }
                if reference is None:
                    reference = comparable
                elif reference != comparable:
                    raise ValueError(f"Universe, effective data, runtime or source changed: {path.name}")
                runs.append(item)
            checks = [(r["metrics"]["csv_sha256"], r["metrics"]["rows_written"], r["metrics"]["bytes"]) for r in runs]
            if len(set(checks)) != 1:
                raise ValueError(f"Output changed between {mode} repetitions.")
            measured = runs[1:]
            numeric = ("export_seconds", "php_baseline_bytes", "php_peak_bytes", "php_peak_increase_bytes",
                       "process_rss_peak_bytes", "queries_total", "query_seconds", "reader_queries",
                       "reader_contexts_processed", "rows_written", "bytes")
            report["modes"][mode] = {
                "warmup": runs[0]["metrics"],
                "measurements": [r["metrics"] for r in measured],
                "medians": {key: statistics.median(r["metrics"][key] for r in measured) for key in numeric},
                "output_stable": True,
                "integrity": measured[0]["integrity"],
                "reader_batches": measured[0]["reader_batches"],
            }
        report["comparison_identity"] = reference
        control = report["modes"]["bss"]["medians"]
        for mode in ("all", "with", "without"):
            current = report["modes"][mode]["medians"]
            report["modes"][mode]["informational_comparison_to_bss"] = {
                "time_ratio": current["export_seconds"] / control["export_seconds"],
                "php_peak_delta_bytes": current["php_peak_bytes"] - control["php_peak_bytes"],
                "rows_per_candidate_context": current["rows_written"] / reference["dataset"]["candidate_contexts"],
            }
        report["result"] = "PASS"
        code = 0
    except (OSError, ValueError, KeyError, TypeError) as error:
        report["result"] = "FAIL"
        report["message"] = str(error)
        code = 1
    print(json.dumps(report, indent=2))
    return code


if __name__ == "__main__":
    raise SystemExit(main())
