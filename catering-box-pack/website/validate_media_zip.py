#!/usr/bin/env python3
"""Read-only validator for the finished DoughBoss Growth Media release archive.

Usage: python validate_media_zip.py ARCHIVE.zip [MEDIA_PLUGIN_SOURCE]

The validator deliberately compares an archive to the checked release source.  It never
extracts an archive and it never writes to either supplied path.
"""

from __future__ import annotations

import hashlib
import json
import os
from dataclasses import dataclass
from pathlib import Path
import re
import stat
import sys
import zipfile
import zlib


MAX_ARCHIVE_BYTES = 1_900_000
PREFIX = "doughboss-growth-media/"
RELEASE_VERSION = "0.2.0"
BOX_MEDIA_VERSION = "0.1.0"
EXPECTED_BOX_LABELS = {
    "closed": ("concept", False),
    "seal_macro": ("concept", False),
    "closed_band": ("concept", False),
    "closed_art": ("concept", False),
    "seal_art": ("concept", False),
    "liner_tile": ("real", False),
    "seal_badge": ("real", False),
}

# This release deliberately has a fixed source/runtime allow-list.  A new media file is a
# release change: update the builder, this independent gate, and its tests together.
EXPECTED_RELATIVE_FILES = frozenset(
    {
        "doughboss-growth-media.php",
        "readme.txt",
        "assets/box/manifest.json",
        "assets/box/closed-art-640.07ff093c.jpg",
        "assets/box/closed-art-640.8990b2bc.webp",
        "assets/box/closed-art-640.fedcd921.avif",
        "assets/box/closed-art-960.368f5e64.webp",
        "assets/box/closed-art-960.af70539d.jpg",
        "assets/box/closed-art-960.e2251d00.avif",
        "assets/box/liner-tile-472.9c19e9e3.png",
        "assets/box/liner-tile-472.f303f989.webp",
        "assets/box/seal-art-300.644feb64.webp",
        "assets/box/seal-art-300.de54f6f7.jpg",
        "assets/box/seal-art-300.e6e217d1.avif",
        "assets/box/seal-art-599.2512e61f.jpg",
        "assets/box/seal-art-599.ae452d02.webp",
        "assets/box/seal-art-599.cd80a740.avif",
        "assets/box/seal-badge-112.9edb854a.png",
        "assets/box/seal-badge-112.f2c8b2a0.avif",
        "assets/box/seal-badge-112.f9557031.webp",
        "assets/box/seal-badge-224.224aae55.png",
        "assets/box/seal-badge-224.6c24b5ed.avif",
        "assets/box/seal-badge-224.c026fb57.webp",
        "assets/box/w1-band-640.8b8b4a91.avif",
        "assets/box/w1-band-640.94b464e7.jpg",
        "assets/box/w1-band-640.9be8eb13.webp",
        "assets/box/w1-band-828.1432ad6a.webp",
        "assets/box/w1-band-828.a02e9599.avif",
        "assets/box/w1-band-828.d37e5172.jpg",
        "assets/box/w1-closed-640.07584c52.jpg",
        "assets/box/w1-closed-640.58228849.avif",
        "assets/box/w1-closed-640.cdf3ba5d.webp",
        "assets/box/w1-closed-828.35529caa.webp",
        "assets/box/w1-closed-828.4928ccac.jpg",
        "assets/box/w1-closed-828.724fd2ba.avif",
        "assets/box/w4-seal-640.2cf6d967.jpg",
        "assets/box/w4-seal-640.6f8d7a61.webp",
        "assets/box/w4-seal-640.dfa4d8fb.avif",
        "assets/box/w4-seal-828.a62e1118.jpg",
        "assets/box/w4-seal-828.e81f3982.webp",
        "assets/box/w4-seal-828.eba694be.avif",
        "assets/hero/hero.json",
        "assets/hero/dbgr-hero-poster-1080.webp",
        "assets/hero/dbgr-hero-poster-720.webp",
    }
)
EXPECTED_SOURCE_DIRS = {"assets", "assets/box", "assets/hero"}


