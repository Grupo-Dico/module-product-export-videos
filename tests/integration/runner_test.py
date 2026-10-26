#!/usr/bin/env python3
"""Local negative-path checks for the runner; no Docker or Magento connection is made."""
import contextlib
import copy
import importlib.util
import io
import json
import os
import shlex
import shutil
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

SPEC = importlib.util.spec_from_file_location("lcvx_runner", Path(__file__).with_name("runner.py"))
runner = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(runner)


TEST_ENV = {"LCVX_MAGENTO_ROOT": "/srv/magento", "LCVX_CONTAINER": "magento-test",
            "LCVX_CONTAINER_USER": "1000:1000", "LCVX_PHP_BINARY": "/usr/bin/php8.3"}
TEST_TARGET = runner.target_config(TEST_ENV)


class TargetConfiguration(unittest.TestCase):
    def test_explicit_configuration_is_preserved_in_docker_arguments(self):
        subject = runner.Runner([], None, target=TEST_TARGET)
        command = subject.docker([TEST_TARGET["php_binary"], "--", "capacity"], {"LCVX_MODULE_ROOT": "/own/module"})
        self.assertEqual(["docker", "exec", "-i", "-u", "1000:1000", "-w", "/srv/magento"], command[:7])
        self.assertIn("LCVX_MAGENTO_ROOT=/srv/magento", command)
        self.assertIn("LCVX_MODULE_ROOT=/own/module", command)
        self.assertEqual(["magento-test", "/usr/bin/php8.3", "--", "capacity"], command[-4:])

    def test_missing_or_invalid_configuration_stops_before_commands_or_output(self):
        invalid = [(key, None) for key in TEST_ENV]
        invalid += [("LCVX_MAGENTO_ROOT", value) for value in ("", "relative", "/", "/srv/../magento", "/srv/./magento", "/srv//magento", "/srv/magento/", "/srv/magento;cmd")]
        invalid += [("LCVX_CONTAINER", "--privileged"), ("LCVX_CONTAINER_USER", "1000 --user=root"),
                    ("LCVX_PHP_BINARY", "php -r code"), ("LCVX_PHP_BINARY", "-d"),
                    ("LCVX_PHP_BINARY", "/usr/../bin/php")]
        with tempfile.TemporaryDirectory(prefix="LCVX-target-config-") as temporary:
            root = Path(temporary)
            for key, value in invalid:
                environment = TEST_ENV.copy()
                if value is None:
                    del environment[key]
                else:
                    environment[key] = value
                with self.subTest(setting=key, value=value), patch.dict(os.environ, environment, clear=True), \
                     patch.object(runner, "PROJECT", root), patch.object(runner.sys, "argv", ["runner", "preflight"]), \
                     patch.object(runner.subprocess, "run") as command, contextlib.redirect_stdout(io.StringIO()) as output:
                    self.assertEqual(2, runner.main())
                    command.assert_not_called()
                    self.assertEqual([], list(root.iterdir()))
                    self.assertFalse(json.loads(output.getvalue())["destination_touched"])

    def test_bounded_profile_requires_existing_explicit_report_before_commands(self):
        for value in (None, "relative.json", "/nonexistent/lcvx-prior.json"):
            environment = TEST_ENV.copy()
            if value is not None:
                environment["LCVX_FAILURES_EVIDENCE"] = value
            with self.subTest(value=value), tempfile.TemporaryDirectory(prefix="LCVX-prior-input-") as temporary, \
                 patch.dict(os.environ, environment, clear=True), patch.object(runner, "PROJECT", Path(temporary)), \
                 patch.object(runner.sys, "argv", ["runner", "bounded-recheck"]), \
                 patch.object(runner.subprocess, "run") as command, contextlib.redirect_stdout(io.StringIO()) as output:
                self.assertEqual(2, runner.main())
                command.assert_not_called()
                self.assertEqual([], list(Path(temporary).iterdir()))
                self.assertFalse(json.loads(output.getvalue())["destination_touched"])

    def test_external_report_must_be_physical_regular_valid_and_successful(self):
        with tempfile.TemporaryDirectory(prefix="LCVX-prior-report-") as temporary:
            root = Path(temporary).resolve()
            report = root / "prior.json"
            success = {"result": "PASS", "owned_runtime_removed": True,
                       "owned_db_cleanup": {key: True for key in ("queues_removed", "messages_removed",
                           "message_statuses_removed", "notifications_removed", "locks_removed")}}
            report.write_text(json.dumps(success))
            self.assertEqual(str(report), runner.prior_evidence_path(str(report)))
            alias = root / "alias.json"
            alias.symlink_to(report)
            for path in (alias, root):
                with self.subTest(path=path), self.assertRaises(runner.RunnerFailure):
                    runner.prior_evidence_path(str(path))
            for contents in ("not json", json.dumps(success | {"result": "FAIL"}),
                             json.dumps(success | {"owned_runtime_removed": False})):
                report.write_text(contents)
                with self.subTest(contents=contents), self.assertRaises(runner.RunnerFailure):
                    runner.prior_evidence_path(str(report))

    def test_php_root_guard_rejects_redirection_without_bootstrapping(self):
        php = shutil.which("php")
        self.assertIsNotNone(php, "Use the PHP 8.3 tooling runtime for the filesystem guard test")
        support = Path(__file__).with_name("runner-support.php")
        code = "define('LCVX_RUNNER_SUPPORT_LIBRARY_ONLY',true); require $argv[1]; echo lcvxIntegrationRoot();"
        with tempfile.TemporaryDirectory(prefix="LCVX-php-root-") as temporary:
            base = Path(temporary).resolve()
            root = base / "magento"
            for relative in ("app/bootstrap.php", "app/etc/env.php", "app/etc/config.php"):
                path = root / relative
                path.parent.mkdir(parents=True, exist_ok=True)
                path.write_text("<?php throw new RuntimeException('Must never bootstrap.');")
            (root / "var").mkdir()
            def invoke(value):
                environment = os.environ.copy()
                environment.pop("LCVX_MAGENTO_ROOT", None)
                if value is not None:
                    environment["LCVX_MAGENTO_ROOT"] = value
                return runner.subprocess.run([php, "-r", code, str(support)],
                    env=environment, capture_output=True, check=False)
            valid = invoke(str(root))
            self.assertEqual(0, valid.returncode)
            self.assertEqual(str(root), valid.stdout.decode())
            alias = base / "alias"
            alias.symlink_to(root, target_is_directory=True)
            for value in (None, str(alias), str(root / "missing"), str(root) + "/../magento"):
                with self.subTest(value=value):
                    self.assertNotEqual(0, invoke(value).returncode)
            (root / "var").rmdir()
            (root / "var").symlink_to(base, target_is_directory=True)
            self.assertNotEqual(0, invoke(str(root)).returncode)
            (root / "var").unlink()
            (root / "var").mkdir()
            (root / "app/etc/env.php").unlink()
            self.assertNotEqual(0, invoke(str(root)).returncode)


