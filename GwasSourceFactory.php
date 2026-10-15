<?php

namespace Omnilab\Gwas;

use Omnilab\Config;
use Omnilab\Source\SourceFactory;
use Omnilab\Source\SourceInterface;
use Symfony\Component\HttpClient\HttpClient;

/**
 * The GWAS Catalog: studies, variants and traits with their strongest
 * associations, a search of traits, the associations paged - no key
 * needed.
 *
 *   options:
 *     mailto: 'support@glitchr.io'     # in the User-Agent
 *     associations: 10                 # associations (or a trait's studies) an entity holds (0: none, one call)
 *     throttle: 0                      # seconds between two calls: the catalogue publishes no rate
 *     base_uri: 'https://www.ebi.ac.uk/gwas/rest/api/v2/'
 */
final class GwasSourceFactory extends SourceFactory
{
    protected function populate(Config $c): void
    {
        $c->defaults([
            'omnilab.factory_name' => 'gwas',
            'omnilab.required_options' => [],
            'associations' => 10,
            'throttle' => 0.0,
            'base_uri' => GwasSource::BASE_URI,
        ]);
    }

    protected function build(Config $c): SourceInterface
    {
        return new GwasSource(
            $this->http ?? HttpClient::create(),
            (int) $c->get('associations'),
            (string) $c->get('base_uri', GwasSource::BASE_URI),
            ['User-Agent' => self::userAgent($c)],
            (float) $c->get('throttle'),
        );
    }
}