class ValidationError(Exception):
    """A release gate failure with an actionable message."""


@dataclass(frozen=True)
class SourceFile:
    path: Path
    body: bytes
    crc: int

    @property
    def size(self) -> int:
        return len(self.body)


def fail(message: str) -> None:
    raise ValidationError(message)


def regular_file(path: Path, label: str) -> None:
    try:
        metadata = path.lstat()
    except OSError as error:
        fail(f"cannot inspect {label}: {error}")
    if stat.S_ISLNK(metadata.st_mode) or not stat.S_ISREG(metadata.st_mode):
        fail(f"source must be a regular non-symlink file: {label}")


def read_regular(path: Path, label: str) -> bytes:
    regular_file(path, label)
    try:
        return path.read_bytes()
    except OSError as error:
        fail(f"cannot read {label}: {error}")


def manifest_filename(value: object, label: str) -> str:
    if not isinstance(value, str) or not value:
        fail(f"invalid manifest filename: {label}")
    if value in {".", ".."} or value.startswith(".") or "/" in value or "\\" in value:
        fail(f"unsafe manifest filename: {label}")
    return value


def load_json(path: Path, label: str) -> dict[str, object]:
    try:
        loaded = json.loads(read_regular(path, label).decode("utf-8"))
    except (UnicodeDecodeError, json.JSONDecodeError) as error:
        fail(f"invalid JSON in {label}: {error}")
    if not isinstance(loaded, dict):
        fail(f"manifest root must be an object: {label}")
    return loaded


def source_versions(source: Path) -> str:
    plugin = read_regular(source / "doughboss-growth-media.php", "doughboss-growth-media.php").decode(
        "utf-8"
    )
    readme = read_regular(source / "readme.txt", "readme.txt").decode("utf-8")
    header = re.search(r"^ \* Version:\s*(\S+)", plugin, re.MULTILINE)
    stable = re.search(r"^Stable tag:\s*(\S+)", readme, re.MULTILINE)
    changelog = re.search(r"^= ([0-9.]+) =\s*$", readme, re.MULTILINE)
    versions = {
        "plugin header": header.group(1) if header else "",
        "readme stable tag": stable.group(1) if stable else "",
        "readme changelog": changelog.group(1) if changelog else "",
    }
    if any(not re.fullmatch(r"\d+\.\d+\.\d+", value) for value in versions.values()):
        fail("missing or invalid release version metadata")
    if len(set(versions.values())) != 1:
        fail("release version metadata does not agree")
    version = next(iter(versions.values()))
    if version != RELEASE_VERSION:
        fail(f"expected release version {RELEASE_VERSION}, got {version}")
    return version


