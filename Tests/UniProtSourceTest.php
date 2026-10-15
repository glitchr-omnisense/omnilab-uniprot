<?php

namespace Omnilab\UniProt\Tests;

use Omnilab\Exception\NotSupportedException;
use Omnilab\Exception\RateLimitedException;
use Omnilab\Exception\UnavailableException;
use Omnilab\Model\Domain;
use Omnilab\Model\Identifier;
use Omnilab\Model\Point;
use Omnilab\Model\Quantity;
use Omnilab\Model\Scheme;
use Omnilab\Source\Capability;
use Omnilab\Source\Query;
use Omnilab\Source\SeriesQuery;
use Omnilab\Source\SourceInterface;
use Omnilab\UniProt\UniProtSource;
use Omnilab\UniProt\UniProtSourceFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Fixtures recorded from rest.uniprot.org (release 2026_03) on 2026-10-15,
 * with the fields the source asks for: entry-P68871.json (hemoglobin
 * subunit beta, human), entry-P00918.json (carbonic anhydrase 2, human: a
 * cofactor, an EC number, a location; its 1200 PDB structures cut to 30),
 * entry-A4GX73.json (an accession merged into P68871: UniProt's 303 and
 * its body), entry-P02023.json (demerged into three), entry-Q9ZZZ9.json
 * (deleted), entry-notfound.json (404), search-id-HBB_HUMAN.json (the
 * entry name), search-hemoglobin-beta-1.json and -2.json (three a page,
 * the second by the cursor of the first's Link header).
 */
final class UniProtSourceTest extends TestCase
{
    private const NEXT = '8e9tj6dnc2z3t8t0m5kunyvdpvtn4hojuh';
    private const AFTER = '8vzrjg5vdsq37mbf6vtd2netfsr2e3gf1s';

    /** @var list<string> */
    private array $calls = [];

    /** @var list<array<string, string>> */
    private array $headers = [];

    private function source(?MockResponse $response = null, array $options = []): SourceInterface
    {
        $http = new MockHttpClient(function (string $method, string $url, array $request) use ($response): MockResponse {
            $path = substr((string) parse_url($url, \PHP_URL_PATH), \strlen('/uniprotkb/'));
            parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
            $this->calls[] = $path.('search' === $path ? '?query='.$query['query'].(isset($query['cursor']) ? '&cursor='.$query['cursor'] : '') : '');
            $this->headers[] = $request['normalized_headers'] ?? [];
            if ($response) {
                return $response;
            }
            if ('search' === $path) {
                $link = static fn (string $cursor) => '<https://rest.uniprot.org/uniprotkb/search?fields='.UniProtSource::SEARCH_FIELDS.'&query=hemoglobin%20beta&cursor='.$cursor.'&size=3>; rel="next"';

                return match (true) {
                    'id:HBB_HUMAN AND active:true' === $query['query'] => self::fixture('search-id-HBB_HUMAN.json', ['x-total-results' => '1']),
                    'hemoglobin beta' === $query['query'] && !isset($query['cursor']) => self::fixture('search-hemoglobin-beta-1.json', ['link' => $link(self::NEXT), 'x-total-results' => '57554']),
                    'hemoglobin beta' === $query['query'] && self::NEXT === $query['cursor'] => self::fixture('search-hemoglobin-beta-2.json', ['link' => $link(self::AFTER), 'x-total-results' => '57554']),
                    default => new MockResponse('{"results":[]}', ['response_headers' => ['x-total-results' => '0']]),
                };
            }

            return match ($path) {
                'P68871.json', 'P00918.json', 'P02023.json', 'Q9ZZZ9.json' => self::fixture('entry-'.$path),
                // UniProt's 303 to the entry it was merged into: MockHttpClient does not follow it.
                'A4GX73.json' => self::fixture('entry-A4GX73.json', ['location' => '/uniprotkb/P68871?from=A4GX73'], 303),
                default => self::fixture('entry-notfound.json', [], 404),
            };
        });

        return (new UniProtSourceFactory($http))->create($options);
    }

    /** @param array<string, string> $headers */
    private static function fixture(string $name, array $headers = [], int $status = 200): MockResponse
    {
        return new MockResponse((string) file_get_contents(__DIR__.'/Fixtures/'.$name), ['http_code' => $status, 'response_headers' => $headers]);
    }

    public function testHemoglobinBetaByItsAccessionInOneCall(): void
    {
        $hbb = $this->source()->entity('P68871');

        self::assertSame('uniprot:P68871', $hbb->id->key());
        self::assertSame('Hemoglobin subunit beta', $hbb->label);
        self::assertSame(Domain::BIOLOGY, $hbb->domain);
        self::assertSame('protein', $hbb->kind);
        self::assertSame(['Beta-globin', 'Hemoglobin beta chain'], $hbb->synonyms);
        self::assertStringStartsWith('Involved in oxygen transport from the lung to the various peripheral tissues.', $hbb->description);
        self::assertStringNotContainsString('Spinorphin', $hbb->description, 'a peptide\'s own function left out');
        self::assertSame('HBB', $hbb->value('gene'));
        self::assertSame('Homo sapiens', $hbb->value('organism'));
        self::assertSame('Human', $hbb->value('organism_common_name'));
        self::assertEquals(new Quantity(147, 'aa'), $hbb->value('sequence_length'));
        self::assertEquals(new Quantity(15998, 'Da'), $hbb->value('mass'));
        self::assertStringStartsWith('MVHLTPEEKSAVTALWGKVNVDEVGGEALGRLLVVYPWTQRFFESFGDLSTPDAVMGNPKVKAHGKK', $hbb->value('sequence'));
        self::assertSame(147, \strlen($hbb->value('sequence')));
        self::assertTrue($hbb->value('reviewed'));
        self::assertSame('Evidence at protein level', $hbb->value('protein_existence'));
        self::assertSame(5, $hbb->value('annotation_score'));
        self::assertSame(['(2R)-2,3-bisphosphoglycerate', 'heme b'], array_map('strval', $hbb->properties('ligand')));
        self::assertContains('Oxygen transport', array_map('strval', $hbb->properties('keyword')));
        self::assertSame('uniprot', $hbb->property('mass')->source);
        self::assertSame('https://www.uniprot.org/uniprotkb/P68871/entry', $hbb->url);
        self::assertSame(['P68871.json'], $this->calls);
    }

    public function testItsGenesAreCrossReferencesItsOrganismStructuresAndLigandsRelated(): void
    {
        $hbb = $this->source()->entity(Identifier::of(Scheme::UNIPROT, 'P68871'));

        self::assertSame(['uniprot:P68871', 'ncbi.gene:3043', 'ensembl:ENSG00000244734'], $hbb->identifiers()->keys(), 'NCBI Gene first, the Ensembl gene once (three transcripts name it), its version dropped');
        self::assertSame(['organism', 'ligand', 'structure'], array_keys($hbb->related));
        self::assertSame(['ncbi.taxon:9606'], $hbb->related('organism')->keys());
        self::assertSame(['chebi:58248', 'chebi:60344'], $hbb->related('ligand')->keys());
        self::assertCount(20, $hbb->related('structure'), '348 in the entry, 20 kept');
        self::assertSame('pdb:1A00', $hbb->related('structure')->all()[0]->key());
        self::assertFalse($hbb->identifiers()->has(Scheme::NCBI_TAXON), 'the organism is not the protein: the Collector does not follow it');

        $bare = $this->source(null, ['structures' => 0, 'sequence' => 100])->entity('P68871');
        self::assertCount(0, $bare->related('structure'));
        self::assertNull($bare->value('sequence'), 'longer than asked');
        self::assertEquals(new Quantity(147, 'aa'), $bare->value('sequence_length'));
    }

    public function testAnEnzymeItsCofactorsLocationAndEcNumber(): void
    {
        $ca2 = $this->source()->entity('uniprot:P00918');

        self::assertSame('Carbonic anhydrase 2', $ca2->label);
        self::assertSame('4.2.1.1', $ca2->value('ec_number'));
        self::assertSame(['Zn(2+)', 'Co(2+)'], array_map('strval', $ca2->properties('cofactor')));
        self::assertSame(['chebi:29105', 'chebi:48828'], $ca2->related('cofactor')->keys());
        self::assertSame(['Cytoplasm', 'Cell membrane'], array_map('strval', $ca2->properties('subcellular_location')));
        self::assertSame(['Zn(2+)'], array_map('strval', $ca2->properties('ligand')), 'a ligand without a ChEBI id ("substrate") left out');
        self::assertStringStartsWith('Catalyzes the reversible hydration of carbon dioxide. Can also hydrate cyanamide to urea.', $ca2->description, 'the PubMed citations taken out');
        self::assertContains('Carbonic anhydrase II', $ca2->synonyms);
        self::assertContains('CA-II', $ca2->synonyms, 'short names too');
        self::assertSame(['uniprot:P00918', 'ncbi.gene:760', 'ensembl:ENSG00000104267'], $ca2->identifiers()->keys());
    }

    public function testAMergedAccessionGivesTheEntryItWasMergedInto(): void
    {
        self::assertSame('uniprot:P68871', $this->source()->entity('A4GX73')?->id->key());
        self::assertSame(['A4GX73.json', 'P68871.json'], $this->calls);
    }

    public function testDemergedDeletedOrUnknownIsNull(): void
    {
        self::assertNull($this->source()->entity('P02023'), 'demerged into three: none of them is it');
        self::assertNull($this->source()->entity('Q9ZZZ9'), 'deleted');
        self::assertNull($this->source()->entity('A0A0A0A0A0'));
    }

    public function testByItsEntryNameThroughTheSearch(): void
    {
        self::assertSame('uniprot:P68871', $this->source()->entity('HBB_HUMAN')?->id->key());
        self::assertSame(['search?query=id:HBB_HUMAN AND active:true'], $this->calls);
        self::assertSame('uniprot:P68871', $this->source()->entity('hbb_human')?->id->key());
    }

    public function testABareAccessionIsReadAsUniProtsFirst(): void
    {
        $this->source()->entity('Q60235');
        self::assertSame(['Q60235.json'], $this->calls, 'Q60235 is a UniProt accession too');
    }

    public function testWhatItDoesNotReadIsRefusedBeforeAnyCall(): void
    {
        foreach ([Identifier::wikidata('Q60235'), Identifier::of(Scheme::NCBI_GENE, '3043'), 'CHEBI:27732', 'ENSG00000244734', 'hemoglobin beta', 'https://www.wikidata.org/wiki/Q424422'] as $id) {
            try {
                $this->source()->entity($id);
                self::fail('not read: '.$id);
            } catch (NotSupportedException) {
            }
        }
        self::assertSame([], $this->calls);
    }

    public function testSearchPageByPageByTheLinkHeadersCursor(): void
    {
        $source = $this->source();
        $page = $source->search(new Query('hemoglobin beta', limit: 3));

        self::assertSame(['uniprot:P68871', 'uniprot:P11517', 'uniprot:P84792'], array_map(static fn ($e) => $e->id->key(), $page->items));
        self::assertSame(['Homo sapiens', 'Rattus norvegicus', 'Aythya fuligula'], array_map(static fn ($e) => $e->value('organism'), $page->items));
        self::assertSame(57554, $page->total);
        self::assertSame(self::NEXT, $page->next);
        self::assertSame('3043', $page->first()->identifier(Scheme::NCBI_GENE));
        self::assertNull($page->first()->value('sequence'), 'a search result holds no sequence');

        $second = $source->search(new Query('hemoglobin beta', limit: 3, cursor: $page->next));
        self::assertSame(['uniprot:P02087', 'uniprot:P02088', 'uniprot:P02094'], array_map(static fn ($e) => $e->id->key(), $second->items));
        self::assertSame(self::AFTER, $second->next);
        self::assertSame(['search?query=hemoglobin beta', 'search?query=hemoglobin beta&cursor='.self::NEXT], $this->calls);

        self::assertSame([], $source->search(new Query('hemoglobin', Domain::CHEMISTRY))->items, 'nothing of another domain');
        self::assertCount(2, $this->calls, 'and no call for it');
        $none = $source->search(new Query('nothingatallxyz'));
        self::assertSame([0, true], [$none->total, $none->isLast()]);
    }

    public function testItAsksForAnUncompressedBody(): void
    {
        $this->source()->entity('P68871');
        self::assertSame(['Accept-Encoding: identity'], $this->headers[0]['accept-encoding'], 'asked for "gzip" alone, UniProt compresses twice');
    }

    public function testErrorsMapTheSameWayAsEverySource(): void
    {
        try {
            $this->source(new MockResponse('<html>Bad Gateway</html>', ['http_code' => 502]))->entity('P68871');
            self::fail('a 502 is an UnavailableException');
        } catch (UnavailableException $e) {
            self::assertSame(502, $e->status);
        }
        $this->expectException(RateLimitedException::class);
        $this->source(new MockResponse('', ['http_code' => 429]))->entity('P68871');
    }

    public function testWhatItCanDo(): void
    {
        self::assertSame([Capability::ENTITY, Capability::SEARCH, Capability::CROSS_REFERENCES], $this->source()->capabilities());
        $this->expectException(NotSupportedException::class);
        $this->source()->series(new SeriesQuery(['temperature_2m'], new Point(48.57, 7.75)));
    }
}
