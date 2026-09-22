#!/usr/bin/env python3
"""Validate the consolidated Logistics plan without third-party packages."""

from __future__ import annotations

import argparse
import hashlib
import re
import sys
from pathlib import Path


PLAN_DIR = Path(__file__).resolve().parents[1]
EXPECTED = {
    "R": range(1, 41),
    "D": range(1, 21),
    "F": range(1, 15),
    "KTD": range(1, 15),
    "IC": range(1, 13),
    "DM": range(1, 16),
    "G": range(1, 19),
    "U": range(1, 21),
    "V": range(1, 11),
    "AE": range(1, 31),
    "Q": range(1, 14),
}
PHASES = ("P0", "P1A", "P1B", "P2", "P3", "P4", "P5", "P6", "P7", "P8")
PHASE_FIELDS = (
    "Objective",
    "Scope",
    "Affected modules",
    "Dependencies",
    "Database changes",
    "Commands/APIs/contracts",
    "Business rules",
    "Validations",
    "Permissions",
    "Integration points",
    "Tests",
    "Acceptance",
    "Migration/refactoring",
    "Out of scope",
)
SOURCE_HASHES = {
    "a": {
        "MANIFEST.json": "9342b155cc91a91ef3e78bd22b51461f67cfaf6e896501c9c845131be8276431",
        "registry/requirements.json": "f44b63010713fbfefab831ada889ac60e4b18a7feff5a8e8520237c00eeff507",
    },
    "b": {
        "project-manifest.json": "4dd89022ffeb0f7a8bb70d02f4a5ee46588f5f54015ad127d7ffe84d8707b547",
        "registers/requirements.json": "531ccaf8fe6907b123510bebc8ae02b6dcfd64775a3f235cc23e25fd82c4f921",
    },
}


def digest(path: Path) -> str:
    value = hashlib.sha256()
    with path.open("rb") as source:
        for chunk in iter(lambda: source.read(1024 * 1024), b""):
            value.update(chunk)
    return value.hexdigest()


def markdown_files() -> list[Path]:
    return sorted(path for path in PLAN_DIR.glob("*.md") if path.is_file())


def validate_links(files: list[Path], errors: list[str]) -> None:
    link_pattern = re.compile(r"\[[^]]+\]\(([^)]+)\)")
    for path in files:
        for target in link_pattern.findall(path.read_text()):
            local = target.split("#", 1)[0]
            if not local or "://" in local or local.startswith("mailto:"):
                continue
            if not (path.parent / local).resolve().exists():
                errors.append(f"{path.name}: missing local link target {target}")


def validate_definitions(files: list[Path], errors: list[str]) -> None:
    contents = {path.name: path.read_text() for path in files}
    roadmap_registry = contents["08-IMPLEMENTATION-ROADMAP.md"].split("## Unit execution index", 1)[0]
    definitions = {
        "R": re.findall(r"^- R(\d+)\.", contents["01-SCOPE-AND-REQUIREMENTS.md"], re.MULTILINE),
        "D": re.findall(r"^\| D(\d+) \|", contents["02-DOMAIN-MODEL.md"], re.MULTILINE),
        "F": re.findall(r"^## F(\d+)\.", contents["03-WORKFLOWS-AND-LIFECYCLES.md"], re.MULTILINE),
        "KTD": re.findall(r"^- KTD(\d+)\.", contents["04-TARGET-ARCHITECTURE.md"], re.MULTILINE),
        "IC": re.findall(r"^## IC(\d+)\.", contents["05-INTEGRATION-CONTRACTS.md"], re.MULTILINE),
        "DM": re.findall(r"^\| DM(\d+) \|", contents["06-DATA-MODEL-AND-MIGRATION.md"], re.MULTILINE),
        "G": re.findall(r"^\| G(\d+) \|", contents["07-AUREUSERP-GAP-AND-REFACTOR-MAP.md"], re.MULTILINE),
        "U": re.findall(r"^\| U(\d+) \|", roadmap_registry, re.MULTILINE),
        "V": re.findall(r"^\| V(\d+) \|", contents["09-TESTING-AND-ACCEPTANCE.md"], re.MULTILINE),
        "AE": re.findall(r"^- AE(\d+)\.", contents["09-TESTING-AND-ACCEPTANCE.md"], re.MULTILINE),
        "Q": re.findall(r"^\| Q(\d+) \|", contents["12-OPEN-DECISIONS.md"], re.MULTILINE),
    }
    for prefix, expected_numbers in EXPECTED.items():
        actual = [int(value) for value in definitions[prefix]]
        expected = list(expected_numbers)
        if actual != expected:
            errors.append(f"canonical {prefix} definitions are {actual}, expected {expected}")