def manifest_files(source: Path, version: str) -> set[str]:
    box = load_json(source / "assets" / "box" / "manifest.json", "assets/box/manifest.json")
    if box.get("media_version") != BOX_MEDIA_VERSION:
        fail(f"box manifest must retain media_version {BOX_MEDIA_VERSION}")
    slots = box.get("slots")
    if not isinstance(slots, dict) or not slots:
        fail("box manifest slots are missing")
    if set(slots) != set(EXPECTED_BOX_LABELS):
        fail("box manifest slot set does not match frozen release")
    box_files: set[str] = {"assets/box/manifest.json"}
    for slot_name, slot in slots.items():
        if not isinstance(slot_name, str) or not isinstance(slot, dict):
            fail("invalid box manifest slot")
        status = slot.get("status")
        ai_food = slot.get("ai_food")
        if status not in {"concept", "real"} or not isinstance(ai_food, bool):
            fail(f"invalid concept/AI label for box slot: {slot_name}")
        if (status, ai_food) != EXPECTED_BOX_LABELS[slot_name]:
            fail(f"box concept/AI labels do not match frozen release: {slot_name}")
        variants = slot.get("variants", {})
        files = slot.get("files", {})
        if not isinstance(variants, dict) or not isinstance(files, dict):
            fail(f"invalid box media files for slot: {slot_name}")
        for variant_list in variants.values():
            if not isinstance(variant_list, list):
                fail(f"invalid box variants for slot: {slot_name}")
            for variant in variant_list:
                if not isinstance(variant, list) or len(variant) != 2:
                    fail(f"invalid box variant for slot: {slot_name}")
                box_files.add("assets/box/" + manifest_filename(variant[1], f"box slot {slot_name}"))
        for filename in files.values():
            box_files.add("assets/box/" + manifest_filename(filename, f"box slot {slot_name}"))

    hero = load_json(source / "assets" / "hero" / "hero.json", "assets/hero/hero.json")
    if hero.get("media_version") != version:
        fail("hero manifest media_version does not match the release")
    hero_status = hero.get("status")
    hero_ai_food = hero.get("ai_food")
    if hero_status not in {"concept", "real"} or not isinstance(hero_ai_food, bool):
        fail("invalid concept/AI label for hero")
    if (hero_status, hero_ai_food) != ("concept", True):
        fail("hero concept/AI labels do not match frozen release")
    if not isinstance(hero.get("owner_decision"), str) or not hero["owner_decision"].strip():
        fail("AI-food hero requires its owner decision record")
    posters = hero.get("posters")
    if not isinstance(posters, dict) or not posters:
        fail("hero manifest posters are missing")
    hero_files = {"assets/hero/hero.json"}
    for poster_name, poster in posters.items():
        if not isinstance(poster_name, str) or not isinstance(poster, dict):
            fail("invalid hero poster record")
        filename = manifest_filename(poster.get("file"), f"hero poster {poster_name}")
        if not isinstance(poster.get("w"), int) or not isinstance(poster.get("h"), int):
            fail(f"invalid hero poster dimensions: {poster_name}")
        hero_files.add("assets/hero/" + filename)
    return {"doughboss-growth-media.php", "readme.txt"} | box_files | hero_files


def inspect_source(source: Path) -> tuple[str, dict[str, SourceFile]]:
    if source.is_symlink() or not source.is_dir():
        fail(f"source directory is missing or linked: {source}")

    actual_files: set[str] = set()
    actual_dirs: set[str] = set()
    for directory, dirnames, filenames in os.walk(source, followlinks=False):
        current = Path(directory)
        for dirname in dirnames:
            child = current / dirname
            relative = child.relative_to(source).as_posix()
            if child.is_symlink():
                fail(f"source directory must not be a symlink: {relative}")
            actual_dirs.add(relative)
        for filename in filenames:
            child = current / filename
            relative = child.relative_to(source).as_posix()
            regular_file(child, relative)
            actual_files.add(relative)
    if actual_dirs != EXPECTED_SOURCE_DIRS:
        fail("source directory layout is not the canonical media-plugin layout")

    version = source_versions(source)
    from_manifests = manifest_files(source, version)
    if from_manifests != EXPECTED_RELATIVE_FILES:
        fail("manifest file references are not the canonical 44-file media release set")
    if actual_files != EXPECTED_RELATIVE_FILES:
        fail("source file set is not the canonical 44-file media release set")

    files: dict[str, SourceFile] = {}
    for relative in sorted(EXPECTED_RELATIVE_FILES):
        body = read_regular(source / Path(relative), relative)
        files[relative] = SourceFile(source / Path(relative), body, zlib.crc32(body) & 0xFFFFFFFF)
    return version, files


def check_archive_path(archive_path: Path) -> int:
    try:
        size = archive_path.stat().st_size
    except OSError as error:
        fail(f"archive is missing: {error}")
    if not archive_path.is_file() or size < 1:
        fail(f"archive is missing or empty: {archive_path}")
    if size > MAX_ARCHIVE_BYTES:
        fail(f"archive exceeds {MAX_ARCHIVE_BYTES} byte budget before opening: {size}")
    return size


