# Runtime Requirements

Flexi Core declares PHP `>=7.4` and `ext-json` as required Composer platform packages. The exact transitive extension requirements come from the packages pinned in `composer.lock`; inspect them with `composer check-platform-reqs` for the selected PHP runtime.

`ext-pcntl` is optional. It is needed only for asynchronous event dispatch, which is available from the CLI when `dispatch_mode=1`. If asynchronous dispatch is requested without `pcntl_fork` and `pcntl_waitpid`, Flexi throws an actionable `RuntimeException`. Normal web requests and synchronous event dispatch do not require the extension.

Composer resolves the application dependencies and validates declared platform requirements during install/update. Use the lockfile committed with the application to reproduce the same package versions in local development, CI, and deployment.
