# Compatibility and verification

The first-release runtime target is Magento Open Source 2.4.9 with PHP 8.5.
The Composer PHP range is a dependency constraint, not a claim that every
Magento/PHP combination has passed runtime testing.

**Minimum: Magento Open Source 2.4.4**, enforced by `magento/framework: ^103.0.4`. Magento 2.4.0–2.4.3
run only on PHP 7.3/7.4; this package needs PHP 8.1.

| Magento Open Source | PHP | Runtime verification |
| --- | --- | --- |
| 2.4.9 | 8.5 | Unit tests, PHPStan level 5 and the Magento2 coding standard passed; runtime verification pending |
| 2.4.4–2.4.8 | 8.1–8.4 | Unverified; use a matching Magento dependency set and services |

The GitHub workflow checks package metadata, PHP syntax on 8.1–8.5, Git archive contents and runs the unit tests.
