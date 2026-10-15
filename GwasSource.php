<?php

namespace Omnilab\Gwas;

use Omnilab\Exception\NotSupportedException;
use Omnilab\Model\Domain;
use Omnilab\Model\Entity;
use Omnilab\Model\Identifier;
use Omnilab\Model\Identifiers;
use Omnilab\Model\Property;
use Omnilab\Model\Scheme;
use Omnilab\Source\Capability;
use Omnilab\Source\HttpSource;
use Omnilab\Source\Page;
use Omnilab\Source\Query;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The NHGRI-EBI GWAS Catalog through its REST API v2: a study by its
 * accession ("GCST000854") - its reported trait, its traits in EFO, Mondo
 * or HPO, its samples and ancestries, platforms, SNP count, publication,
 * summary statistics -; a variant by its rs id ("rs334") - its functional
 * class, alleles, location, mapped genes -; a trait by its EFO, Mondo or
 * HPO id ("MONDO_0011382") - its name and its studies -; each with its
 * strongest associations (the smallest p-values first) as properties and
 * the studies, variants and traits they tie it to as related things; a
 * search of traits by name; the associations themselves, paged
 * (associations(), outside the contract).
 *
 * No key, no account, no published rate: no throttle by default. Two calls
 * an entity (the thing, its associations or its studies), one a search
 * page. The v1 API answers 410 since its retirement.
 */
final class GwasSource extends HttpSource
{
    public const BASE_URI = 'https://www.ebi.ac.uk/gwas/rest/api/v2/';

    /** The most items a page holds (the API's own cap is higher). */
    public const MAX_LIMIT = 100;

    /** The ontologies a trait is named in, and the prefix the catalogue writes them with. */
    private const TRAITS = ['efo' => 'EFO_', 'mondo' => 'MONDO_', 'hp' => 'HP_', 'orphanet' => 'Orphanet_', 'go' => 'GO_'];

    /**
     * @param int $associations how many associations (or a trait's studies) an entity holds, the strongest first (0: none, one call)
     */
    public function __construct(
        HttpClientInterface $http,
        private readonly int $associations = 10,
        string $baseUri = self::BASE_URI,
        array $headers = [],
        float $throttle = 0.0,
    ) {
        parent::__construct($http, $baseUri, $headers, $throttle);
    }

    public function getName(): string
    {
        return 'gwas';
    }

    public function capabilities(): array
    {
        return [Capability::ENTITY, Capability::SEARCH];
    }

    /**
     * A study ("GCST000854", its page), a variant ("rs334", "dbsnp:rs334",
     * its dbSNP page) or a trait ("EFO_0004321", "MONDO:0011382",
     * "HP_0001878"); another base's identifier is refused before any call;
     * null when the catalogue has no such thing.
     */
    public function entity(Identifier|string $id): ?Entity
    {
        $identifier = self::identify($id, Scheme::GWAS, Scheme::DBSNP, Scheme::EFO, Scheme::MONDO, Scheme::HP, Scheme::ORPHANET) ?? throw NotSupportedException::identifier('gwas', (string) $id);
        $size = max(0, $this->associations);

        return match ($identifier->scheme) {
            Scheme::GWAS => ($study = $this->getJson('studies/'.rawurlencode($identifier->value))) ? $this->study($study, $size ? $this->rows('associations', ['accession_id' => $identifier->value], 0, $size, true) : null) : null,
            Scheme::DBSNP => ($snp = $this->getJson('single-nucleotide-polymorphisms/'.rawurlencode($identifier->value))) ? $this->snp($snp, $size ? $this->rows('associations', ['rs_id' => $identifier->value], 0, $size, true) : null) : null,
            default => ($trait = $this->getJson('efo-traits/'.rawurlencode(self::traitId($identifier)))) ? $this->traitEntity($trait, $size ? $this->rows('studies', ['efo_id' => self::traitId($identifier)], 0, $size) : null) : null,
        };
    }

