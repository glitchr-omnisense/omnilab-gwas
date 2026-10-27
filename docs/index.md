# omnilab/gwas

The NHGRI-EBI GWAS Catalog's REST API v2 (`https://www.ebi.ac.uk/gwas/rest/api/v2/`) for
[omnisense/omnilab](https://github.com/glitchr-omnisense/omnilab): studies (`GCST000854`), variants
(`rs334`) and traits (EFO, Mondo, HPO, Orphanet) with their strongest associations - p-values,
effect sizes, risk alleles - as entities, the studies, variants and traits they tie as related
things; a search of traits; outside the contract, the associations paged. No key.

## Installation

```sh
composer require omnilab/gwas
```

```php
use Omnilab\Gwas\GwasSourceFactory;

$gwas = (new GwasSourceFactory($httpClient))->create([
    'mailto' => 'support@glitchr.io',  // optional
    'associations' => 10,              // optional: associations (or a trait's studies) an entity holds; 0: one call
]);
```

## Credentials

None: the GWAS Catalog's REST API v2 takes no key and no account.

## Options

The factory's options, with the defaults it applies (`GwasSourceFactory`):

| Option | Type | Default | Required | Effect |
|---|---|---|---|---|
| `associations` | `int` | `10` | no | how many associations (for a trait, how many studies) an entity holds, the strongest first; 0: none, one call an entity |
| `mailto` | `?string` | null | no | a contact address, sent in the User-Agent |
| `user_agent` | `?string` | null | no | the whole User-Agent, in place of the one made from `mailto` |
| `throttle` | `float` | `0` | no | seconds left between two calls: the catalogue publishes no rate |
| `base_uri` | `string` | `https://www.ebi.ac.uk/gwas/rest/api/v2/` | no | the API the source calls |

## Capabilities

`capabilities()` answers `entity`, `search`; an operation it does not answer throws `NotSupportedException` before any call.

| Capability | Supported | |
|---|---|---|
| `entity` | yes | a study (`GCST000854`), a variant (`rs334`) or a trait (`EFO_...`, `MONDO:...`, `HP:...`, `ORPHA:...`); another base refused before any call; two calls with `associations` above 0 |
| `search` | yes | the catalogue's traits by name (`efo-traits?trait=`), 100 a page at most; a study or a variant is not searched by text |
| `series` | no |  |
| `products` | no |  |
| `occurrences` | no |  |
| `datasets` | no |  |
| `jobs` | no |  |
| `open` | no |  |
| `structure` | no |  |
| `cross_references` | no | none of the same thing: the studies, variants and traits an entity is tied to are its `related` (`trait`, `variant`, `study`, `associated_trait`) |
| `forecast` | no |  |
| `historical` | no |  |
| `area` | no |  |
| `station` | no |  |
| `cloud_cover` | no |  |

Outside the contract, on `Omnilab\Gwas\GwasSource`: `associations(Identifier|string $id, int
$limit = 25, ?string $cursor = null): Page` - a study's, a variant's or a trait's associations, the
smallest p-value first, as `Omnilab\Gwas\Association` objects ([below](#outside-the-contract-associations)).

## The schemes

| Thing | Scheme | Read from |
|---|---|---|
| a study | `gwas` | `GCST000854`, its page `www.ebi.ac.uk/gwas/studies/` |
| a variant | `dbsnp` | `rs334`, its dbSNP page |
| a trait | `efo`, `mondo`, `hp`, `orphanet` | `EFO_0004321`, `MONDO:0011382`... (the catalogue writes `MONDO_0011382`) |

Any other base's identifier is refused before any call.

## Which API

`https://www.ebi.ac.uk/gwas/rest/api/v2/` (the v1 API answers 410 since its retirement: "This
legacy GWAS Catalog REST API is no longer available"):

| Method | Calls |
|---|---|
| `entity()`, a study | `studies/GCST000854`, then `associations?accession_id=GCST000854&page=0&size=10&sort=p_value&direction=asc` |
| `entity()`, a variant | `single-nucleotide-polymorphisms/rs334`, then `associations?rs_id=rs334&...` |
| `entity()`, a trait | `efo-traits/MONDO_0011382`, then `studies?efo_id=MONDO_0011382&page=0&size=10` |
| `search()` | `efo-traits?trait=...&page=...&size=...` |
| `associations()` | `associations?{accession_id,rs_id,efo_id}=...&page=...&size=...&sort=p_value&direction=asc` |

With `associations` 0, an entity takes one call. Unknown is a 404: `null`. The catalogue's
`studies?disease_trait=` and `?efo_trait=` matched nothing for "sickle" or "sickle cell anemia" on
2026-10-15: a search goes through the traits (`efo-traits?trait=`), whose studies `entity()` gives.

## What an entity holds

A study: `id`, `label` (its reported trait), `kind` `GWAS study`, `url`, and `reported_trait`,
`trait` and `background_trait` (one each, mapped), `initial_sample_size`,
`replication_sample_size`, `discovery_ancestry`, `replication_ancestry`, `cohort`,
`genotyping_technology`, `array_manufacturer` (one each), `platforms`, `snp_count`, `imputed`,
`pooled`, `gxe`, `gxg`, `full_summary_stats_available`, `summary_statistics` (its address when
there is one), `pubmed`, `associations` (how many), `association` (the strongest, as text).
`related`: `trait`, `background_trait` (EFO, Mondo, HPO), `variant` (the strongest associations'
rs ids).

A variant: `id` (`dbsnp:rs334`), `label`, `kind` `variant`, `url` (its dbSNP page), and
`functional_class`, `most_severe_consequence`, `alleles` (`T/A/C/G (forward)`),
`genomic_location` (`11:5227002`), `map_location`, `gene` (one per mapped gene), `merged`,
`associations`, `association` (labelled with its study: `Association (GCST90475778)`, valued
`rs334-T (HBB): p = 1e-323, Hereditary hemolytic anemias (PheCode 282)`). `related`: `study`,
`associated_trait`.

A trait: `id`, `label`, `kind` `trait`, `url` (its page in the catalogue), `studies` (how many),
`study` (one each: `Sickle cell anemia (PheCode 282.5) (GCST90481652)`). `related`: `study`.

A p-value is written as the catalogue keeps it, mantissa and exponent (`1e-323`): a float
underflows past about 1e-308, and the catalogue holds smaller ones.

## Outside the contract: associations()

`associations($id, $limit, $cursor)` gives a `Page` of `Association` objects, the smallest p-value
first - `id`, `study`, `variants`, `riskAlleles` (`rs334-T`), `pValue` (a float), `pValueText`
(mantissa and exponent), `traits`, `reported`, `oddsRatio`, `beta` (`2.241 unit decrease`),
`range` (the confidence interval: `[2.16-2.32]`), `riskFrequency`, `genes`, `locations`, `pubmed`,
`firstAuthor` - its `next` the next page's number, its `total` the catalogue's count (134 for rs334
on 2026-10-15).

## The Collector: the sickle cell variant from ClinVar

ClinVar gives a variation's rs id; the catalogue reads it:

```php
$collector->collect('clinvar', '15333', 1, ['gwas']);
```

Against the real services on 2026-10-15: three calls in 1.7 s, `sources` = clinvar, gwas; ClinVar's
classification and the catalogue's associations side by side. An rs id names a site (rs334 holds
four alleles): the variation and the site are joined as ClinVar names them.

## What the API does not give here

The loci and genomic contexts of an association (`associations/{id}/loci`), a study's ancestries
in detail, the summary statistics themselves (files), publications as entities, genes as the
catalogue maps them (`genes/{symbol}`), and the background traits' own studies.

## Example

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Omnilab\Gwas\GwasSourceFactory;
use Omnilab\Source\Query;
use Symfony\Component\HttpClient\HttpClient;

$gwas = (new GwasSourceFactory(HttpClient::create()))->create(['mailto' => 'you@example.org', 'associations' => 3]);

$variant = $gwas->entity('rs334');
echo $variant->label, ': ', $variant->value('most_severe_consequence'), ', ', $variant->value('genomic_location'), ', ', $variant->value('associations'), " associations; the strongest:\n";
foreach ($variant->properties('association') as $association) {
    echo '  ', $association, "\n";
}

$page = $gwas->associations('rs334', 2);
foreach ($page as $a) {
    printf("  %s in %s: p = %s, %s\n", implode(',', $a->riskAlleles), $a->study, $a->pValueText, $a->oddsRatio ?? $a->beta ?? '-');
}

foreach ($gwas->search(new Query('sickle', limit: 5)) as $trait) {
    echo 'trait ', $trait->id->display(), ' ', $trait->label, "\n";
}
```

```
rs334: missense variant, 11:5227002, 134 associations; the strongest:
  rs334-T (HBB): p = 1e-323, Hereditary hemolytic anemias (PheCode 282)
  rs334-T (HBB): p = 9e-131, hematocrit (maximum, inv-norm transformed)
  rs334-T (HBB): p = 3e-118, hematocrit (maximum, inv-norm transformed)
  rs334-T in gwas:GCST90475778: p = 1e-323, 2.241 unit decrease
  rs334-T in gwas:GCST90475337: p = 9e-131, 0.2578 unit increase
trait EFO:0700066 sickle cell disease and related diseases
trait MONDO:0011382 sickle cell disease
```

(as answered on 2026-10-27: four calls)

```yaml
# config/packages/omnilab.yaml
omnilab:
    sources:
        gwas: { factory: gwas, options: { mailto: '%env(OMNILAB_MAILTO)%', associations: 10 } }
```

`associations()` is a method of `GwasSource`, not of the interface the bundle injects: check the
instance before calling it.

## Errors

Every call goes through `HttpSource`: a 404 or a 410 (and a 204) is null - an empty page for a list -; a 429 is a `RateLimitedException` (`retryAfter` from `Retry-After`); a 5xx, a time-out or a host that does not answer an `UnavailableException`; any other 4xx a `ProviderException` with its `status` and what the service said; an answer that is not JSON a `ProviderException`, a web page answered for JSON an `UnavailableException`.

Before any call: an identifier of another base given to `entity()` or `associations()`
(`NotSupportedException`). An unknown study, variant or trait is the catalogue's 404, null; the
retired v1 API answers 410 (null too, were `base_uri` pointed at it).

## Verified, and not

Against the real service on 2026-10-15: the study GCST000854 (four associations, two traits),
rs334 (134 associations, the smallest p-value 1e-323), sickle cell disease as a trait (eight
studies), an rs id the catalogue does not hold (404), the search for "sickle" (two traits), rs334's
associations in pages of five, the Collector's walk above. The fixtures in `Tests/Fixtures/` are its
answers. Each call took 1 to 2.7 s.

Again on 2026-10-27: rs334 and its three strongest associations, two pages of its associations,
the search for "sickle" (two traits; the example above).

Not verified: an Orphanet id as a trait (the catalogue's traits met were EFO, Mondo and HPO terms);
a rate limit (none published, none met).

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by the NHGRI, the EMBL-EBI or the GWAS Catalog. The catalogue's answers name the
[EMBL-EBI's terms of use](https://www.ebi.ac.uk/about/terms-of-use/) as their licence, and it asks
to be cited (Cerezo M. et al., Nucleic Acids Res. 2025, 10.1093/nar/gkae1070), as the README's
[Terms of use](https://github.com/glitchr-omnisense/omnilab-gwas#terms-of-use) says. Whoever runs
the package is responsible for complying with them and for any use made of what it returns: see
the README's [Responsibility](https://github.com/glitchr-omnisense/omnilab-gwas#responsibility)
section.
