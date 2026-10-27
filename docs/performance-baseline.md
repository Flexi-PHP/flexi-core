# Runtime Performance Baseline

This is a reproducible local baseline for detecting large runtime regressions. It is not a production capacity claim and does not set a performance target.

## Measurement

Run the harness from the repository root inside the development container:

```sh
podman exec flexi php tools/benchmark-runtime.php 100
```

The harness creates and removes isolated temporary application roots. It compares the core configuration with no active modules against the same configuration plus one active local module that contributes one service and one literal HTTP route. It warms each scenario, then reports median and p95 times over the requested number of iterations. Bootstrap measures configuration validation, container construction, and router construction. Dispatch measures one PSR-7 request after bootstrap and checks for the expected 404 or 200 response.

## Recorded baseline

Measured on 2026-10-26 in the repository's Podman development container (PHP 7.4.33, 100 measured iterations after 10 warmups):

| Scenario | Bootstrap median | Bootstrap p95 | HTTP dispatch median | HTTP dispatch p95 |
|---|---:|---:|---:|---:|
| Minimal core configuration | 39.620 ms | 41.514 ms | 0.057 ms | 0.075 ms |
| One active module with one service and route | 44.270 ms | 48.707 ms | 0.059 ms | 0.077 ms |

The module scenario added about 4.65 ms to median bootstrap in this run; dispatch time was effectively unchanged at this scale. These figures describe this container and fixture only. Compare runs on the same host/container and PHP build; investigate repeated material changes before optimizing.