def validate_ids(text: str, errors: list[str]) -> None:
    for prefix, numbers in EXPECTED.items():
        allowed = set(numbers)
        for number in numbers:
            identifier = f"{prefix}{number}"
            if not re.search(rf"(?<![A-Z0-9]){re.escape(identifier)}(?![A-Z0-9])", text):
                errors.append(f"missing identifier {identifier}")
        referenced = {
            int(value)
            for value in re.findall(rf"(?<![A-Z0-9-]){prefix}(\d+)(?![A-Z0-9])", text)
        }
        for number in sorted(referenced - allowed):
            errors.append(f"out-of-range identifier {prefix}{number}")


def validate_crosswalk(files: list[Path], errors: list[str]) -> None:
    trace = next(path for path in files if path.name == "11-SOURCE-TRACEABILITY.md").read_text()
    section = trace.split("## Target requirement crosswalk", 1)[-1].split("## Source fingerprints", 1)[0]
    rows = re.findall(r"^\| (R\d+) \|", section, re.MULTILINE)
    expected = [f"R{number}" for number in range(1, 41)]
    if rows != expected:
        errors.append("target requirement crosswalk must contain R1-R40 exactly once in order")
    for line in section.splitlines():
        if not re.match(r"^\| R\d+ \|", line):
            continue
        columns = [column.strip() for column in line.strip("|").split("|")]
        if len(columns) != 5:
            errors.append(f"invalid crosswalk row: {line}")
            continue
        requirement, _domain, _design, delivery, evidence = columns
        if not re.search(r"\bP(?:0|1A|1B|[2-8])\b", delivery) or not re.search(r"\bU\d+\b", delivery):
            errors.append(f"{requirement}: crosswalk delivery must cite a phase and unit")
        if not re.search(r"\b(?:V|AE)\d+\b", evidence):
            errors.append(f"{requirement}: crosswalk evidence must cite a V or AE identifier")


def validate_phases(files: list[Path], errors: list[str]) -> None:
    roadmap = next(path for path in files if path.name == "08-IMPLEMENTATION-ROADMAP.md").read_text()
    headings = list(re.finditer(r"^## (P(?:0|1A|1B|[2-8]))\. ", roadmap, re.MULTILINE))
    found = tuple(match.group(1) for match in headings)
    if found != PHASES:
        errors.append(f"roadmap phase order is {found}, expected {PHASES}")
        return
    for index, match in enumerate(headings):
        end = headings[index + 1].start() if index + 1 < len(headings) else len(roadmap)
        section = roadmap[match.start():end]
        for field in PHASE_FIELDS:
            if not re.search(rf"^- \*\*{re.escape(field)}:\*\*\s+\S", section, re.MULTILINE):
                errors.append(f"{match.group(1)}: missing phase field {field}")

    testing = next(path for path in files if path.name == "09-TESTING-AND-ACCEPTANCE.md").read_text()
    evidence_section = testing.split("## Phase acceptance evidence", 1)[-1].split("## All-mode launch matrix", 1)[0]
    evidence_rows = tuple(re.findall(r"^\| (P(?:0|1A|1B|[2-8])) —", evidence_section, re.MULTILINE))
    if evidence_rows != PHASES:
        errors.append(f"phase acceptance evidence order is {evidence_rows}, expected {PHASES}")


def validate_sources(source: Path | None, key: str, errors: list[str]) -> None:
    if source is None:
        return
    for relative, expected in SOURCE_HASHES[key].items():
        path = source / relative
        if not path.is_file():
            errors.append(f"source {key.upper()}: missing {relative}")
        elif digest(path) != expected:
            errors.append(f"source {key.upper()}: hash mismatch for {relative}")


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--source-a", type=Path)
    parser.add_argument("--source-b", type=Path)
    args = parser.parse_args()

    files = markdown_files()
    errors: list[str] = []
    if len(files) != 13:
        errors.append(f"expected 13 top-level plan Markdown files, found {len(files)}")

    combined = "\n".join(path.read_text() for path in files)
    validate_links(files, errors)
    validate_definitions(files, errors)
    validate_ids(combined, errors)
    validate_crosswalk(files, errors)
    validate_phases(files, errors)
    validate_sources(args.source_a, "a", errors)
    validate_sources(args.source_b, "b", errors)
    for path in files + [Path(__file__)]:
        for line_number, line in enumerate(path.read_text().splitlines(), 1):
            if line.rstrip() != line:
                errors.append(f"{path.name}:{line_number}: trailing whitespace")

    if errors:
        for error in errors:
            print(f"ERROR: {error}")
        return 1

    print(f"Validated {len(files)} plan documents, required IDs, links, crosswalk and phase contracts.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
