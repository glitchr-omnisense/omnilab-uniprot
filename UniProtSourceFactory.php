<?php

namespace Omnilab\UniProt;

use Omnilab\Config;
use Omnilab\Source\SourceFactory;
use Omnilab\Source\SourceInterface;
use Symfony\Component\HttpClient\HttpClient;

/**
 * UniProtKB: proteins, their function, gene, organism, sequence, cofactors
 * and ligands, their genes in NCBI Gene and Ensembl - no key needed.
 *
 *   options:
 *     mailto: 'support@glitchr.io'     # in the User-Agent: whom UniProt writes to before it blocks a caller
 *     sequence: 2000                   # the longest sequence given as a property, in residues (0: none)
 *     structures: 20                   # how many PDB structures to keep among the related things (0: none)
 *     throttle: 0                      # seconds between two calls: UniProt publishes no figure
 *     base_uri: 'https://rest.uniprot.org/uniprotkb/'
 */
final class UniProtSourceFactory extends SourceFactory
{
    protected function populate(Config $c): void
    {
        $c->defaults([
            'omnilab.factory_name' => 'uniprot',
            'omnilab.required_options' => [],
            'sequence' => 2000,
            'structures' => 20,
            'throttle' => 0.0,
            'base_uri' => UniProtSource::BASE_URI,
        ]);
    }

    protected function build(Config $c): SourceInterface
    {
        return new UniProtSource(
            $this->http ?? HttpClient::create(),
            (int) $c->get('sequence'),
            (int) $c->get('structures'),
            (string) $c->get('base_uri', UniProtSource::BASE_URI),
            ['User-Agent' => self::userAgent($c)],
            (float) $c->get('throttle'),
        );
    }
}
