# PHPForge TODO

## Re-evaluate the regular Psalm package

Status: blocked by the stable upstream dependency graph as of 2026-08-02.

PHPForge currently requires `psalm/phar ^6.16.1`. The PHAR distribution keeps
Psalm's internal dependencies isolated while PHPForge uses Pest 5, PHPUnit 13,
and `sebastian/diff ^9`.

Stable `vimeo/psalm 6.16.1` and `7.0.0-beta19` accept `sebastian/diff` only up
to `^8`. Do not replace the PHAR with a development branch, beta release,
Composer alias, or a Pest/PHPUnit downgrade merely to use the regular package.

Revisit this when a stable `vimeo/psalm` release:

- supports `sebastian/diff ^9` or newer;
- supports PHP 8.4+ and PHPForge's active Symfony major;
- provides the normal `vendor/bin/psalm` executable and configuration schema;
- resolves alongside the latest stable Pest and Drift releases without aliases
  or relaxed stability settings.

When those conditions are met:

1. Confirm the published constraints with `composer show vimeo/psalm --all`.
2. Replace `psalm/phar` with the compatible stable `vimeo/psalm` constraint.
3. Change PHPForge task paths from `psalm.phar` back to `psalm`.
4. Update the Deptrac package boundary and restore the local Psalm schema path
   if the package provides it.
5. Update the Psalm task/display regression tests and this documentation.
6. Run `composer update -W`, `composer validate --strict`, `composer audit`,
   `composer ic:release:constraints`, and the complete `composer ic:ci` suite.
7. Remove this TODO only after the stable dependency graph and all quality gates
   pass without downgrading another tool.
8. Additionally, you can check commit around 2-3 August for relevant changes we've done for this (so you can revert the relevant ones only). Note: These commits also include some other changes that not relevant to this, so you must pick the correct ones.
