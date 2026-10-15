<?php

namespace Omnilab\UniProt;

use Omnilab\Exception\NotSupportedException;
use Omnilab\Model\Domain;
use Omnilab\Model\Entity;
use Omnilab\Model\Identifier;
use Omnilab\Model\Identifiers;
use Omnilab\Model\Property;
use Omnilab\Model\Quantity;
use Omnilab\Model\Scheme;
use Omnilab\Source\Capability;
use Omnilab\Source\HttpSource;
use Omnilab\Source\Page;
use Omnilab\Source\Query;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * UniProtKB, the protein knowledgebase of the UniProt consortium (EMBL-EBI,
 * SIB, PIR), through its REST API (rest.uniprot.org): a protein by its
 * accession (P68871) or its entry name (HBB_HUMAN); its names, gene,
 * organism, function, length, mass and sequence, where it sits in the cell,
 * its cofactors and the ligands of its binding sites, its keywords; its
 * NCBI Gene and Ensembl genes as cross-references, its organism, structures
 * and ligands as related things; and UniProt's search, page after page.
 *
 * No key. An entry costs one call, asked for the fields read here only
 * (an entry whole runs to hundreds of kilobytes); an accession merged into
 * another gives that other.
 */
final class UniProtSource extends HttpSource
{
    public const BASE_URI = 'https://rest.uniprot.org/uniprotkb/';

    /** The most entries one search page is asked for (UniProt stops at 500). */
    public const MAX_PER_PAGE = 500;

    /** The fields entity() asks for. */
    public const ENTRY_FIELDS = 'accession,id,reviewed,protein_name,gene_names,organism_name,organism_id,protein_existence,annotation_score,cc_function,cc_cofactor,cc_subcellular_location,ec,length,mass,sequence,keyword,ft_binding,xref_geneid,xref_ensembl,xref_pdb';

    /** The fields of a search result: no sequence, no structures. */
    public const SEARCH_FIELDS = 'accession,id,reviewed,protein_name,gene_names,organism_name,organism_id,protein_existence,annotation_score,cc_function,length,mass,xref_geneid,xref_ensembl';

    /** An entry name: a mnemonic and an organism code, HBB_HUMAN. */
    private const ENTRY_NAME = '~^[A-Z0-9]{1,16}_[A-Z0-9]{1,5}$~';

    /**
     * @param int $sequence   the longest sequence given as a property, in residues (0: none)
     * @param int $structures how many PDB structures to keep among the related things (0: none)
     */
    public function __construct(
        HttpClientInterface $http,
        private readonly int $sequence = 2000,
        private readonly int $structures = 20,
        string $baseUri = self::BASE_URI,
        array $headers = [],
        float $throttle = 0.0,
    ) {
        // Asked for "gzip" alone (what Symfony's client asks when it inflates), UniProt gzips twice: the body comes back still compressed.
        parent::__construct($http, $baseUri, $headers + ['Accept-Encoding' => 'identity'], $throttle);
    }

    public function getName(): string
    {
        return 'uniprot';
    }

    public function capabilities(): array
    {
        return [Capability::ENTITY, Capability::SEARCH, Capability::CROSS_REFERENCES];
    }

    public function entity(Identifier|string $id): ?Entity
    {
        if ($id instanceof Identifier) {
            $accession = Scheme::UNIPROT === $id->scheme ? $id->value : throw NotSupportedException::identifier('uniprot', (string) $id);
        } else {
            // A bare accession first: Q60235 is a UniProt accession here before it is a Wikidata item.
            $text = trim($id);
            $parsed = Identifier::tryOf(Scheme::UNIPROT, $text) ?? Identifier::parse($text);
            if (null === $parsed && preg_match(self::ENTRY_NAME, strtoupper($text))) {
                return $this->byEntryName(strtoupper($text));
            }
            if (null === $parsed || Scheme::UNIPROT !== $parsed->scheme) {
                throw NotSupportedException::identifier('uniprot', $id);
            }
            $accession = $parsed->value;
        }

        return $this->entry($accession);
    }