    /**
     * The traits whose name holds the text (the catalogue's EFO, Mondo and
     * HPO terms), in its order; a domain other than biology answers nothing.
     * An entity of a page holds the trait's name: entity() adds its studies.
     */
    public function search(Query $query): Page
    {
        if (null !== $query->domain && Domain::BIOLOGY !== $query->domain) {
            return new Page();
        }
        $text = trim((string) preg_replace('~\s+~u', ' ', $query->text));
        if ('' === $text) {
            return new Page();
        }
        $page = max(0, (int) $query->cursor);
        [$rows, $total, $pages] = $this->rows('efo-traits', ['trait' => $text], $page, max(1, min(self::MAX_LIMIT, $query->limit)));
        $items = array_values(array_filter(array_map(fn (array $row) => $this->traitEntity($row, null), $rows)));

        return new Page($items, $page + 1 < $pages ? (string) ($page + 1) : null, $total);
    }

    /**
     * The associations of a study, a variant or a trait, the smallest
     * p-value first, one page at a time (the cursor is the page's number).
     * Outside the contract: an association ties a variant, a trait and a
     * study, with its statistics.
     *
     * @return Page<Association>
     */
    public function associations(Identifier|string $id, int $limit = 25, ?string $cursor = null): Page
    {
        $identifier = self::identify($id, Scheme::GWAS, Scheme::DBSNP, Scheme::EFO, Scheme::MONDO, Scheme::HP, Scheme::ORPHANET) ?? throw NotSupportedException::identifier('gwas', (string) $id);
        $filter = match ($identifier->scheme) {
            Scheme::GWAS => ['accession_id' => $identifier->value],
            Scheme::DBSNP => ['rs_id' => $identifier->value],
            default => ['efo_id' => self::traitId($identifier)],
        };
        $page = max(0, (int) $cursor);
        [$rows, $total, $pages] = $this->rows('associations', $filter, $page, max(1, min(self::MAX_LIMIT, $limit)), true);

        return new Page(array_values(array_filter(array_map(static fn (array $row) => Association::fromRow($row), $rows))), $page + 1 < $pages ? (string) ($page + 1) : null, $total);
    }

    /** A trait's id as the catalogue writes it (EFO_0004321, MONDO_0011382) in a scheme of the core; null for the others. */
    public static function traitOf(string $id): ?Identifier
    {
        $parsed = '' === $id ? null : Identifier::parse($id);

        return null !== $parsed && isset(self::TRAITS[$parsed->scheme->value]) ? $parsed : null;
    }

    /**
     * One page of a collection: its rows, the total, the number of pages.
     *
     * @param array<string, string> $filter
     *
     * @return array{0: list<array<string, mixed>>, 1: ?int, 2: int}
     */
    private function rows(string $collection, array $filter, int $page, int $size, bool $byPValue = false): array
    {
        $data = $this->getJson($collection, $filter + ['page' => $page, 'size' => $size] + ($byPValue ? ['sort' => 'p_value', 'direction' => 'asc'] : [])) ?? [];
        $key = 'efo-traits' === $collection ? 'efo_traits' : $collection;

        return [
            array_values(array_filter((array) ($data['_embedded'][$key] ?? []), 'is_array')),
            isset($data['page']['totalElements']) ? (int) $data['page']['totalElements'] : null,
            (int) ($data['page']['totalPages'] ?? 0),
        ];
    }