class EvidenceContracts(unittest.TestCase):
    def test_rejects_invalid_json_even_with_success_exit(self):
        with self.assertRaises(runner.RunnerFailure):
            runner.decode_report(b"PHP Fatal error", 0, "probe")

    def test_rejects_nonzero_exit_even_with_pass_report(self):
        with self.assertRaises(runner.RunnerFailure):
            runner.decode_report(b'{"result":"PASS"}', 1, "probe")

    def test_rejects_nonobject_json(self):
        with self.assertRaises(runner.RunnerFailure):
            runner.decode_report(b"[]", 0, "probe")

    def test_missing_cleanup_cannot_pass(self):
        with self.assertRaises(runner.RunnerFailure):
            runner.validate_stage("queue", {"result": "PASS", "owned_runtime_removed": True})

    def test_false_or_string_cleanup_cannot_pass(self):
        for value in (False, "true", 1):
            with self.subTest(value=value), self.assertRaises(runner.RunnerFailure):
                runner.validate_stage("performance-run", {"result": "PASS", "owned_runtime_removed": value})

    def test_fixture_leftovers_cannot_pass(self):
        for stage in ("fixtures", "recover", "dense-performance"):
            with self.subTest(stage=stage), self.assertRaises(runner.RunnerFailure):
                runner.validate_stage(stage, {"result": "PASS", "owned_runtime_removed": True,
                    "runner_runtime_removed": True, "cleanup": {"products": {"own": {"status": "failed"}}}})

    def test_expected_absence_of_admin_writes_is_valid(self):
        runner.validate_stage("admin", {"status": "PASS", "cleanup": {"owned_runtime_removed": True,
            "database_transaction_closed": True, "persistent_users_roles_or_catalog_writes": False}})

    def test_queue_rollback_is_required_for_every_resource(self):
        with self.assertRaises(runner.RunnerFailure):
            runner.validate_stage("queue", {"result": "PASS", "owned_runtime_removed": True,
                "owned_db_cleanup": {"queue_removed": True, "message_statuses_removed": True,
                    "messages_removed": True, "notifications_removed": True, "locks_removed": False}})

    def test_invalid_cli_arguments_never_invoke_docker(self):
        with tempfile.TemporaryDirectory(prefix="LCVX-runner-cli-") as temporary:
            directory = Path(temporary)
            marker = directory / "docker-called"
            executable = directory / "docker"
            executable.write_text("#!/bin/sh\nprintf called > " + shlex.quote(str(marker)) + "\nexit 99\n")
            executable.chmod(0o700)
            environment = os.environ | {"PATH": str(directory) + os.pathsep + os.environ["PATH"],
                                       "PYTHONDONTWRITEBYTECODE": "1"}
            for arguments in (["invalid-stage"], ["recover", "../unowned"]):
                with self.subTest(arguments=arguments):
                    result = runner.subprocess.run([str(Path(__file__).with_name("run.sh")), *arguments],
                        env=environment, capture_output=True, check=False)
                    self.assertEqual(2, result.returncode)
                    self.assertFalse(marker.exists())

    def test_all_explicitly_defers_volume_tests(self):
        stages, omitted = runner.select_stages("all")
        self.assertIn("failures", stages)
        self.assertFalse(set(runner.VOLUME_STAGES) & set(stages))
        self.assertEqual(set(runner.VOLUME_STAGES), {item["stage"] for item in omitted})
        self.assertTrue(all(item["result"] == "NOT_RUN" and "excluded" in item["reason"] for item in omitted))

    def test_bounded_recheck_does_not_repeat_failures_or_volume(self):
        stages, omitted = runner.select_stages("bounded-recheck", "/operator/prior-failures.json")
        self.assertNotIn("failures", stages)
        self.assertFalse(set(runner.VOLUME_STAGES) & set(stages))
        self.assertEqual({"failures", *runner.VOLUME_STAGES}, {item["stage"] for item in omitted})

    def test_volume_stages_remain_individually_selectable_for_future_authorization(self):
        for stage in runner.VOLUME_STAGES:
            with self.subTest(stage=stage):
                self.assertEqual((["preflight", stage], []), runner.select_stages(stage))


