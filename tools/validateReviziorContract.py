#!/usr/bin/env python3
"""Strict, dependency-free R0 validation for the ReviziOR v1 contract.

The OpenAPI document intentionally uses JSON syntax in a .yaml file. JSON is a
YAML 1.2 subset, which lets CI reject duplicate keys and dangling local $refs
without adding a parser to the application runtime.
"""

from __future__ import annotations

import hashlib
import json
import re
import sys
import uuid
from pathlib import Path
from typing import Any


ROOT = Path(__file__).resolve().parent.parent
SPEC = ROOT / "api" / "openapi-revizior-integration.yaml"
FIXTURES = ROOT / "source" / "revizior-integration" / "contract" / "v1"
HASHES = FIXTURES / "hashes.json"
VERSION = "1.0"

EXPECTED_FIXTURES = {
    "capabilities.json",
    "provision-request.json",
    "provision-response.json",
    "organization-update.json",
    "user-upsert.json",
    "client-upsert-request.json",
    "client-upsert-response.json",
    "price-resolve-request.json",
    "price-resolve-response.json",
    "invoice-draft-request.json",
    "invoice-snapshot.json",
    "errors/validation-failed.json",
    "events/invoice-issued.json",
}

UUID_PATTERN = re.compile(
    r"^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-8][0-9a-fA-F]{3}-"
    r"[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$"
)


class ValidationError(RuntimeError):
    pass


def strict_object(pairs: list[tuple[str, Any]]) -> dict[str, Any]:
    result: dict[str, Any] = {}
    for key, value in pairs:
        if key in result:
            raise ValidationError(f"duplicate JSON/YAML key: {key}")
        result[key] = value
    return result


def load(path: Path) -> Any:
    try:
        return json.loads(path.read_text(encoding="utf-8"), object_pairs_hook=strict_object)
    except (OSError, json.JSONDecodeError, ValidationError) as error:
        raise ValidationError(f"{path.relative_to(ROOT)}: {error}") from error


def walk(value: Any, location: str = "$"):
    yield location, value
    if isinstance(value, dict):
        for key, child in value.items():
            yield from walk(child, f"{location}.{key}")
    elif isinstance(value, list):
        for index, child in enumerate(value):
            yield from walk(child, f"{location}[{index}]")


def resolve_pointer(document: Any, reference: str) -> None:
    if not reference.startswith("#/"):
        raise ValidationError(f"only local OpenAPI refs are allowed: {reference}")
    current = document
    for encoded in reference[2:].split("/"):
        token = encoded.replace("~1", "/").replace("~0", "~")
        if not isinstance(current, dict) or token not in current:
            raise ValidationError(f"dangling OpenAPI ref: {reference}")
        current = current[token]


def validate_openapi() -> None:
    spec = load(SPEC)
    if not isinstance(spec, dict) or spec.get("openapi") != "3.1.0":
        raise ValidationError("OpenAPI document must declare openapi=3.1.0")
    if spec.get("info", {}).get("version") != VERSION:
        raise ValidationError("OpenAPI info.version must be 1.0")
    paths = spec.get("paths")
    webhooks = spec.get("webhooks")
    schemas = spec.get("components", {}).get("schemas")
    if not isinstance(paths, dict) or not paths:
        raise ValidationError("OpenAPI paths must not be empty")
    if not isinstance(schemas, dict) or not schemas:
        raise ValidationError("OpenAPI component schemas must not be empty")
    if not isinstance(webhooks, dict) or not webhooks:
        raise ValidationError("OpenAPI must describe the outbound event webhook")
    for path in paths:
        if not path.startswith("/api/"):
            raise ValidationError(f"integration path must be absolute under /api: {path}")
    for location, value in walk(spec):
        if isinstance(value, dict) and "$ref" in value:
            resolve_pointer(spec, str(value["$ref"]))
        if isinstance(value, float):
            raise ValidationError(f"floating-point literal is forbidden at {location}")


def canonical_bytes(value: Any) -> bytes:
    return json.dumps(
        value,
        ensure_ascii=False,
        sort_keys=True,
        separators=(",", ":"),
        allow_nan=False,
    ).encode("utf-8")


def fixture_files() -> dict[str, Path]:
    files = {
        path.relative_to(FIXTURES).as_posix(): path
        for path in FIXTURES.rglob("*.json")
        if path != HASHES
    }
    missing = EXPECTED_FIXTURES - files.keys()
    if missing:
        raise ValidationError(f"missing contract fixtures: {', '.join(sorted(missing))}")
    unexpected = files.keys() - EXPECTED_FIXTURES
    if unexpected:
        raise ValidationError(f"unregistered contract fixtures: {', '.join(sorted(unexpected))}")
    return files


def validate_fixtures() -> None:
    files = fixture_files()
    manifest = load(HASHES)
    if manifest.get("specVersion") != VERSION:
        raise ValidationError("hashes.json must declare specVersion=1.0")
    if manifest.get("algorithm") != "sha256-canonical-json-v1":
        raise ValidationError("unexpected fixture hash algorithm")
    recorded = manifest.get("fixtures")
    if not isinstance(recorded, dict):
        raise ValidationError("hashes.json fixtures must be an object")

    actual: dict[str, str] = {}
    for name, path in sorted(files.items()):
        fixture = load(path)
        if not isinstance(fixture, dict) or fixture.get("specVersion") != VERSION:
            raise ValidationError(f"{name}: fixture must declare top-level specVersion=1.0")
        for location, value in walk(fixture):
            if isinstance(value, float):
                raise ValidationError(f"{name}: JSON float forbidden at {location}; use a decimal string or minor units")
            if isinstance(value, str) and UUID_PATTERN.fullmatch(value):
                canonical = str(uuid.UUID(value))
                if value != canonical:
                    raise ValidationError(f"{name}: non-canonical UUID at {location}")
        actual[name] = "sha256:" + hashlib.sha256(canonical_bytes(fixture)).hexdigest()

    if set(recorded) != set(actual):
        raise ValidationError("hashes.json file set does not match contract fixtures")
    for name, digest in actual.items():
        if recorded[name] != digest:
            raise ValidationError(f"{name}: hash mismatch; expected {digest}")

    for request_name, response_name in (
        ("provision-request.json", "provision-response.json"),
        ("client-upsert-request.json", "client-upsert-response.json"),
    ):
        request_hash = "sha256:" + hashlib.sha256(canonical_bytes(load(files[request_name]))).hexdigest()
        response = load(files[response_name])
        payload_hash = response.get("data", {}).get("payloadHash")
        if payload_hash != request_hash:
            raise ValidationError(f"{response_name}: payloadHash does not match {request_name}")


def main() -> int:
    try:
        validate_openapi()
        validate_fixtures()
    except ValidationError as error:
        print(f"ReviziOR contract validation failed: {error}", file=sys.stderr)
        return 1
    print(f"ReviziOR contract {VERSION}: OpenAPI refs and {len(fixture_files())} fixtures are valid.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