    /**
     * UniProt's search: the text in UniProt's query syntax ("hemoglobin
     * beta", "gene:HBB AND organism_id:9606", "reviewed:true AND ec:4.2.1.1"),
     * the best first; the cursor of the next page comes in the answer's
     * Link header, the total in X-Total-Results.
     */
    public function search(Query $query): Page
    {
        if (null !== $query->domain && Domain::BIOLOGY !== $query->domain) {
            return new Page();
        }
        $text = trim($query->text);
        if ('' === $text) {
            return new Page();
        }
        $answer = $this->getJsonWithHeaders('search', [
            'query' => $text,
            'fields' => self::SEARCH_FIELDS,
            'size' => max(1, min(self::MAX_PER_PAGE, $query->limit)),
            'cursor' => $query->cursor,
        ]);
        if (null === $answer) {
            return new Page([], null, 0);
        }
        [$data, $headers] = $answer;
        $entities = [];
        foreach ($data['results'] ?? [] as $entry) {
            if (\is_array($entry) && 'Inactive' !== ($entry['entryType'] ?? null)) {
                $entities[] = $this->toEntity($entry);
            }
        }
        $next = null;
        foreach ($headers['link'] ?? [] as $link) {
            if (preg_match('~<([^>]+)>\s*;\s*rel="?next"?~', $link, $m)) {
                parse_str((string) parse_url($m[1], \PHP_URL_QUERY), $params);
                $next = isset($params['cursor']) ? (string) $params['cursor'] : null;
            }
        }
        $total = $headers['x-total-results'][0] ?? null;

        return new Page($entities, $next, null === $total ? null : (int) $total);
    }

    /**
     * GET {accession}.json: null when UniProt does not know it (404), or
     * keeps it only as inactive (deleted, or demerged into several). An
     * accession merged into another answers 303 to that other: an HTTP
     * client that follows it brings that entry whole (UniProt drops the
     * fields on the way); one that does not gives the inactive record,
     * whose target is then asked.
     */
    private function entry(string $accession, int $hops = 0): ?Entity
    {
        $data = $this->getJson($accession.'.json', ['fields' => self::ENTRY_FIELDS]);
        if (null === $data || !isset($data['primaryAccession'])) {
            return null;
        }
        if ('Inactive' === ($data['entryType'] ?? null)) {
            $reason = $data['inactiveReason'] ?? [];
            $targets = $reason['mergeDemergeTo'] ?? [];

            return 'MERGED' === ($reason['inactiveReasonType'] ?? null) && 1 === \count($targets) && $hops < 3 && null !== ($target = Identifier::tryOf(Scheme::UNIPROT, (string) $targets[0]))
                ? $this->entry($target->value, $hops + 1) : null;
        }

        return $this->toEntity($data);
    }

    /** An entry name (HBB_HUMAN) through the search: id:HBB_HUMAN, the active entry; null when none. */
    private function byEntryName(string $name): ?Entity
    {
        $data = $this->getJson('search', ['query' => 'id:'.$name.' AND active:true', 'fields' => self::ENTRY_FIELDS, 'size' => 1]);
        $entry = $data['results'][0] ?? null;

        return \is_array($entry) && ($entry['uniProtkbId'] ?? null) === $name ? $this->toEntity($entry) : null;
    }

