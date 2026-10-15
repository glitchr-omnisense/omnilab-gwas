<?php

namespace Omnilab\Gwas\Tests;

use Omnilab\Exception\NotSupportedException;
use Omnilab\Exception\ProviderException;
use Omnilab\Exception\UnavailableException;
use Omnilab\Gwas\GwasSource;
use Omnilab\Gwas\GwasSourceFactory;
use Omnilab\Model\Domain;
use Omnilab\Model\Identifier;
use Omnilab\Model\Scheme;
use Omnilab\Source\Capability;
use Omnilab\Source\Query;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Fixtures recorded from www.ebi.ac.uk/gwas/rest/api/v2 on 2026-10-15: the
 * study GCST000854 and its four associations; rs334 and its 134
 * associations (the first ten, and the second page of five), the smallest
 * p-value first; sickle cell disease (MONDO_0011382) as a trait and its
 * eight studies; the traits named "sickle", and none named "zzqqxx"; an rs
 * id the catalogue does not hold (404).
 */
final class GwasSourceTest extends TestCase
{
    /** @var list<string> the paths asked, with their queries */
    private array $calls = [];

    private function source(?MockResponse $response = null, array $options = []): GwasSource
    {
        $http = new MockHttpClient(function (string $method, string $url) use ($response): MockResponse {
            $path = (string) parse_url($url, \PHP_URL_PATH);
            self::assertStringStartsWith('/gwas/rest/api/v2/', $path);
            $raw = (string) parse_url($url, \PHP_URL_QUERY);
            $this->calls[] = $call = substr($path, \strlen('/gwas/rest/api/v2/')).('' === $raw ? '' : '?'.rawurldecode($raw));
            if ($response) {
                return $response;
            }
            $file = __DIR__.'/Fixtures/'.preg_replace('~[?&=/]~', '_', $call).'.json';

            $body = is_file($file) ? (string) file_get_contents($file) : '{"errorCode":404,"error":"Not Found","errorMessage":"not found"}';

            return new MockResponse($body, ['http_code' => str_contains($body, '"errorCode":404') || str_contains($body, '"errorCode": 404') ? 404 : 200]);
        });
        $source = (new GwasSourceFactory($http))->create($options);
        self::assertInstanceOf(GwasSource::class, $source);

        return $source;
    }

    public function testAStudyAndItsAssociations(): void
    {
        $study = $this->source()->entity('GCST000854');

        self::assertSame('gwas:GCST000854', $study->id->key());
        self::assertSame('Suicide risk', $study->label);
        self::assertSame(Domain::BIOLOGY, $study->domain);
        self::assertSame('GWAS study', $study->kind);
        self::assertSame('https://www.ebi.ac.uk/gwas/studies/GCST000854', $study->url);
        self::assertSame(['attempted suicide', 'mental disorder'], array_map('strval', $study->properties('trait')));
        self::assertSame(['efo:0004321', 'mondo:0005084'], $study->related('trait')->keys());
        self::assertSame(1922309, $study->value('snp_count'));
        self::assertSame('4390 European (U.K.)', $study->value('discovery_ancestry'));
        self::assertTrue($study->value('imputed'));
        self::assertFalse($study->value('full_summary_stats_available'));
        self::assertNull($study->property('summary_statistics'), '"NA": left out');
        self::assertSame('21041247', $study->value('pubmed'));
        self::assertSame(4, $study->value('associations'));
        self::assertSame('rs4918918-T (SORBS1): p = 3e-6, Suicide risk', $study->value('association'));
        self::assertSame(['dbsnp:rs4918918', 'dbsnp:rs10854398', 'dbsnp:rs2462021', 'dbsnp:rs12373805'], $study->related('variant')->keys());
        self::assertSame(['studies/GCST000854', 'associations?accession_id=GCST000854&page=0&size=10&sort=p_value&direction=asc'], $this->calls);
    }

    public function testAVariant(): void
    {
        $snp = $this->source()->entity(Identifier::of(Scheme::DBSNP, 'rs334'));

        self::assertSame('dbsnp:rs334', (string) $snp->id);
        self::assertSame('rs334', $snp->label);
        self::assertSame('variant', $snp->kind);
        self::assertSame('https://www.ncbi.nlm.nih.gov/snp/rs334', $snp->url);
        self::assertSame('missense variant', $snp->value('functional_class'));
        self::assertSame('T/A/C/G (forward)', $snp->value('alleles'));
        self::assertSame('11:5227002', $snp->value('genomic_location'));
        self::assertSame('11p15.4', $snp->value('map_location'));
        self::assertSame('HBB', $snp->value('gene'));
        self::assertSame(134, $snp->value('associations'));
        self::assertCount(10, $snp->properties('association'));
        self::assertSame('rs334-T (HBB): p = 1e-323, Hereditary hemolytic anemias (PheCode 282)', $snp->value('association'), 'the mantissa and exponent: a float would underflow further down');
        self::assertSame('Association (GCST90475778)', $snp->property('association')->label);
        self::assertSame('gwas:GCST90475778', (string) $snp->related('study')->all()[0]);
        self::assertContains('mondo:0003689', $snp->related('associated_trait')->keys());
    }

