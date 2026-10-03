# Changelog

This file lists each notable change to `gatepost/postcode`. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the versions follow [Semantic Versioning](https://semver.org/spec/v2.0.0.html). Changie writes this file from the change files in `.changes/`.

## 0.1.0-alpha.1 - 2026-10-03

### Added

- Add the client of NIPOST's gateway: lookup, reverse and autocomplete, from spec 0.2.0.

### Changed

- Implement spec 0.2.0. It adds the client contract, and core results stay the same.

## 0.1.0-alpha.0 - 2026-10-02

### Added

- Add the offline core of spec 0.1.0: parse, normalize, isLegacy, stateName, precisionForAccuracy, truncate, parent, contains and redact.
