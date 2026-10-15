<?php

namespace Omnilab\Gwas;

use Omnilab\Model\Identifier;
use Omnilab\Model\Identifiers;

/**
 * One association of the GWAS Catalog: a variant's risk allele tied to a
 * trait by a study, with its p-value (as the catalogue writes it, its
 * mantissa and exponent: a float underflows past 1e-308), its odds ratio or
 * beta and its confidence interval, the risk allele's frequency, where it
 * lies and the genes mapped to it.
 */
final readonly class Association implements \Stringable
{
    /**
     * @param list<string> $riskAlleles "rs334-T"
     * @param list<string> $genes       the mapped genes' symbols
     * @param list<string> $locations   "11:5227002"
     * @param list<string> $reported    the traits as the study reported them
     */
    public function __construct(
        public string $id,
        public ?Identifier $study,
        public Identifiers $variants,
        public array $riskAlleles,
        public ?float $pValue,
        public ?string $pValueText,
        public Identifiers $traits,
        public array $reported = [],
        public ?float $oddsRatio = null,
        public ?string $beta = null,
        public ?string $range = null,
        public ?string $riskFrequency = null,
        public array $genes = [],
        public array $locations = [],
        public ?string $pubmed = null,
        public ?string $firstAuthor = null,
    ) {
    }

    /** @param array<string, mixed> $row an association of the v2 API */
    public static function fromRow(array $row): ?self
    {
        if (!isset($row['association_id'])) {
            return null;
        }
        $text = static fn (mixed $v): ?string => \is_scalar($v) && '' !== trim((string) $v) && !\in_array(trim((string) $v), ['NR', '-', 'NA'], true) ? trim((string) $v) : null;
        $list = static fn (mixed $values): array => array_values(array_filter(array_map($text, (array) ($values ?? []))));
        $variants = [];
        foreach ((array) ($row['snp_allele'] ?? []) as $allele) {
            $variants[] = \is_array($allele) ? Identifier::tryOf('dbsnp', $allele['rs_id'] ?? null) : null;
        }
        $traits = [];
        foreach ((array) ($row['efo_traits'] ?? []) as $trait) {
            $traits[] = \is_array($trait) ? GwasSource::traitOf((string) ($trait['efo_id'] ?? '')) : null;
        }
        $mantissa = $row['pvalue_mantissa'] ?? null;
        $exponent = $row['pvalue_exponent'] ?? null;

        return new self(
            (string) $row['association_id'],
            Identifier::tryOf('gwas', $row['accession_id'] ?? null),
            new Identifiers($variants),
            $list($row['snp_effect_allele'] ?? null),
            is_numeric($row['p_value'] ?? null) ? (float) $row['p_value'] : null,
            is_numeric($mantissa) && is_numeric($exponent) ? $mantissa.'e'.$exponent : null,
            new Identifiers($traits),
            $list($row['reported_trait'] ?? null),
            is_numeric($row['or_value'] ?? null) ? (float) $row['or_value'] : null,
            $text($row['beta'] ?? null),
            $text($row['range'] ?? null),
            $text($row['risk_frequency'] ?? null),
            $list($row['mapped_genes'] ?? null),
            $list($row['locations'] ?? null),
            $text($row['pubmed_id'] ?? null),
            $text($row['first_author'] ?? null),
        );
    }

    /** "rs334-T (HBB): p = 1e-323, Hereditary hemolytic anemias (PheCode 282)" */
    public function __toString(): string
    {
        return implode(', ', $this->riskAlleles ?: array_map(static fn (Identifier $v) => $v->value, $this->variants->all()))
            .($this->genes ? ' ('.implode(', ', $this->genes).')' : '')
            .(null !== $this->pValueText ? ': p = '.$this->pValueText : '')
            .($this->reported ? ', '.implode('; ', $this->reported) : '');
    }
}
