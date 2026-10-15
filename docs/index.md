# omnilab/gwas

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

## Verified, and not

Against the real service on 2026-10-15: the study GCST000854 (four associations, two traits),
rs334 (134 associations, the smallest p-value 1e-323), sickle cell disease as a trait (eight
studies), an rs id the catalogue does not hold (404), the search for "sickle" (two traits), rs334's
associations in pages of five, the Collector's walk above. The fixtures in `Tests/Fixtures/` are its
answers. Each call took 1 to 2.7 s.

Not verified: an Orphanet id as a trait (the catalogue's traits met were EFO, Mondo and HPO terms);
a rate limit (none published, none met).