def canonical_relative(name: str) -> str:
    if not name or "\\" in name or not name.startswith(PREFIX):
        fail(f"unsafe or noncanonical archive entry: {name!r}")
    relative = name[len(PREFIX) :]
    segments = relative.split("/")
    if not relative or name.endswith("/") or any(
        not segment or segment in {".", ".."} or segment.startswith(".") for segment in segments
    ):
        fail(f"hidden, directory, traversal, or noncanonical archive entry: {name!r}")
    if "/".join(segments) != relative:
        fail(f"noncanonical archive entry: {name!r}")
    return relative


def validate(archive_path: Path, source: Path) -> tuple[str, int, int, int, str]:
    archive_size = check_archive_path(archive_path)
    version, source_files = inspect_source(source)
    expected_names = {PREFIX + relative for relative in source_files}
    expected_total = sum(item.size for item in source_files.values())

    try:
        with zipfile.ZipFile(archive_path, "r") as archive:
            infos = archive.infolist()
            seen: set[str] = set()
            seen_casefold: set[str] = set()
            declared_total = 0
            for info in infos:
                name = info.filename
                # zipfile truncates names at NUL (and may normalise separators).
                # Validate the stored name, not only the parser's canonical alias.
                raw_name = info.orig_filename
                if raw_name != name or "\x00" in raw_name:
                    fail(f"normalized or NUL archive entry name is not allowed: {raw_name!r}")
                relative = canonical_relative(name)
                if name in seen:
                    fail(f"duplicate archive entry: {name}")
                folded = name.casefold()
                if folded in seen_casefold:
                    fail(f"case-colliding archive entry: {name}")
                seen.add(name)
                seen_casefold.add(folded)
                if name not in expected_names:
                    fail(f"unexpected archive entry: {name}")
                if info.flag_bits & 0x1:
                    fail(f"encrypted archive entry is not allowed: {name}")
                if info.create_system != 3:
                    fail(f"unsupported archive creator platform for entry: {name}")
                if info.is_dir() or info.external_attr != (0o100644 << 16):
                    fail(f"archive entry must be a Unix regular 0o100644 file: {name}")
                source_file = source_files[relative]
                if info.file_size != source_file.size:
                    fail(f"archive/source byte-size mismatch before read: {name}")
                if info.CRC != source_file.crc:
                    fail(f"archive/source CRC mismatch before read: {name}")
                declared_total += info.file_size
            if seen != expected_names:
                fail(
                    "archive file set differs from the canonical 44-file media release "
                    f"(missing={len(expected_names - seen)}, unexpected={len(seen - expected_names)})"
                )
            if declared_total != expected_total:
                fail("archive aggregate payload bytes differ from the bounded source payload")
            for relative, source_file in source_files.items():
                name = PREFIX + relative
                try:
                    archived = archive.read(name)
                except (OSError, RuntimeError, zipfile.BadZipFile) as error:
                    fail(f"cannot read archive entry {name}: {error}")
                if archived != source_file.body:
                    fail(f"archive/current-source payload mismatch: {name}")
    except zipfile.BadZipFile as error:
        fail(f"cannot open ZIP archive: {error}")

    with archive_path.open("rb") as archive_stream:
        digest = hashlib.file_digest(archive_stream, "sha256").hexdigest()
    return version, len(source_files), expected_total, archive_size, digest


def main(argv: list[str]) -> int:
    if len(argv) not in {2, 3}:
        print("Usage: python validate_media_zip.py ARCHIVE.zip [MEDIA_PLUGIN_SOURCE]", file=sys.stderr)
        return 1
    archive_path = Path(argv[1])
    source = Path(argv[2]) if len(argv) == 3 else Path(__file__).resolve().parent / "media-plugin"
    try:
        version, count, payload_size, archive_size, digest = validate(archive_path, source)
    except ValidationError as error:
        print(f"ERROR: {error}", file=sys.stderr)
        return 1
    print(
        f"VALID version={version} files={count} payload_bytes={payload_size} "
        f"archive_bytes={archive_size} sha256={digest}\n"
        "Scope: current-source parity only; this is not proof of a historic original hash or deployment."
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv))