    /**
     * @param array<string, mixed> $s
     * @param array{0: list<array<string, mixed>>, 1: ?int, 2: int}|null $associations
     */
    private function study(array $s, ?array $associations): ?Entity
    {
        $id = Identifier::tryOf(Scheme::GWAS, $s['accession_id'] ?? null);
        if (null === $id) {
            return null;
        }
        [$properties, $add] = self::bag();
        $text = static fn (mixed $v): ?string => \is_scalar($v) && !\is_bool($v) && '' !== trim((string) $v) && !\in_array(trim((string) $v), ['NR', 'NA', '-'], true) ? trim((string) $v) : null;
        $add('reported_trait', 'Reported trait', $text($s['disease_trait'] ?? null));
        foreach ((array) ($s['efo_traits'] ?? []) as $trait) {
            $add('trait', 'Trait', \is_array($trait) ? $text($trait['efo_trait'] ?? null) : null);
        }
        foreach ((array) ($s['bg_efo_traits'] ?? []) as $trait) {
            $add('background_trait', 'Background trait', \is_array($trait) ? $text($trait['efo_trait'] ?? null) : null);
        }
        $add('initial_sample_size', 'Initial sample', $text($s['initial_sample_size'] ?? null));
        $add('replication_sample_size', 'Replication sample', $text($s['replication_sample_size'] ?? null));
        foreach (['discovery_ancestry' => 'Discovery ancestry', 'replication_ancestry' => 'Replication ancestry', 'cohort' => 'Cohort', 'genotyping_technologies' => 'Genotyping technology', 'array_manufacturer' => 'Array manufacturer'] as $key => $label) {
            foreach ((array) ($s[$key] ?? []) as $value) {
                $add('genotyping_technologies' === $key ? 'genotyping_technology' : $key, $label, $text($value));
            }
        }
        $add('platforms', 'Platforms', $text($s['platforms'] ?? null));
        $add('snp_count', 'Variants analysed', is_numeric($s['snp_count'] ?? null) ? (int) $s['snp_count'] : null);
        foreach (['imputed' => 'Imputed', 'pooled' => 'Pooled', 'gxe' => 'Gene-environment interaction', 'gxg' => 'Gene-gene interaction', 'full_summary_stats_available' => 'Full summary statistics available'] as $key => $label) {
            $add($key, $label, isset($s[$key]) ? (bool) $s[$key] : null);
        }
        $add('summary_statistics', 'Summary statistics', $text($s['full_summary_stats'] ?? null));
        $add('pubmed', 'PubMed', $text($s['pubmed_id'] ?? null));
        $related = ['trait' => [], 'background_trait' => []];
        foreach (['efo_traits' => 'trait', 'bg_efo_traits' => 'background_trait'] as $field => $relation) {
            foreach ((array) ($s[$field] ?? []) as $trait) {
                $related[$relation][] = \is_array($trait) ? self::traitOf((string) ($trait['efo_id'] ?? '')) : null;
            }
        }
        $related += $this->strongest($associations, $add, 'variant');

        return new Entity(
            id: $id,
            label: $text($s['disease_trait'] ?? null) ?? $id->value,
            domain: Domain::BIOLOGY,
            kind: 'GWAS study',
            properties: $properties(),
            url: $id->url(),
            source: 'gwas',
            related: self::related($related),
        );
    }

    /**
     * @param array<string, mixed> $v
     * @param array{0: list<array<string, mixed>>, 1: ?int, 2: int}|null $associations
     */
    private function snp(array $v, ?array $associations): ?Entity
    {
        $id = Identifier::tryOf(Scheme::DBSNP, $v['rs_id'] ?? null);
        if (null === $id) {
            return null;
        }
        [$properties, $add] = self::bag();
        $add('functional_class', 'Functional class', self::text(isset($v['functional_class']) ? str_replace('_', ' ', (string) $v['functional_class']) : null));
        $add('most_severe_consequence', 'Most severe consequence', self::text(isset($v['most_severe_consequence']) ? str_replace('_', ' ', (string) $v['most_severe_consequence']) : null));
        $add('alleles', 'Alleles', self::text($v['alleles'] ?? null));
        foreach ((array) ($v['locations'] ?? []) as $location) {
            if (\is_array($location) && isset($location['chromosome_name'], $location['chromosome_position'])) {
                $add('genomic_location', 'Genomic location', $location['chromosome_name'].':'.$location['chromosome_position']);
                $add('map_location', 'Cytogenetic location', self::text($location['region']['name'] ?? null));
            }
        }
        foreach ((array) ($v['mapped_genes'] ?? []) as $gene) {
            $add('gene', 'Mapped gene', \is_string($gene) ? self::text($gene) : null);
        }
        $add('merged', 'Merged into another rs id', isset($v['merged']) ? (bool) $v['merged'] : null);
        $related = $this->strongest($associations, $add, 'study');

        return new Entity(
            id: $id,
            label: $id->value,
            domain: Domain::BIOLOGY,
            kind: 'variant',
            properties: $properties(),
            url: $id->url(),
            source: 'gwas',
            related: self::related($related),
        );
    }

