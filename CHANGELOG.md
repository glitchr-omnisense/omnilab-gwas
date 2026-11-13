# Changelog

## [Unreleased]

## [1.0.0] - 2026-11-13

The first stable release: it installs with Composer's default minimum-stability (stable).

### Added

- Omnilab GWAS Catalog: a study by its accession (GCST000854), a variant by its rs id, a trait by
  its EFO, Mondo or HPO id - reported and mapped traits, samples, ancestries, platforms, functional
  class, location, mapped genes, studies - each with its strongest associations and the studies,
  variants and traits they tie it to as related things, a search of traits, and the associations
  paged with their p-values, effect sizes and risk alleles, through the NHGRI-EBI GWAS Catalog's
  REST API v2, no key needed.

### Release

- Requires `omnisense/omnilab` `^1.0`, stable versions, where it required `@dev` ones:
  `composer require omnilab/gwas` installs in a project at Composer's default minimum-stability
  (stable). `extra.branch-alias` names the branch (`dev-1.x`: `1.x-dev`).
- CI: `.github/workflows/ci.yml` (glitchr-studio/ci's php-package.yml, pinned: the package
  installed alone, PHPUnit, PHPStan against its baseline, a consumer install) and `release.yml`
  (that CI on the tag, then glitchr-studio/ci's php-release.yml); `phpunit.xml.dist` runs the
  package's own tests.
- README: the install line and a first call.
