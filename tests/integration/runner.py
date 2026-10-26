#!/usr/bin/env python3
"""Run integration checks against an explicitly configured Magento test destination."""
from __future__ import annotations

import argparse
import datetime as dt
import fcntl
import hashlib
import io
import json
import os
from pathlib import Path
import re
import secrets
import signal
import subprocess
import sys
import tarfile
import tempfile
import time

PROJECT = Path(__file__).resolve().parents[2]
ALL_STAGES = ("preflight", "reader", "product-export", "csv-cleanup", "fixtures", "queue", "admin",
              "failures", "performance")
VOLUME_STAGES = ("catalog", "dense-performance")
M04_MODULE_ROOT = "src/LeanCommerce/ProductVideoExport/"
M04_FILES = frozenset(("composer.json", "tests/integration/failures.php", "tests/integration/FailureHarness.php",
                       "tests/integration/runner-support.php"))
M04_SCOPE = "m04-production-and-harness-v1"
SCRIPTS = {
    "preflight": "probes/preflight.php", "reader": "probes/batch-reader.php",
    "product-export": "probes/product-export.php", "csv-cleanup": "probes/csv-cleanup.php",
    "fixtures": "tests/fixtures.php", "queue": "tests/queue.php", "admin": "tests/admin.php",
    "failures": "tests/failures.php", "recover": "tests/recover.php",
    "performance": "tests/performance.php", "catalog": "tests/catalog.php",
    "dense-performance": "tests/dense-performance.php",
}


class RunnerFailure(RuntimeError):
    """A check, process or ownership boundary did not pass."""


def target_config(environment: dict | None = None) -> dict:
    """Validate operator inputs before creating reports, locks or invoking Docker."""
    values = os.environ if environment is None else environment
    names = {"root": "LCVX_MAGENTO_ROOT", "container": "LCVX_CONTAINER",
             "user": "LCVX_CONTAINER_USER", "php_binary": "LCVX_PHP_BINARY"}
    target = {}
    for key, name in names.items():
        value = values.get(name, "")
        if not isinstance(value, str) or not value:
            raise RunnerFailure(f"Required destination setting is missing: {name}")
        target[key] = value
    path_pattern = r"/[A-Za-z0-9_.-]+(?:/[A-Za-z0-9_.-]+)*"
    def absolute_path(value):
        return re.fullmatch(path_pattern, value) and not ({".", ".."} & set(value.split("/")))
    if not absolute_path(target["root"]):
        raise RunnerFailure("LCVX_MAGENTO_ROOT must be an absolute normalized path without spaces")
    if not re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9_.-]{0,127}", target["container"]):
        raise RunnerFailure("LCVX_CONTAINER must be an explicit Docker container name or ID")
    identity = r"(?:[a-z_][a-z0-9_-]*|[0-9]+)"
    if not re.fullmatch(identity + r"(?::" + identity + r")?", target["user"]):
        raise RunnerFailure("LCVX_CONTAINER_USER must be a user/UID, optionally with group/GID")
    php = target["php_binary"]
    if not (re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9_.-]*", php) or absolute_path(php)):
        raise RunnerFailure("LCVX_PHP_BINARY must be one executable name or absolute normalized path")
    return target


def prior_evidence_path(value: str | None) -> str:
    """An explicit external report is an input, never a bundled historical default."""
    if not value:
        raise RunnerFailure("bounded-recheck requires LCVX_FAILURES_EVIDENCE")
    path = Path(value)
    try:
        if not path.is_absolute() or path.is_symlink() or not path.is_file() or path.resolve(strict=True) != path:
            raise RunnerFailure("LCVX_FAILURES_EVIDENCE must be an absolute physical regular file")
        report = decode_report(path.read_bytes(), 0, "prior-failures")
        validate_stage("failures", report)
    except OSError:
        raise RunnerFailure("LCVX_FAILURES_EVIDENCE is unavailable or unreadable") from None
    return str(path)