class CompleteFailureInventory(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix="LCVX-m04-inventory-")
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name).resolve()
        self.patch = patch.object(runner, "PROJECT", self.root)
        self.patch.start()
        self.addCleanup(self.patch.stop)
        self.paths = {
            "src/LeanCommerce/ProductVideoExport/registration.php": "module/registration.php",
            "src/LeanCommerce/ProductVideoExport/Model/ProductExport.php": "module/Model/ProductExport.php",
            "src/LeanCommerce/ProductVideoExport/Model/ExportExecutionContext.php": "module/Model/ExportExecutionContext.php",
            "src/LeanCommerce/ProductVideoExport/Model/CsvCleanupRegistry.php": "module/Model/CsvCleanupRegistry.php",
            "src/LeanCommerce/ProductVideoExport/etc/di.xml": "module/etc/di.xml",
            "src/LeanCommerce/ProductVideoExport/i18n/es_MX.csv": "module/i18n/es_MX.csv",
            "composer.json": "project/composer.json",
            "tests/integration/failures.php": "tests/failures.php",
            "tests/integration/FailureHarness.php": "tests/FailureHarness.php",
            "tests/integration/runner-support.php": "tests/runner-support.php",
        }
        self.hashes = {path: runner.digest(path.encode()) for path in self.paths}
        self.manifest = {"files": [{"source": path, "snapshot": target, "sha256": self.hashes[path]}
                                   for path, target in self.paths.items()]}
        # The historical fields deliberately cover only a subset, reproducing the old gate.
        self.report = {"result": "PASS", "owned_runtime_removed": True,
                       "owned_db_cleanup": {name: True for name in
                           ("queues_removed", "message_statuses_removed", "messages_removed", "notifications_removed", "locks_removed")},
                       "source_sha256": {"Model/ProductExport.php": self.hashes["src/LeanCommerce/ProductVideoExport/Model/ProductExport.php"]},
                       "test_sha256": {path: self.hashes[path] for path in self.hashes if path.startswith("tests/")},
                       "source_inventory": {"schema_version": 1, "scope": "m04-production-and-harness-v1",
                           "sha256": self.hashes.copy(), "unchanged_after_execution": True}}
        support = self.root / "tests/integration/runner-support.php"
        support.parent.mkdir(parents=True)
        support.write_text("<?php // not executed")
        self.evidence = self.root / "prior.json"
        self.save_report()
        self.subject = runner.Runner([], None, omitted=[{"stage": "failures", "prior_evidence": str(self.evidence)}], target=TEST_TARGET)

    def save_report(self):
        self.evidence.write_text(json.dumps(self.report))

    def test_identical_complete_inventory_is_reusable(self):
        self.subject.validate_prior_evidence(self.manifest)

    def test_prior_report_without_shared_helper_is_rejected(self):
        del self.report["source_inventory"]["sha256"]["tests/integration/runner-support.php"]
        self.save_report()
        with self.assertRaises(runner.RunnerFailure):
            self.subject.validate_prior_evidence(self.manifest)

    def test_missing_shared_helper_on_both_sides_is_rejected(self):
        path = "tests/integration/runner-support.php"
        del self.report["source_inventory"]["sha256"][path]
        self.save_report()
        manifest = copy.deepcopy(self.manifest)
        manifest["files"] = [item for item in manifest["files"] if item["source"] != path]
        with self.assertRaises(runner.RunnerFailure):
            self.subject.validate_prior_evidence(manifest)

    def test_change_to_every_recorded_source_is_rejected(self):
        for path in self.paths:
            with self.subTest(path=path):
                manifest = copy.deepcopy(self.manifest)
                next(item for item in manifest["files"] if item["source"] == path)["sha256"] = "0" * 64
                with self.assertRaises(runner.RunnerFailure):
                    self.subject.validate_prior_evidence(manifest)

    def test_deletion_of_every_recorded_source_is_rejected(self):
        for path in self.paths:
            with self.subTest(path=path):
                manifest = copy.deepcopy(self.manifest)
                manifest["files"] = [item for item in manifest["files"] if item["source"] != path]
                with self.assertRaises(runner.RunnerFailure):
                    self.subject.validate_prior_evidence(manifest)

    def test_new_production_php_xml_and_translation_files_are_rejected(self):
        for suffix in ("Model/NewClass.php", "etc/events.xml", "i18n/en_US.csv"):
            with self.subTest(path=suffix):
                manifest = copy.deepcopy(self.manifest)
                manifest["files"].append({"source": "src/LeanCommerce/ProductVideoExport/" + suffix,
                                          "snapshot": "module/" + suffix, "sha256": "1" * 64})
                with self.assertRaises(runner.RunnerFailure):
                    self.subject.validate_prior_evidence(manifest)

    def test_historical_subset_without_execution_inventory_is_rejected(self):
        del self.report["source_inventory"]
        self.save_report()
        with self.assertRaises(runner.RunnerFailure):
            self.subject.validate_prior_evidence(self.manifest)

    def test_incomplete_or_extra_historical_inventory_is_rejected(self):
        original = copy.deepcopy(self.report)
        for action in ("missing", "extra"):
            with self.subTest(action=action):
                self.report = copy.deepcopy(original)
                hashes = self.report["source_inventory"]["sha256"]
                if action == "missing":
                    del hashes["src/LeanCommerce/ProductVideoExport/Model/CsvCleanupRegistry.php"]
                else:
                    hashes["src/LeanCommerce/ProductVideoExport/Model/Removed.php"] = "2" * 64
                self.save_report()
                with self.assertRaises(runner.RunnerFailure):
                    self.subject.validate_prior_evidence(self.manifest)

    def test_execution_inventory_requires_supported_schema_scope_and_final_verification(self):
        original = copy.deepcopy(self.report)
        for key, value in (("schema_version", 0), ("scope", "subset"), ("unchanged_after_execution", False)):
            with self.subTest(key=key):
                self.report = copy.deepcopy(original)
                self.report["source_inventory"][key] = value
                self.save_report()
                with self.assertRaises(runner.RunnerFailure):
                    self.subject.validate_prior_evidence(self.manifest)

    def test_duplicate_candidate_source_cannot_hide_a_change(self):
        manifest = copy.deepcopy(self.manifest)
        manifest["files"].append(copy.deepcopy(manifest["files"][0]))
        with self.assertRaises(runner.RunnerFailure):
            self.subject.validate_prior_evidence(manifest)

    def test_unrelated_fixture_changes_do_not_invalidate_m04(self):
        manifest = copy.deepcopy(self.manifest)
        manifest["files"].append({"source": "tests/integration/FixtureFactory.php",
                                  "snapshot": "tests/FixtureFactory.php", "sha256": "3" * 64})
        self.subject.validate_prior_evidence(manifest)


