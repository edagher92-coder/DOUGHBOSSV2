"""Black-box tests for validate_media_zip.py using only copied tracked media bytes."""

from __future__ import annotations

import json
import os
from pathlib import Path
import shutil
import struct
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch
import zipfile


HERE = Path(__file__).resolve().parent
VALIDATOR = HERE / "validate_media_zip.py"
SOURCE = HERE / "media-plugin"
PREFIX = "doughboss-growth-media/"
MAX_ARCHIVE_BYTES = 1_900_000


def source_entries(source: Path) -> list[tuple[str, bytes]]:
    return [
        (PREFIX + path.relative_to(source).as_posix(), path.read_bytes())
        for path in sorted(source.rglob("*"))
        if path.is_file() and not path.is_symlink()
    ]


def write_archive(
    output: Path,
    source: Path,
    *,
    remove: set[str] | None = None,
    extras: list[tuple[str, bytes]] | None = None,
    replacements: dict[str, bytes] | None = None,
    rename_root: bool = False,
    metadata: dict[str, tuple[int, int]] | None = None,
) -> None:
    remove = remove or set()
    extras = extras or []
    replacements = replacements or {}
    metadata = metadata or {}
    with zipfile.ZipFile(output, "w") as archive:
        for name, body in source_entries(source):
            if name in remove:
                continue
            if rename_root:
                name = "wrong-root/" + name[len(PREFIX) :]
            body = replacements.get(name, body)
            info = zipfile.ZipInfo(name, date_time=(2026, 1, 1, 0, 0, 0))
            info.create_system = 3
            info.external_attr = 0o100644 << 16
            if name in metadata:
                info.create_system, info.external_attr = metadata[name]
            info.compress_type = (
                zipfile.ZIP_STORED
                if name.endswith((".avif", ".webp", ".jpg", ".png", ".mp4"))
                else zipfile.ZIP_DEFLATED
            )
            archive.writestr(info, body)
        for name, body in extras:
            info = zipfile.ZipInfo(name, date_time=(2026, 1, 1, 0, 0, 0))
            info.create_system = 3
            info.external_attr = 0o100644 << 16
            info.compress_type = zipfile.ZIP_DEFLATED
            archive.writestr(info, body)


def set_first_entry_encrypted_bit(path: Path) -> None:
    """Make a complete-payload ZIP advertise encryption without needing an encrypted writer."""
    raw = bytearray(path.read_bytes())
    local = raw.find(b"PK\x03\x04")
    central = raw.find(b"PK\x01\x02")
    if local < 0 or central < 0:
        raise AssertionError("fixture has no ZIP local/central headers")
    for offset in (local + 6, central + 8):
        flags = struct.unpack_from("<H", raw, offset)[0]
        struct.pack_into("<H", raw, offset, flags | 0x1)
    path.write_bytes(raw)