def digest(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def write_json(path: Path, value: object) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    temporary = path.with_suffix(path.suffix + ".tmp")
    temporary.write_text(json.dumps(value, indent=2, ensure_ascii=False) + "\n")
    temporary.replace(path)


def decode_report(raw: bytes, returncode: int, name: str) -> dict:
    try:
        report = json.loads(raw)
    except (ValueError, UnicodeError):
        raise RunnerFailure(f"{name}: invalid JSON (raw output withheld)") from None
    if not isinstance(report, dict):
        raise RunnerFailure(f"{name}: JSON object required")
    if returncode != 0:
        raise RunnerFailure(f"{name}: process exited {returncode}")
    return report


def require_true(value: object, name: str) -> None:
    if value is not True:
        raise RunnerFailure(f"{name}: cleanup or integrity flag is not true")


def validate_stage(name: str, report: dict) -> None:
    if report.get("result", report.get("status")) != "PASS":
        raise RunnerFailure(f"{name}: stage did not report PASS")
    if name in ("reader", "recover"):
        require_true(report.get("runner_runtime_removed"), name)
    elif name == "product-export":
        require_true(report.get("owned_temporary_removed"), name)
    elif name == "csv-cleanup":
        require_true(report.get("runtime_removed"), name)
    elif name == "admin":
        cleanup = report.get("cleanup", {})
        require_true(cleanup.get("owned_runtime_removed"), name)
        require_true(cleanup.get("database_transaction_closed"), name)
        if cleanup.get("persistent_users_roles_or_catalog_writes") is not False:
            raise RunnerFailure("admin: persistent writes were not excluded")
    elif name in ("queue", "failures"):
        require_true(report.get("owned_runtime_removed"), name)
        cleanup = report.get("owned_db_cleanup")
        expected = {"messages_removed", "message_statuses_removed", "notifications_removed", "locks_removed",
                    "queue_removed" if name == "queue" else "queues_removed"}
        if not isinstance(cleanup, dict) or not expected.issubset(cleanup):
            raise RunnerFailure(f"{name}: database cleanup evidence missing")
        for key, value in cleanup.items():
            require_true(value, f"{name}/{key}")
    elif name in ("fixtures", "performance-run", "catalog", "dense-performance"):
        require_true(report.get("owned_runtime_removed"), name)
    if name in ("fixtures", "recover", "dense-performance"):
        products = report.get("cleanup", {}).get("products")
        if not isinstance(products, (dict, list)) or not products:
            raise RunnerFailure(f"{name}: fixture cleanup manifest missing")
        for item in products.values() if isinstance(products, dict) else products:
            if item.get("status") not in ("deleted", "already_absent"):
                raise RunnerFailure(f"{name}: a fixture was not removed")


def select_stages(profile: str, prior_evidence: str | None = None) -> tuple[list[str], list[dict]]:
    if profile in ("all", "bounded-recheck"):
        stages = list(ALL_STAGES)
        omitted = [{"stage": stage, "result": "NOT_RUN", "reason": "Volume stages are excluded from this profile",
                    "coverage_claim": False} for stage in VOLUME_STAGES]
        if profile == "bounded-recheck":
            if not prior_evidence:
                raise RunnerFailure("bounded-recheck requires an explicit prior failure report")
            stages.remove("failures")
            omitted.append({"stage": "failures", "result": "NOT_RUN",
                            "reason": "Reuse explicitly supplied failure evidence only after complete inventory validation",
                            "prior_evidence": prior_evidence, "coverage_claim_in_this_run": False})
    else:
        stages, omitted = [profile], []
    if stages[0] != "preflight":
        stages.insert(0, "preflight")
    return stages, omitted


def source_files() -> list[tuple[Path, str]]:
    composer = PROJECT / "composer.json"
    if composer.is_symlink() or not composer.is_file():
        raise RunnerFailure("Candidate composer.json must be a regular file")
    files = [(composer, "project/composer.json")]
    for source, destination in ((PROJECT / "src/LeanCommerce/ProductVideoExport", "module"),
                                (PROJECT / "tests/integration", "tests"),
                                (PROJECT / "dev/probes", "probes")):
        for item in sorted(source.rglob("*")):
            relative = item.relative_to(source)
            if any(part.startswith("._") or part in (".DS_Store", "__pycache__") for part in relative.parts):
                continue
            if item.is_symlink():
                raise RunnerFailure("Source snapshot must not contain symlinks")
            if item.is_file():
                files.append((item, f"{destination}/{relative.as_posix()}"))
    return files


def validate_failure_inventory(evidence: dict, manifest: dict) -> int:
    """Require the whole execution-time M04 inventory, never upgrade historical subsets."""
    inventory = evidence.get("source_inventory")
    if (not isinstance(inventory, dict) or type(inventory.get("schema_version")) is not int
            or inventory["schema_version"] != 1 or inventory.get("scope") != M04_SCOPE
            or inventory.get("unchanged_after_execution") is not True
            or not isinstance(inventory.get("sha256"), dict)):
        raise RunnerFailure("M04 evidence lacks a complete verified execution-time inventory; rerun failures explicitly")
    current = {}
    seen = set()
    for item in manifest["files"]:
        source = item["source"]
        if source in seen:
            raise RunnerFailure("Candidate source inventory contains a duplicate path")
        seen.add(source)
        if source.startswith(M04_MODULE_ROOT) or source in M04_FILES:
            current[source] = item["sha256"]
    if not M04_FILES.issubset(current) or not any(path.startswith(M04_MODULE_ROOT) for path in current):
        raise RunnerFailure("Candidate is missing required M04 production/configuration or harness files")
    expected = inventory["sha256"]
    if current != expected:
        added = set(current) - set(expected)
        removed = set(expected) - set(current)
        changed = {path for path in set(current) & set(expected) if current[path] != expected[path]}
        raise RunnerFailure(f"M04 complete source inventory differs: {len(added)} added, {len(removed)} removed, "
                            f"{len(changed)} changed; do not repeat automatically")
    if any(not isinstance(value, str) or not re.fullmatch(r"[a-f0-9]{64}", value) for value in expected.values()):
        raise RunnerFailure("M04 source inventory contains an invalid SHA-256")
    return len(current)


def snapshot(directory: Path, run_id: str) -> dict:
    manifest = {"run_id": run_id, "captured_utc": dt.datetime.now(dt.timezone.utc).isoformat(), "files": []}
    for source, target in source_files():
        content = source.read_bytes()
        destination = directory / target
        destination.parent.mkdir(parents=True, exist_ok=True)
        destination.write_bytes(content)
        manifest["files"].append({"source": source.relative_to(PROJECT).as_posix(), "snapshot": target,
                                  "sha256": digest(content), "bytes": len(content)})
    current = {target: digest(source.read_bytes()) for source, target in source_files()}
    expected = {item["snapshot"]: item["sha256"] for item in manifest["files"]}
    if current != expected:
        raise RunnerFailure("Sources changed while creating the snapshot; retry after edits finish")
    write_json(directory / "manifest.json", manifest)
    return manifest


def tar_snapshot(directory: Path) -> bytes:
    output = io.BytesIO()
    with tarfile.open(fileobj=output, mode="w", format=tarfile.USTAR_FORMAT) as archive:
        for path in sorted(directory.rglob("*")):
            if path.is_file():
                data = path.read_bytes()
                info = tarfile.TarInfo(path.relative_to(directory).as_posix())
                info.size, info.mode, info.mtime = len(data), 0o600, 0
                archive.addfile(info, io.BytesIO(data))
    return output.getvalue()


class Runner:
    def __init__(self, stages: list[str], recovery: str | None, profile: str = "selected",
                 omitted: list[dict] | None = None, target: dict | None = None):
        self.target = target_config() if target is None else target
        now = dt.datetime.now(dt.timezone.utc)
        self.run_id = f"INT-{now:%Y%m%dT%H%M%SZ}-{secrets.token_hex(6)}"
        self.parent = PROJECT / f"internal/evidencia/integracion-{now:%Y%m%d}"
        self.evidence = self.parent / self.run_id
        self.candidate = f"{self.target['root']}/var/lcvx-product-video-export/candidates/{self.run_id}"
        self.token = secrets.token_hex(32)
        self.stages, self.recovery = stages, recovery
        self.sequence = 0
        self.interrupted = False
        self.summary = {
            "run_id": self.run_id, "started_utc": now.isoformat(), "result": "FAIL",
            "profile": profile, "requested_stages": stages, "omitted_stages": omitted or [],
            "coverage": "Only the executed stages are covered; this report does not establish complete acceptance or volume coverage",
            "target": self.target.copy(),
            "snapshot": {"path": self.candidate, "removed": False}, "stages": [],
            "limits": ["Native in-process integration checks; browser/login/download and installed consumer operation remain separate.",
                       "Performance is informative; completion, integrity and cleanup are mandatory.",
                       "Only the owned candidate is removed; fixture ledgers and shared resources are preserved."],
        }
        self.support = (PROJECT / "tests/integration/runner-support.php").read_bytes()
        self.env = {"LCVX_MAGENTO_ROOT": self.target["root"],
                    "LCVX_RUNNER_SUPPORT": self.candidate + "/tests/runner-support.php",
                    "LCVX_MODULE_ROOT": self.candidate + "/module",
                    "LCVX_CANDIDATE_COMPOSER": self.candidate + "/project/composer.json",
                    "LCVX_ISOLATED_BOOTSTRAP": self.candidate + "/tests/isolated-bootstrap.php"}

    def command(self, name: str, command: list[str], data: bytes | None = None) -> tuple[bytes, int]:
        self.sequence += 1
        started = time.monotonic()
        # Let the owned PHP process finish its finally block before deleting its source snapshot.
        # Killing only the docker client can leave the exec process running in the container.
        previous = {number: signal.getsignal(number) for number in (signal.SIGINT, signal.SIGTERM)}
        def defer_interrupt(_signum, _frame):
            self.interrupted = True
        for number in previous:
            signal.signal(number, defer_interrupt)
        try:
            result = subprocess.run(command, input=data, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                                    check=False, start_new_session=True)
            raw, error, code = result.stdout, result.stderr, result.returncode
        except OSError as failure:
            raw, error, code = b"", str(failure).encode(), 127
        finally:
            for number, handler in previous.items():
                signal.signal(number, handler)
        log = {"command": name, "exit_code": code, "elapsed_seconds": time.monotonic() - started,
               "stdout_bytes": len(raw), "stdout_sha256": digest(raw),
               "stderr_bytes": len(error), "stderr_sha256": digest(error),
               "raw_output_policy": "Stage JSON is stored separately; raw errors are withheld to avoid catalog/credential disclosure."}
        write_json(self.evidence / "logs" / f"{self.sequence:03}-{name}.json", log)
        if self.interrupted and name != "cleanup":
            raise KeyboardInterrupt("Interrupted after the active process completed; later stages were not started")
        return raw, code

    def docker(self, arguments: list[str], environment: dict | None = None) -> list[str]:
        command = ["docker", "exec", "-i", "-u", self.target["user"], "-w", self.target["root"]]
        supplied = (environment or {}) | {"LCVX_MAGENTO_ROOT": self.target["root"]}
        for key, value in sorted(supplied.items()):
            command.extend(("-e", f"{key}={value}"))
        return command + [self.target["container"]] + arguments

    def support_action(self, action: str) -> dict:
        raw, code = self.command(action, self.docker([self.target["php_binary"], "--", action, self.candidate, self.token]), self.support)
        report = decode_report(raw, code, action)
        if report.get("result") != "PASS":
            raise RunnerFailure(f"{action}: candidate operation failed")
        write_json(self.evidence / "snapshot" / f"{action}.json", report)
        return report

    def php_stage(self, name: str, environment: dict | None = None, arguments: list[str] | None = None,
                  report_name: str | None = None, validate_as: str | None = None) -> dict:
        label = report_name or name
        command = [self.target["php_binary"], self.candidate + "/" + SCRIPTS[name]] + (arguments or [])
        raw, code = self.command(label, self.docker(command, self.env | (environment or {})))
        # Preserve syntactically valid failure reports too; never label malformed output as evidence of PASS.
        parsed = None
        try:
            parsed = json.loads(raw)
            if isinstance(parsed, dict):
                write_json(self.evidence / "stages" / f"{label}.json", parsed)
        except (ValueError, UnicodeError):
            pass
        try:
            report = decode_report(raw, code, label)
            validate_stage(validate_as or name, report)
        except RunnerFailure as failure:
            if not isinstance(parsed, dict):
                write_json(self.evidence / "stages" / f"{label}.json", {
                    "result": "FAIL", "runner_failure": str(failure), "process_exit": code,
                    "stdout_bytes": len(raw), "stdout_sha256": digest(raw),
                })
            raise
        return report

    def preflight(self) -> dict:
        raw, code = self.command("preflight", self.docker([self.target["php_binary"], self.candidate + "/probes/preflight.php"], self.env))
        inventory = decode_report(raw, code, "preflight")
        capacity = self.support_action("capacity")
        report = {"stage": "preflight", "result": "FAIL", "inventory": inventory, "capacity": capacity,
                  "requirements": {
                      "php": "8.3", "database": "Native connection must answer read-only inventory queries",
                      "modules": ["Magento_CatalogImportExport", "Magento_ProductVideo", "Bss_CustomProductAttributeExport"],
                      "runtime": "Private local file cache/session/generated/lock paths; shared cache/search/HTTP not required",
                      "queue": "Native DB transport in own in-process queue; no shared/background consumer",
                      "capacity": "Recorded without invented thresholds; positive free disk/memory and successful completion required",
                  }}
        try:
            if not str(inventory.get("php", "")).startswith("8.3.") or not inventory.get("database"):
                raise RunnerFailure("preflight: PHP 8.3 or database unavailable")
            for module in report["requirements"]["modules"]:
                if inventory.get("modules", {}).get(module) != 1:
                    raise RunnerFailure(f"preflight: required module {module} unavailable")
            if capacity.get("disk_available_bytes", 0) <= 0 or capacity.get("memory", {}).get("MemAvailable_bytes", 0) <= 0:
                raise RunnerFailure("preflight: available disk or memory could not be established")
            if any(stage in self.stages for stage in ("queue", "failures")) and inventory.get("queue_transport") != "db":
                raise RunnerFailure("preflight: configured queue transport differs from the DB harness")
            report["result"] = "PASS"
        finally:
            write_json(self.evidence / "stages/preflight.json", report)
        return report

    def performance(self, local_snapshot: Path) -> dict:
        directory = self.evidence / "performance"
        for mode in ("bss", "all", "with", "without"):
            for iteration in ("warmup", "1", "2", "3"):
                report = self.php_stage("performance", {"LCVX_PERF_MODE": mode, "LCVX_PERF_RUN": iteration},
                                        report_name=f"performance-{mode}-{iteration}", validate_as="performance-run")
                write_json(directory / f"{mode}-{iteration}.json", report)
        raw, code = self.command("performance-summary", [sys.executable, str(local_snapshot / "tests/performance-summary.py"), str(directory)])
        report = decode_report(raw, code, "performance-summary")
        write_json(self.evidence / "stages/performance.json", report)
        validate_stage("performance", report)
        return report

    def validate_prior_evidence(self, manifest: dict) -> None:
        for omission in self.summary["omitted_stages"]:
            if "prior_evidence" not in omission:
                continue
            path = Path(prior_evidence_path(omission["prior_evidence"]))
            raw = path.read_bytes()
            evidence = decode_report(raw, 0, "prior-failures")
            validate_stage("failures", evidence)
            verified_count = validate_failure_inventory(evidence, manifest)
            omission["prior_evidence_sha256"] = digest(raw)
            omission["complete_source_inventory_matches_candidate"] = True
            omission["verified_inventory_scope"] = M04_SCOPE
            omission["source_files_verified"] = verified_count

    def run(self) -> int:
        attempted = False
        failed = True
        self.evidence.mkdir(parents=True, exist_ok=False)
        try:
            with tempfile.TemporaryDirectory(prefix="LCVX-integration-") as temporary:
                local = Path(temporary)
                manifest = snapshot(local, self.run_id)
                self.support = (local / "tests/runner-support.php").read_bytes()
                for stage in self.stages:
                    if not (local / SCRIPTS[stage]).is_file():
                        raise RunnerFailure(f"{stage}: stage script is not available")
                write_json(self.evidence / "source-manifest.json", manifest)
                self.validate_prior_evidence(manifest)
                self.summary["snapshot"]["manifest_sha256"] = digest((local / "manifest.json").read_bytes())
                self.summary["snapshot"]["module_files"] = sum(item["snapshot"].startswith("module/") for item in manifest["files"])
                attempted = True
                self.support_action("create")
                raw, code = self.command("copy-snapshot", self.docker(["tar", "-xf", "-", "-C", self.candidate]), tar_snapshot(local))
                if code != 0:
                    raise RunnerFailure("Could not copy candidate snapshot")
                verified = self.support_action("verify")
                if verified.get("manifest_sha256") != self.summary["snapshot"]["manifest_sha256"]:
                    raise RunnerFailure("Remote manifest differs from the local source manifest")
                for stage in self.stages:
                    print(f"Integration {self.run_id}: {stage}", file=sys.stderr, flush=True)
                    stage_path = self.evidence / "stages" / f"{stage}.json"
                    entry = {"stage": stage, "result": "FAIL", "report": str(stage_path.relative_to(PROJECT))}
                    self.summary["stages"].append(entry)
                    write_json(stage_path, {"stage": stage, "result": "FAIL", "runner_status": "No completed valid stage report yet"})
                    started = time.monotonic()
                    if stage == "preflight":
                        report = self.preflight()
                    elif stage == "performance":
                        report = self.performance(local)
                    else:
                        report = self.php_stage(stage, arguments=[self.recovery] if stage == "recover" else None)
                    if stage == "failures":
                        entry["complete_source_files_verified"] = validate_failure_inventory(report, manifest)
                    entry.update(result="PASS", seconds=time.monotonic() - started,
                                 report=str((self.evidence / "stages" / f"{stage}.json").relative_to(PROJECT)))
                    if stage in ("fixtures", "dense-performance"):
                        entry["fixture_run_id"] = report.get("run_id")
                final_verify = self.support_action("verify")
                if final_verify.get("manifest_sha256") != self.summary["snapshot"]["manifest_sha256"]:
                    raise RunnerFailure("Snapshot manifest changed during execution")
                self.summary["snapshot"]["unchanged_after_stages"] = True
                failed = False
        except (RunnerFailure, OSError, ValueError, KeyboardInterrupt) as error:
            self.summary["failure"] = str(error) or "Interrupted; run is not accepted"
        finally:
            if attempted:
                try:
                    cleanup = self.support_action("cleanup")
                    require_true(cleanup.get("candidate_removed"), "candidate")
                    self.summary["snapshot"]["removed"] = True
                except (RunnerFailure, OSError, ValueError) as error:
                    self.summary["cleanup_failure"] = str(error)
                    failed = True
            else:
                self.summary["snapshot"]["not_created"] = True
            failed = failed or self.interrupted
            self.summary["interrupted"] = self.interrupted
            self.summary["result"] = "FAIL" if failed else "PASS"
            self.summary["finished_utc"] = dt.datetime.now(dt.timezone.utc).isoformat()
            path = self.parent / f"run-{self.run_id}.json"
            self.summary["evidence"] = str(path.relative_to(PROJECT))
            write_json(path, self.summary)
            print(json.dumps(self.summary, indent=2, ensure_ascii=False))
        return int(failed)


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("stage", nargs="?", default="all",
                        choices=("all", "bounded-recheck", *ALL_STAGES, *VOLUME_STAGES, "recover"),
                        help="all excludes volume; bounded-recheck also requires explicit verified failure evidence")
    parser.add_argument("fixture_run_id", nargs="?")
    args = parser.parse_args()
    if args.stage == "recover":
        if not args.fixture_run_id or not re.fullmatch(r"IT-[a-zA-Z0-9-]{4,36}", args.fixture_run_id):
            parser.error("recover requires a recorded IT- fixture run ID")
    elif args.fixture_run_id is not None:
        parser.error("a fixture run ID is accepted only by recover")
    try:
        target = target_config()
        prior = prior_evidence_path(os.environ.get("LCVX_FAILURES_EVIDENCE")) if args.stage == "bounded-recheck" else None
        stages, omitted = select_stages(args.stage, prior)
    except RunnerFailure as failure:
        print(json.dumps({"result": "FAIL", "message": str(failure), "destination_touched": False}))
        return 2
    lock_name = digest(str(PROJECT).encode())[:20]
    lock_path = Path(tempfile.gettempdir()) / f"LCVX-integration-{lock_name}.lock"
    with lock_path.open("a+") as lock:
        try:
            fcntl.flock(lock.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            print(json.dumps({"result": "FAIL", "message": "Another local integration runner holds the lock", "destination_touched": False}))
            return 2
        def interrupt(_signum, _frame):
            raise KeyboardInterrupt("Interrupted; exact candidate cleanup follows")
        signal.signal(signal.SIGTERM, interrupt)
        return Runner(stages, args.fixture_run_id, args.stage, omitted, target=target).run()


if __name__ == "__main__":
    raise SystemExit(main())