class RunLifecycle(unittest.TestCase):
    def run_fake(self, fail_stage=False, fail_cleanup=False):
        with tempfile.TemporaryDirectory(prefix="LCVX-runner-test-") as temporary:
            root = Path(temporary)
            for name, data in {
                "composer.json": b'{"name":"test/fixture"}\n',
                "src/LeanCommerce/ProductVideoExport/registration.php": b"<?php // snapshot\n",
                "tests/integration/runner-support.php": b"<?php // mock\n",
                "tests/integration/fixtures.php": b"<?php // mock\n",
                "tests/integration/queue.php": b"<?php // mock\n",
                "dev/probes/preflight.php": b"<?php // mock\n",
            }.items():
                path = root / name
                path.parent.mkdir(parents=True, exist_ok=True)
                path.write_bytes(data)
            calls = []
            with patch.object(runner, "PROJECT", root):
                subject = runner.Runner(["preflight", "fixtures", "queue"], None, target=TEST_TARGET)
                def support(action):
                    calls.append(action)
                    if action == "cleanup" and fail_cleanup:
                        raise runner.RunnerFailure("simulated cleanup failure")
                    return {"result": "PASS", "candidate_removed": True,
                            "manifest_sha256": subject.summary["snapshot"].get("manifest_sha256")}
                def stage(name, **_kwargs):
                    calls.append(name)
                    if fail_stage and name == "fixtures":
                        raise runner.RunnerFailure("simulated fixture failure")
                    return {"result": "PASS"}
                with patch.object(subject, "support_action", side_effect=support), \
                     patch.object(subject, "command", return_value=(b"", 0)), \
                     patch.object(subject, "preflight", return_value={"result": "PASS"}), \
                     patch.object(subject, "php_stage", side_effect=stage), \
                     contextlib.redirect_stdout(io.StringIO()), contextlib.redirect_stderr(io.StringIO()):
                    result = subject.run()
                canonical = json.loads((subject.parent / f"run-{subject.run_id}.json").read_text())
                return result, calls, canonical

    def test_first_failure_stops_later_stages_and_cleans_candidate(self):
        code, calls, summary = self.run_fake(fail_stage=True)
        self.assertEqual(1, code)
        self.assertNotIn("queue", calls)
        self.assertEqual("cleanup", calls[-1])
        self.assertTrue(summary["snapshot"]["removed"])
        self.assertEqual("FAIL", summary["result"])

    def test_cleanup_failure_makes_successful_stages_fail(self):
        code, calls, summary = self.run_fake(fail_cleanup=True)
        self.assertEqual(1, code)
        self.assertIn("queue", calls)
        self.assertEqual("FAIL", summary["result"])
        self.assertFalse(summary["snapshot"]["removed"])

    def test_success_requires_final_verification_and_cleanup(self):
        code, calls, summary = self.run_fake()
        self.assertEqual(0, code)
        self.assertEqual(["verify", "cleanup"], calls[-2:])
        self.assertEqual("PASS", summary["result"])

    def test_interrupt_waits_for_the_active_process_before_cleanup(self):
        with tempfile.TemporaryDirectory(prefix="LCVX-runner-test-") as temporary:
            subject = runner.Runner(["preflight"], None, target=TEST_TARGET)
            subject.evidence = Path(temporary)
            def completed_process(*_args, **kwargs):
                self.assertTrue(kwargs["start_new_session"])
                runner.signal.getsignal(runner.signal.SIGTERM)(runner.signal.SIGTERM, None)
                return runner.subprocess.CompletedProcess([], 0, b'{"result":"PASS"}', b"")
            with patch.object(runner.subprocess, "run", side_effect=completed_process):
                with self.assertRaises(KeyboardInterrupt):
                    subject.command("active-stage", ["mock-only"])
                # Cleanup is still allowed after the deferred interruption.
                raw, code = subject.command("cleanup", ["mock-only"])
                self.assertEqual(0, code)
                self.assertTrue(subject.interrupted)
                self.assertTrue((subject.evidence / "logs/001-active-stage.json").is_file())


if __name__ == "__main__":
    unittest.main()
