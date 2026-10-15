# omnilab/gwas

The NHGRI-EBI GWAS Catalog for [glitchr/omnilab](https://github.com/glitchr-studio/omnilab), through
its REST API v2: a study by its accession (`GCST000854`) with its reported trait, its traits in EFO,
Mondo or HPO, its samples and ancestries, platforms, number of variants, publication and summary
statistics; a variant by its rs id (`rs334`) with its functional class, alleles, location and mapped
genes; a trait by its EFO, Mondo or HPO id (`MONDO_0011382`) with its studies; each with its
strongest associations (the smallest p-values first) as properties and the studies, variants and
traits they tie it to as related things; a search of traits by name; the associations themselves,
paged. No key.

```php
$gwas = (new GwasSourceFactory($http))->create(['mailto' => 'support@glitchr.io']);
$snp = $gwas->entity('rs334');
$snp->value('association');              // rs334-T (HBB): p = 1e-323, Hereditary hemolytic anemias (PheCode 282)
$snp->related('study')->keys();          // gwas:GCST90475778, ...
$study = $gwas->entity('GCST000854');
$study->related('trait')->keys();        // efo:0004321, mondo:0005084
$gwas->entity('MONDO_0011382')->value('studies');   // 8
$gwas->associations('rs334', 5, '1');    // Association objects: risk allele, p-value, OR or beta, CI, genes
$gwas->search(new Query('sickle'));      // sickle cell disease and related diseases, sickle cell disease
```

```yaml
omnilab:
    sources:
        gwas:
            factory: gwas
            options:
                mailto: 'support@glitchr.io'   # in the User-Agent
                associations: 10               # associations (or a trait's studies) an entity holds
```

A study is named in the core's `gwas` scheme, a variant in `dbsnp`, a trait in `efo`, `mondo` or
`hp`. The `Collector` reaches the catalogue from ClinVar by a variation's rs id
(`$collector->collect('clinvar', '15333')`).

## Terms of use

The catalogue's answers name the [EMBL-EBI's terms of use](https://www.ebi.ac.uk/about/terms-of-use/)
as their licence. Cite Cerezo M. et al., "The NHGRI-EBI GWAS Catalog: standards for reusability,
sustainability and diversity", Nucleic Acids Res. 2025,
[10.1093/nar/gkae1070](https://doi.org/10.1093/nar/gkae1070). No rate is published.

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by the NHGRI-EBI GWAS Catalog. It reaches their service on behalf of whoever runs it, and it does not
redistribute their data beyond the short excerpts recorded as test fixtures. Its MIT licence
covers this package's code only and grants no right to the service or to its data.

Whoever installs and runs this package is responsible for complying with the terms above, for
obtaining whatever they require (an account, a key, a licence or subscription, an attribution or
a citation, a rate limit to respect), and for any use made of what it returns. The authors
publish it free of charge, make no commercial use of it, and accept no liability for how it is
used (see [LICENSE](LICENSE)).

License: MIT.
