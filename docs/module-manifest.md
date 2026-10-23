# Flexi Module Manifest

This document defines the canonical metadata for Flexi modules. Composer remains the source of truth for package identity, package version, PHP support, and dependency resolution. Flexi-specific metadata belongs in `extra.flexi`.

## Composer metadata

Every distributable module is a Composer package with these fields:

| Field | Requirement | Meaning |
|---|---|---|
| `name` | Required | Unique Composer package name, such as `acme/flexi-module-auth`. |
| `version` | Prefer a VCS tag; use an explicit value only when the package source requires it | Package SemVer. Do not duplicate this value in `extra.flexi`. |
| `type` | Required: `flexi-module` | Identifies the package as a Flexi module. |
| `require.php` | Required | Composer version constraint for supported PHP runtimes. |
| `require.flexi/contracts` | Required for modules that use framework contracts | Contract package and compatible version range. |
| `require` / `suggest` | As needed | Required and optional package dependencies, including other modules. Composer resolves required dependencies. |
| `autoload.psr-4` | Required for autoloaded PHP code | Module namespace-to-directory mapping. |

The Flexi core is not a module dependency. Core compatibility is declared in `extra.flexi.compatibility.core`, because Composer's root application package is not normally required as a dependency by its modules.

## `extra.flexi` schema, version 1

| Field | Type | Requirement | Meaning |
|---|---|---|---|
| `schema-version` | Integer | Required; currently `1` | Version of this metadata schema, independent of the package version. |
| `module-name` | String | Required | Stable logical module identifier used by Flexi state and diagnostics. Use the same identifier in local and vendor installations. |
| `compatibility.core` | Composer constraint string | Required | Supported Flexi Core SemVer range. |
| `config-files` | List of strings | Required; may be empty | Explicit module configuration files, relative to the package root. Paths must not be absolute or contain `..` segments. |
| `provides` | List of strings | Required; may be empty | Stable capability identifiers exposed by the module, for example `auth.authentication`. |

Package dependencies are expressed through Composer's `require` and `suggest` fields and are not repeated in Flexi metadata. A required module dependency must use that module's Composer package name and version constraint.

Capability identifiers describe what a module offers; they do not install or activate the module and do not replace a dependency declaration. Capability discovery and dependency validation will be implemented in a later roadmap item.

## Example

```json
{
  "name": "acme/flexi-module-auth",
  "description": "Authentication module for Flexi applications",
  "type": "flexi-module",
  "require": {
    "php": "^8.2",
    "flexi/contracts": "^5.0"
  },
  "autoload": {
    "psr-4": {
      "Acme\\Flexi\\Auth\\": "src/"
    }
  },
  "extra": {
    "flexi": {
      "schema-version": 1,
      "module-name": "Auth",
      "compatibility": {
        "core": "^4.0"
      },
      "config-files": [
        "Config/services.json",
        "Config/routes.json"
      ],
      "provides": [
        "auth.authentication"
      ]
    }
  }
}
```

The `^8.2` and `^4.0` values are illustrative. A module must declare the PHP and core ranges it actually supports. Composer derives a published package's version from its VCS tag; an explicit `version` field is not needed for a normal tagged repository.

## Current implementation status

The core reads the canonical `extra.flexi` block and consumes `config-files`. Vendor discovery reads `module-name` (and the historical `name` alias), while local discovery still derives the module identifier from the directory name; making those paths consistent is still pending. The core does not yet validate `schema-version`, core compatibility, `provides`, or dependency compatibility. Until that validation is implemented, these fields are a documented contract for module authors, not a guarantee that startup rejects invalid values. The historical `extra.flexi-module` format is legacy input and is not part of the canonical schema.
