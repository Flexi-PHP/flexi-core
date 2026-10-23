# Runtime Requirements

Flexi Core declares PHP `>=7.4` and `ext-json` as required Composer platform packages. The exact transitive extension requirements come from the packages pinned in `composer.lock`; inspect them with `composer check-platform-reqs` for the selected PHP runtime.

## Verified development container

The repository's Podman development container was checked on 2026-10-24:

| Component | Declared or observed version |
|---|---|
| PHP runtime | 7.4.33 (CLI and Apache container) |
| Composer | 2.8.12 |
| PHPUnit | 9.6.33 (from the committed lockfile) |
| Composer runtime API | 2.2.2 |
| Composer plugin API | 2.6.0 in the running container |
| Required core platform packages | PHP `>=7.4`, `ext-json` |
| Optional async extension | `ext-pcntl`; installed in the development container |

`composer check-platform-reqs` passes in this container. The locked dependency set additionally uses `curl`, `dom`, `filter`, `hash`, `iconv`, `libxml`, `pcre`, `phar`, `reflection`, `simplexml`, `spl`, `tokenizer`, `xml`, `xmlwriter`, and `zip`; `ctype` and `mbstring` are satisfied by Symfony polyfills in this image. Treat this as a measured lockfile/runtime snapshot, not a universal list: rerun the command after dependency or image changes.

The checked-in Compose definition starts only the Flexi PHP/Apache container; the core test suite does not require an external database, queue, or cache service. Optional modules and application adapters may add their own services and platform requirements.

`ext-pcntl` is optional. It is needed only for asynchronous event dispatch, which is available from the CLI when `dispatch_mode=1`. If asynchronous dispatch is requested without `pcntl_fork` and `pcntl_waitpid`, Flexi throws an actionable `RuntimeException`. Normal web requests and synchronous event dispatch do not require the extension.

Composer resolves the application dependencies and validates declared platform requirements during install/update. Use the lockfile committed with the application to reproduce the same package versions in local development, CI, and deployment.