    /**
     * @param array<string, mixed> $t
     * @param array{0: list<array<string, mixed>>, 1: ?int, 2: int}|null $studies
     */
    private function traitEntity(array $t, ?array $studies): ?Entity
    {
        $id = self::traitOf((string) ($t['efo_id'] ?? ''));
        if (null === $id) {
            return null;
        }
        [$properties, $add] = self::bag();
        $related = ['study' => []];
        if (null !== $studies) {
            $add('studies', 'Studies', $studies[1]);
            foreach ($studies[0] as $study) {
                $accession = Identifier::tryOf(Scheme::GWAS, $study['accession_id'] ?? null);
                $add('study', 'Study', self::text($study['disease_trait'] ?? null).(null === $accession ? '' : ' ('.$accession->value.')'));
                $related['study'][] = $accession;
            }
        }

        return new Entity(
            id: $id,
            label: self::text($t['efo_trait'] ?? null) ?? $id->display(),
            domain: Domain::BIOLOGY,
            kind: 'trait',
            properties: $properties(),
            url: 'https://www.ebi.ac.uk/gwas/efotraits/'.self::traitId($id),
            source: 'gwas',
            related: self::related($related),
        );
    }

    /**
     * The strongest associations as properties, and what they tie the
     * entity to: the studies, the variants, the traits.
     *
     * @param array{0: list<array<string, mixed>>, 1: ?int, 2: int}|null $associations
     *
     * @return array<string, list<?Identifier>>
     */
    private function strongest(?array $associations, \Closure $add, string $other): array
    {
        if (null === $associations) {
            return [];
        }
        $add('associations', 'Associations', $associations[1]);
        $related = ['study' => [], 'variant' => [], 'associated_trait' => []];
        foreach ($associations[0] as $row) {
            $association = Association::fromRow($row);
            if (null === $association) {
                continue;
            }
            $add('association', 'Association'.(null === $association->study ? '' : ' ('.$association->study->value.')'), (string) $association);
            $related['study'][] = $association->study;
            array_push($related['variant'], ...$association->variants->all());
            array_push($related['associated_trait'], ...$association->traits->all());
        }

        return 'study' === $other ? ['study' => $related['study'], 'associated_trait' => $related['associated_trait']] : ['variant' => $related['variant']];
    }

    /** A trait as the catalogue names it: EFO_0004321, MONDO_0011382. */
    private static function traitId(Identifier $trait): string
    {
        return (self::TRAITS[$trait->scheme->value] ?? '').$trait->value;
    }

    /**
     * @return array{0: \Closure(): list<Property>, 1: \Closure(string, string, mixed): void}
     */
    private static function bag(): array
    {
        $properties = new \ArrayObject();

        return [
            static fn (): array => $properties->getArrayCopy(),
            static function (string $key, string $label, mixed $value) use ($properties): void {
                if (null !== $value && '' !== $value) {
                    $properties[] = new Property($key, $label, $value, 'gwas');
                }
            },
        ];
    }

    /**
     * @param array<string, list<?Identifier>> $related
     *
     * @return array<string, Identifiers>
     */
    private static function related(array $related): array
    {
        return array_filter(array_map(static fn (array $ids) => new Identifiers($ids), $related), static fn (Identifiers $ids) => \count($ids) > 0);
    }
}