class ValidateMediaZipTests(unittest.TestCase):
    def setUp(self) -> None:
        self.temp = tempfile.TemporaryDirectory(prefix="dbgr-media-validate-")
        self.work = Path(self.temp.name)

    def tearDown(self) -> None:
        self.temp.cleanup()

    def archive(self, name: str, **kwargs: object) -> Path:
        result = self.work / name
        write_archive(result, SOURCE, **kwargs)
        return result

    def run_validator(self, archive: Path, source: Path = SOURCE) -> subprocess.CompletedProcess[str]:
        return subprocess.run(
            [sys.executable, str(VALIDATOR), str(archive), str(source)],
            check=False,
            text=True,
            capture_output=True,
        )

    def assert_rejected(self, archive: Path, expected: str, source: Path = SOURCE) -> None:
        result = self.run_validator(archive, source)
        self.assertNotEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertIn(expected, result.stderr)

    def test_accepts_complete_canonical_payload(self) -> None:
        result = self.run_validator(self.archive("valid.zip"))
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertIn("VALID version=0.2.0 files=44", result.stdout)
        self.assertIn("current-source parity only", result.stdout)

    def test_refuses_complete_payload_name_and_byte_variations(self) -> None:
        base_entries = source_entries(SOURCE)
        readme_name = PREFIX + "readme.txt"
        readme_body = dict(base_entries)[readme_name]
        cases: list[tuple[str, Path, str]] = []
        cases.append(("missing", self.archive("missing.zip", remove={readme_name}), "file set differs"))
        cases.append(
            (
                "extra",
                self.archive("extra.zip", extras=[(PREFIX + "assets/box/extra.txt", b"extra")]),
                "unexpected archive entry",
            )
        )
        cases.append(("wrong-root", self.archive("wrong-root.zip", rename_root=True), "unsafe or noncanonical"))
        cases.append(
            (
                "traversal",
                self.archive("traversal.zip", extras=[(PREFIX + "assets/box/../secret.txt", b"no")]),
                "hidden, directory, traversal",
            )
        )
        cases.append(
            (
                "duplicate",
                self.archive("duplicate.zip", extras=[(readme_name, readme_body)]),
                "duplicate archive entry",
            )
        )
        cases.append(
            (
                "case-collision",
                self.archive("case.zip", extras=[(PREFIX + "README.TXT", readme_body)]),
                "case-colliding archive entry",
            )
        )
        changed = bytearray(readme_body)
        changed[0] ^= 1
        cases.append(
            (
                "edited-bytes",
                self.archive("edited.zip", replacements={readme_name: bytes(changed)}),
                "CRC mismatch before read",
            )
        )
        for label, archive, expected in cases:
            with self.subTest(label=label):
                self.assert_rejected(archive, expected)

    def test_refuses_oversize_before_opening(self) -> None:
        archive = self.archive("oversize.zip")
        with archive.open("ab") as stream:
            stream.truncate(MAX_ARCHIVE_BYTES + 1)
        self.assert_rejected(archive, "byte budget before opening")

    def test_refuses_archive_stale_against_current_source(self) -> None:
        stale_source = self.work / "source-newer-than-archive"
        shutil.copytree(SOURCE, stale_source)
        readme = stale_source / "readme.txt"
        readme.write_bytes(readme.read_bytes() + b"\n")
        self.assert_rejected(self.archive("stale.zip"), "byte-size mismatch before read", stale_source)

    def test_refuses_matching_source_and_archive_with_changed_truth_labels(self) -> None:
        box_path = Path("assets/box/manifest.json")
        hero_path = Path("assets/hero/hero.json")
        box = json.loads((SOURCE / box_path).read_text(encoding="utf-8"))
        cases = []
        for slot_name, slot in box["slots"].items():
            cases.append(("status-" + slot_name, box_path, slot_name, "status",
                          "real" if slot["status"] == "concept" else "concept", "box concept/AI labels"))
            cases.append(("ai-" + slot_name, box_path, slot_name, "ai_food", True, "box concept/AI labels"))
        cases.extend([
            ("hero-real", hero_path, None, "status", "real", "hero concept/AI labels"),
            ("hero-no-ai", hero_path, None, "ai_food", False, "hero concept/AI labels"),
            ("hero-empty-owner", hero_path, None, "owner_decision", "", "owner decision record"),
            ("hero-blank-owner", hero_path, None, "owner_decision", " \t", "owner decision record"),
            ("hero-missing-owner", hero_path, None, "owner_decision", None, "owner decision record"),
        ])
        for label, relative, slot_name, key, value, expected in cases:
            with self.subTest(label=label):
                changed_source = self.work / label
                shutil.copytree(SOURCE, changed_source)
                manifest = changed_source / relative
                data = json.loads(manifest.read_text(encoding="utf-8"))
                target = data["slots"][slot_name] if slot_name is not None else data
                if value is None:
                    target.pop(key)
                else:
                    target[key] = value
                manifest.write_text(json.dumps(data), encoding="utf-8")
                archive = self.work / (label + ".zip")
                write_archive(archive, changed_source)
                # Both sides match: rejection must be for disclosure policy, not parity.
                self.assert_rejected(archive, expected, changed_source)

    def test_refuses_complete_payload_metadata_variations(self) -> None:
        target = PREFIX + "readme.txt"
        cases = {
            "wrong-mode": (3, 0o100600 << 16, "Unix regular 0o100644"),
            "symlink": (3, 0o120777 << 16, "Unix regular 0o100644"),
            "fifo": (3, 0o010644 << 16, "Unix regular 0o100644"),
            "directory": (3, 0o040755 << 16, "Unix regular 0o100644"),
            "dos-creator": (0, 0o100644 << 16, "creator platform"),
        }
        for label, (creator, attributes, expected) in cases.items():
            with self.subTest(label=label):
                archive = self.archive(label + ".zip", metadata={target: (creator, attributes)})
                self.assert_rejected(archive, expected)
        encrypted = self.archive("encrypted.zip")
        set_first_entry_encrypted_bit(encrypted)
        self.assert_rejected(encrypted, "encrypted archive entry")

    def test_refuses_extra_or_missing_source_files(self) -> None:
        source_copy = self.work / "source-extra"
        shutil.copytree(SOURCE, source_copy)
        (source_copy / "assets" / "box" / "unlisted.txt").write_bytes(b"extra")
        self.assert_rejected(self.archive("valid-extra-source.zip"), "source file set", source_copy)

        missing_copy = self.work / "source-missing"
        shutil.copytree(SOURCE, missing_copy)
        (missing_copy / "assets" / "hero" / "dbgr-hero-poster-720.webp").unlink()
        self.assert_rejected(self.archive("valid-missing-source.zip"), "source file set", missing_copy)

    def test_refuses_complete_payload_with_nul_normalized_entry_name(self) -> None:
        target = PREFIX + "readme.txt"
        body = dict(source_entries(SOURCE))[target]
        for suffix in ("alias", "/../../extra.php"):
            with self.subTest(suffix=suffix):
                stored_name = target + "!" + suffix
                archive = self.archive(
                    "nul-name-" + str(len(suffix)) + ".zip",
                    remove={target},
                    extras=[(stored_name, body)],
                )
                # Keep every payload/CRC and both name-field lengths intact.
                # ZipInfo's writer truncates NUL itself, so mutate the local and
                # central name fields after producing a complete valid archive.
                raw = archive.read_bytes()
                needle = stored_name.encode("utf-8")
                self.assertEqual(raw.count(needle), 2)
                raw_name = target + "\x00" + suffix
                archive.write_bytes(raw.replace(needle, raw_name.encode("utf-8")))
                with zipfile.ZipFile(archive) as parsed:
                    info = parsed.getinfo(target)
                    self.assertEqual(info.filename, target)
                    self.assertEqual(info.orig_filename, raw_name)
                self.assert_rejected(archive, "normalized or NUL archive entry")

    def test_unexpected_source_symlink_errors_are_failures_not_skips(self) -> None:
        for error in (PermissionError("unexpected permission failure"), OSError("unexpected host failure")):
            with self.subTest(error=type(error).__name__):
                isolated = ValidateMediaZipTests("test_refuses_source_symlink_when_host_supports_it")
                isolated.setUp()
                try:
                    with patch("os.symlink", side_effect=error):
                        with self.assertRaises(type(error)):
                            isolated.test_refuses_source_symlink_when_host_supports_it()
                finally:
                    isolated.tearDown()

    def test_refuses_source_symlink_when_host_supports_it(self) -> None:
        source_copy = self.work / "source-symlink"
        shutil.copytree(SOURCE, source_copy)
        target = source_copy / "assets" / "hero" / "dbgr-hero-poster-720.webp"
        link = source_copy / "assets" / "hero" / "linked.webp"
        try:
            os.symlink(target, link)
        except NotImplementedError as error:
            self.skipTest(f"NOT_RUN: host cannot create true symlink fixture: {error}")
        except OSError as error:
            if os.name == "nt" and getattr(error, "winerror", None) == 1314:
                self.skipTest(f"NOT_RUN: host cannot create true symlink fixture: {error}")
            raise
        self.assertTrue(link.is_symlink(), "host reported symlink creation but did not create a link")
        self.assert_rejected(self.archive("valid-symlink-source.zip"), "regular non-symlink", source_copy)


if __name__ == "__main__":
    unittest.main()