    /** @param array<string, mixed> $entry an entry as UniProt's JSON gives it, for the fields asked */
    private function toEntity(array $entry): Entity
    {
        $accession = (string) $entry['primaryAccession'];
        $description = $entry['proteinDescription'] ?? [];
        $recommended = $description['recommendedName'] ?? $description['submissionNames'][0] ?? [];
        $label = self::text($recommended['fullName']['value'] ?? null) ?? (string) ($entry['uniProtkbId'] ?? $accession);

        $synonyms = [];
        foreach ([$recommended, ...($description['alternativeNames'] ?? [])] as $name) {
            foreach ([$name['fullName']['value'] ?? null, ...array_map(static fn (array $s) => $s['value'] ?? null, $name['shortNames'] ?? [])] as $synonym) {
                if (null !== ($synonym = self::text($synonym))) {
                    $synonyms[mb_strtolower($synonym)] ??= $synonym;
                }
            }
        }
        unset($synonyms[mb_strtolower($label)]);

        $properties = [];
        $add = static function (string $key, string $label, Quantity|string|int|float|bool|null $value) use (&$properties): void {
            if (null !== $value && '' !== $value) {
                $properties[] = new Property($key, $label, $value, 'uniprot');
            }
        };
        $gene = $entry['genes'][0] ?? [];
        $add('gene', 'Gene', self::text($gene['geneName']['value'] ?? $gene['orderedLocusNames'][0]['value'] ?? $gene['orfNames'][0]['value'] ?? null));
        $organism = $entry['organism'] ?? [];
        $add('organism', 'Organism', self::text($organism['scientificName'] ?? null));
        $add('organism_common_name', 'Organism (common name)', self::text($organism['commonName'] ?? null));
        $sequence = $entry['sequence'] ?? [];
        $length = isset($sequence['length']) ? (int) $sequence['length'] : null;
        $add('sequence_length', 'Sequence length', null === $length ? null : new Quantity($length, 'aa'));
        $add('mass', 'Mass (of the canonical sequence)', isset($sequence['molWeight']) ? new Quantity((int) $sequence['molWeight'], 'Da') : null);
        if (null !== $length && $length <= $this->sequence) {
            $add('sequence', 'Sequence (canonical)', self::text($sequence['value'] ?? null));
        }
        $type = (string) ($entry['entryType'] ?? '');
        $add('reviewed', 'Reviewed (Swiss-Prot)', '' === $type ? null : str_contains($type, 'reviewed') && !str_contains($type, 'unreviewed'));
        $add('protein_existence', 'Protein existence', self::text(preg_replace('~^\d+:\s*~', '', (string) ($entry['proteinExistence'] ?? ''))));
        $score = $entry['annotationScore'] ?? null;
        $add('annotation_score', 'Annotation score (1 to 5)', is_numeric($score) ? ((float) $score === floor((float) $score) ? (int) $score : (float) $score) : null);
        foreach ($recommended['ecNumbers'] ?? [] as $ec) {
            $add('ec_number', 'EC number', self::text($ec['value'] ?? null));
        }

        $functions = [];
        $related = ['organism' => [Identifier::tryOf(Scheme::NCBI_TAXON, $organism['taxonId'] ?? null)], 'cofactor' => [], 'ligand' => [], 'structure' => []];
        foreach ($entry['comments'] ?? [] as $comment) {
            $type = $comment['commentType'] ?? null;
            // The protein's own function; a peptide it is cleaved into has its own ("molecule").
            if ('FUNCTION' === $type && !isset($comment['molecule'])) {
                foreach ($comment['texts'] ?? [] as $text) {
                    // The PubMed citations UniProt writes in the text are its evidence, not its sense.
                    $functions[] = self::text(preg_replace('~\s*\((?:PubMed:\d+(?:,\s*)?)+\)~', '', (string) ($text['value'] ?? '')));
                }
            } elseif ('SUBCELLULAR LOCATION' === $type) {
                foreach ($comment['subcellularLocations'] ?? [] as $location) {
                    $add('subcellular_location', 'Subcellular location', self::text($location['location']['value'] ?? null));
                }
            } elseif ('COFACTOR' === $type) {
                foreach ($comment['cofactors'] ?? [] as $cofactor) {
                    $add('cofactor', 'Cofactor', self::text($cofactor['name'] ?? null));
                    if ('ChEBI' === ($cofactor['cofactorCrossReference']['database'] ?? null)) {
                        $related['cofactor'][] = Identifier::tryOf(Scheme::CHEBI, $cofactor['cofactorCrossReference']['id'] ?? null);
                    }
                }
            }
        }
        $functions = array_values(array_filter($functions));
        $ligands = [];
        foreach ($entry['features'] ?? [] as $feature) {
            $ligand = $feature['ligand'] ?? null;
            // A ligand named but not identified ("substrate") is no thing of its own.
            if (\is_array($ligand) && null !== ($chebi = Identifier::tryOf(Scheme::CHEBI, preg_replace('~^ChEBI:~i', '', (string) ($ligand['id'] ?? ''))))) {
                $related['ligand'][] = $chebi;
                $ligands[$chebi->key()] ??= self::text($ligand['name'] ?? null);
            }
        }
        foreach (array_filter($ligands) as $name) {
            $add('ligand', 'Ligand (of a binding site)', $name);
        }
        foreach ($entry['keywords'] ?? [] as $keyword) {
            $add('keyword', 'Keyword', self::text($keyword['name'] ?? null));
        }

        $genes = $ensembl = [];
        $structures = 0;
        foreach ($entry['uniProtKBCrossReferences'] ?? [] as $xref) {
            $database = $xref['database'] ?? null;
            if ('GeneID' === $database) {
                $genes[] = Identifier::tryOf(Scheme::NCBI_GENE, $xref['id'] ?? null);
            } elseif ('Ensembl' === $database) {
                // A transcript's line names its gene: the gene is what the other bases share.
                foreach ($xref['properties'] ?? [] as $property) {
                    if ('GeneId' === ($property['key'] ?? null)) {
                        $ensembl[] = Identifier::tryOf(Scheme::ENSEMBL, $property['value'] ?? null);
                    }
                }
            } elseif ('PDB' === $database && $structures < $this->structures && null !== ($pdb = Identifier::tryOf(Scheme::PDB, $xref['id'] ?? null))) {
                $related['structure'][] = $pdb;
                ++$structures;
            }
        }

        return new Entity(
            id: Identifier::of(Scheme::UNIPROT, $accession),
            label: $label,
            domain: Domain::BIOLOGY,
            kind: 'protein',
            synonyms: array_values($synonyms),
            description: $functions ? implode(' ', array_map(static fn (string $f) => rtrim($f, '.').'.', $functions)) : null,
            properties: $properties,
            identifiers: new Identifiers([...$genes, ...$ensembl]),
            url: 'https://www.uniprot.org/uniprotkb/'.$accession.'/entry',
            source: 'uniprot',
            related: array_filter(array_map(static fn (array $ids) => new Identifiers($ids), $related), static fn (Identifiers $ids) => \count($ids) > 0),
        );
    }
}