    public function testATrait(): void
    {
        $trait = $this->source(null, ['associations' => 10])->entity('MONDO:0011382');

        self::assertSame('mondo:0011382', (string) $trait->id);
        self::assertSame('sickle cell disease', $trait->label);
        self::assertSame('trait', $trait->kind);
        self::assertSame('https://www.ebi.ac.uk/gwas/efotraits/MONDO_0011382', $trait->url);
        self::assertSame(8, $trait->value('studies'));
        self::assertSame('Sickle cell anemia (PheCode 282.5) (GCST90481652)', $trait->value('study'));
        self::assertCount(8, $trait->related('study'));
        self::assertSame(['efo-traits/MONDO_0011382', 'studies?efo_id=MONDO_0011382&page=0&size=10'], $this->calls, 'the catalogue writes MONDO_0011382');
    }

    public function testWithoutAssociationsOneCall(): void
    {
        $study = $this->source(null, ['associations' => 0])->entity('GCST000854');

        self::assertNull($study->property('association'));
        self::assertCount(1, $this->calls);
    }

    public function testUnknownIsNull(): void
    {
        self::assertNull($this->source()->entity('rs999999999999'));
        self::assertNull($this->source()->entity('GCST999999999'));
        self::assertCount(2, $this->calls, 'no associations asked');
    }

    public function testAnotherBaseIsRefusedBeforeAnyCall(): void
    {
        foreach (['CHEBI:15422', 'HBB', 'clinvar:15333', 'P68871', Identifier::of(Scheme::NCBI_GENE, '3043')] as $id) {
            try {
                $this->source()->entity($id);
                self::fail(\sprintf('%s should be refused', $id));
            } catch (NotSupportedException) {
            }
        }
        self::assertSame([], $this->calls);
    }

    public function testASearchOfTraits(): void
    {
        $source = $this->source();
        $found = $source->search(new Query('sickle', limit: 3));

        self::assertSame(['efo:0700066', 'mondo:0011382'], array_map(static fn ($e) => (string) $e->id, $found->items));
        self::assertSame('sickle cell disease and related diseases', $found->first()->label);
        self::assertSame(2, $found->total);
        self::assertNull($found->next);
        self::assertCount(0, $source->search(new Query('zzqqxx')));
        self::assertCount(0, $source->search(new Query('sickle', Domain::EARTH)));
        self::assertCount(2, $this->calls);
    }

    public function testAssociationsInPages(): void
    {
        $page = $this->source()->associations('rs334', 5, '1');

        self::assertSame(134, $page->total);
        self::assertSame('2', $page->next);
        self::assertCount(5, $page);
        $first = $page->first();
        self::assertSame(['dbsnp:rs334'], $first->variants->keys());
        self::assertSame(['rs334-T'], $first->riskAlleles);
        self::assertNotNull($first->study);
        self::assertNotNull($first->pValueText);
        self::assertSame(['HBB'], $first->genes);
        self::assertSame('associations?rs_id=rs334&page=1&size=5&sort=p_value&direction=asc', $this->calls[0]);
    }

    public function testErrors(): void
    {
        try {
            $this->source(new MockResponse('{"errorCode":400,"error":"Bad Request","errorMessage":"Invalid sort field"}', ['http_code' => 400]))->entity('GCST000854');
            self::fail('a 400');
        } catch (ProviderException $e) {
            self::assertSame(400, $e->status);
            self::assertStringContainsString('Invalid sort field', $e->getMessage());
        }
        $this->expectException(UnavailableException::class);
        $this->source(new MockResponse('', ['http_code' => 503]))->entity('GCST000854');
    }

    public function testTheFactory(): void
    {
        $factory = new GwasSourceFactory(new MockHttpClient());

        self::assertSame('gwas', $factory->getName());
        self::assertSame([Capability::ENTITY, Capability::SEARCH], $factory->create()->capabilities());
    }
}
