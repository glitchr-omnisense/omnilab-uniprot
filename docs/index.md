# omnilab/uniprot

UniProtKB through UniProt's REST API (`https://rest.uniprot.org/uniprotkb/`) for
[omnisense/omnilab](https://github.com/glitchr-omnisense/omnilab): proteins by accession or entry
name - function, gene, organism, sequence, mass, cofactors, ligands - with their genes in NCBI
Gene and Ensembl as cross-references, their structures, cofactors and ligands as related things,
as entities; a search in UniProt's query syntax. No key.

## Installation

```sh
composer require omnilab/uniprot
```

```php
use Omnilab\UniProt\UniProtSourceFactory;

$uniprot = (new UniProtSourceFactory($httpClient))->create([
    'mailto' => 'support@glitchr.io',  // optional
    'sequence' => 2000,                // optional
    'structures' => 20,                // optional
]);
```

With the Symfony bundle, the factory is registered as `uniprot` (see the core's docs).

## Credentials

None: UniProt's REST API takes no key and no account. Give `mailto`, the address UniProt writes to
before it blocks a caller.

## Options

The factory's options, with the defaults it applies (`UniProtSourceFactory`):

| Option | Type | Default | Required | Effect |
|---|---|---|---|---|
| `sequence` | `int` | `2000` | no | the longest sequence given as the `sequence` property, in residues; 0: none |
| `structures` | `int` | `20` | no | how many PDB structures to keep in `related('structure')`, in UniProt's order; 0: none |
| `mailto` | `?string` | null | no | a contact address, in the User-Agent: whom UniProt writes to before it blocks a caller |
| `user_agent` | `?string` | null | no | the whole User-Agent, in place of the one made from `mailto` |
| `throttle` | `float` | `0` | no | seconds left between two calls: UniProt publishes no figure |
| `base_uri` | `string` | `https://rest.uniprot.org/uniprotkb/` | no | the API the source calls |

## Capabilities

`capabilities()` answers `entity`, `search`, `cross_references`; an operation it does not answer throws `NotSupportedException` before any call.

| Capability | Supported | |
|---|---|---|
| `entity` | yes | an accession (`P68871`, `uniprot:`, `uniprotkb:`, its page) or an entry name (`HBB_HUMAN`); a merged accession follows its target; another scheme refused before any call; one call |
| `search` | yes | UniProt's query syntax (`gene:HBB AND organism_id:9606`), 500 a page at most, the next page by the `Link` header's cursor, the total from `X-Total-Results`; a domain other than biology answers an empty page |
| `series` | no |  |
| `products` | no |  |
| `occurrences` | no |  |
| `datasets` | no |  |
| `jobs` | no |  |
| `open` | no |  |
| `structure` | no |  |
| `cross_references` | yes | its genes in NCBI Gene and Ensembl; its structures, cofactors, ligands and organism are `related` |
| `forecast` | no |  |
| `historical` | no |  |
| `area` | no |  |
| `station` | no |  |
| `cloud_cover` | no |  |

## Which API

The UniProt REST API, `https://rest.uniprot.org/uniprotkb/`, in JSON. Each call names the fields it
wants (`fields=`): an entry whole runs to hundreds of kilobytes (hemoglobin beta: 342 kB, most of it
variants, references and 348 PDB lines); the fields read here make 59 kB of it.

The source sends `Accept-Encoding: identity`. Asked for `gzip` alone - what Symfony's HTTP client
asks when it decompresses by itself - UniProt compresses the body twice (seen on 2026-10-15): the
client undoes one layer, the JSON stays compressed. `gzip, deflate`, `br` or `identity` are answered
right.

## Calls

| Method | Reads | UniProt |
|---|---|---|
| `entity()` | an accession (`P68871`, `uniprot:P68871`, `uniprotkb:P68871`, UniProt's page, identifiers.org) | `GET {accession}.json?fields=` |
| | an entry name (`HBB_HUMAN`) | `GET search?query=id:HBB_HUMAN AND active:true` |
| `search()` | `Query::$text` in UniProt's query syntax (`hemoglobin beta`, `gene:HBB AND organism_id:9606`); a domain other than biology answers nothing | `GET search?query=&size=&cursor=` |

A bare string of an accession's shape is read as one: `Q60235` is a UniProt protein here (a type
III secretion protein of *Pseudomonas syringae*), not the Wikidata item of caffeine - give an
`Identifier` or `wikidata:Q60235` for that, which the source refuses. Anything else (a protein's
name, a gene, a ChEBI id) is refused before any call; search for a name.

An accession that was merged into another is answered `303` to that other: an HTTP client that
follows redirections (Symfony's does) brings the entry whole, its fields dropped on the way; one
that does not gives the inactive record (`MERGED`, `mergeDemergeTo`), and the source asks the
target. An entry demerged into several (`P02023`, an old accession of hemoglobin beta, now three
entries) or deleted is `null`, as is an unknown accession (404).

The search pages by a cursor UniProt gives in the `Link` header of its answer (`rel="next"`), the
total in `X-Total-Results`; `Page::$next` is that cursor.

## What an entity holds

`id` (its primary accession), `label` (the recommended name; an unreviewed entry's submitted name),
`domain` biology, `kind` `protein`, `synonyms` (its alternative names, full and short),
`description` (its function, as UniProt writes it, the PubMed citations in the text taken out; the
functions of the peptides it is cleaved into left out), `url` (its page), and these properties:

| Key | UniProt | Value |
|---|---|---|
| `gene` | the gene's name (else its ordered locus or ORF name) | text |
| `organism`, `organism_common_name` | organism | text |
| `sequence_length` | sequence.length | Quantity, aa |
| `mass` | sequence.molWeight (the canonical sequence) | Quantity, Da |
| `sequence` | sequence.value, when no longer than the `sequence` option | text |
| `reviewed` | Swiss-Prot (reviewed) or TrEMBL | boolean |
| `protein_existence` | proteinExistence, without its rank | text |
| `annotation_score` | annotationScore, 1 to 5 | number |
| `ec_number` | the recommended name's EC numbers | text |
| `subcellular_location` | the SUBCELLULAR LOCATION comments' locations | text |
| `cofactor` | the COFACTOR comments' cofactors | text |
| `ligand` | the ligands of the binding sites that have a ChEBI id, each once | text |
| `keyword` | the keywords | text |

`identifiers`: its NCBI Gene id (`GeneID`), then its Ensembl gene ids (each once, without their
version) - the gene is what UniProt, NCBI and Wikidata share. `related`: `organism` (its NCBI
taxon), `cofactor` and `ligand` (their ChEBI ids), `structure` (its PDB entries, in UniProt's order,
20 by default). These are other things than the protein: the Collector does not follow them, the
Merger does not join two proteins of one organism.

A search result holds the names, gene, organism, function, length, mass, existence, score and
genes: `entity()` reads the rest.

## What UniProt does not give here

No Wikidata item (UniProt keeps no cross-reference to it: Wikidata finds the protein by its
accession, P352). Not read: the secondary accessions (not among the fields one can ask), the
isoforms, the features other than binding sites (variants, domains, modified residues), the
diseases, interactions, GO terms, references, the HGNC and OMIM cross-references (schemes the core
has, not read here: omnilab/hgnc gives them) and those of schemes it does not know (RefSeq,
DrugBank, AlphaFold DB...). The ChEMBL target an entry names
(`CHEMBL4331` for hemoglobin beta) is not taken: the core's `chembl` scheme names molecules.
UniRef, UniParc, proteomes and the ID mapping service are not used.

## The Collector

Hemoglobin beta from UniProt into NCBI Gene and Wikidata, answered on 2026-10-15: see the core's
`docs/merging.md`. UniProt gives the gene (GeneID 3043), NCBI's gene report gives the accession
back (`swiss_prot_accessions`), Wikidata's protein item (Q424422) carries it in P352.

## Example

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Omnilab\Model\Scheme;
use Omnilab\Source\Query;
use Omnilab\UniProt\UniProtSourceFactory;
use Symfony\Component\HttpClient\HttpClient;

$uniprot = (new UniProtSourceFactory(HttpClient::create()))->create(['mailto' => 'you@example.org', 'sequence' => 0, 'structures' => 3]);

$hbb = $uniprot->entity('HBB_HUMAN');   // by its entry name
echo $hbb->label, ' (', $hbb->id, '): gene ', $hbb->value('gene'), ', ', $hbb->value('organism'), ', ', $hbb->property('sequence_length'), ', ', $hbb->property('mass'), "\n";
echo '  NCBI Gene ', $hbb->identifier(Scheme::NCBI_GENE), ', Ensembl ', $hbb->identifier(Scheme::ENSEMBL), '; structures ', implode(', ', $hbb->related('structure')->values(Scheme::PDB)), '; ligands ', implode(', ', array_map('strval', $hbb->properties('ligand'))), "\n";

$page = $uniprot->search(new Query('gene:HBB AND organism_id:9606', limit: 3));
printf("%d entries for gene:HBB in human; the first %d: %s\n", $page->total, count($page), implode(', ', array_map(static fn ($e) => $e->id->value.' '.$e->label, $page->items)));
```

```
Hemoglobin subunit beta (uniprot:P68871): gene HBB, Homo sapiens, 147 aa, 15998 Da
  NCBI Gene 3043, Ensembl ENSG00000244734; structures 1A00, 1A01, 1A0U; ligands (2R)-2,3-bisphosphoglycerate, heme b
18 entries for gene:HBB in human; the first 3: P68871 Hemoglobin subunit beta, F8W6P5 Hemoglobin subunit beta, Q7Z2K5 Beta-globin
```

(as answered on 2026-10-27: three calls - the search by entry name, the entry, the search)

```yaml
# config/packages/omnilab.yaml
omnilab:
    sources:
        uniprot: { factory: uniprot, options: { mailto: '%env(OMNILAB_MAILTO)%', sequence: 2000, structures: 20 } }
```

## Errors

Every call goes through `HttpSource`: a 404 or a 410 (and a 204) is null - an empty page for a list -; a 429 is a `RateLimitedException` (`retryAfter` from `Retry-After`); a 5xx, a time-out or a host that does not answer an `UnavailableException`; any other 4xx a `ProviderException` with its `status` and what the service said; an answer that is not JSON a `ProviderException`, a web page answered for JSON an `UnavailableException`.

UniProt's own: its `messages` are read into the message of a `ProviderException` (a query it
cannot parse: its 400); an unknown accession is its 404, null; an accession demerged into several
or deleted is null; a merge is followed (three at most). Before any call: a name, a gene, any
other scheme (`NotSupportedException`): search for a name.

## Verified, and not

Against the real service on 2026-10-15 (UniProt release 2026_03), without a key: hemoglobin beta
by its accession, its entry name and an accession merged into it (A4GX73: 303 to P68871); its
names, gene, organism, length (147 aa), mass (15998 Da), sequence, ligands (heme b,
2,3-bisphosphoglycerate), 20 keywords, its gene 3043 and ENSG00000244734, its 348 PDB structures;
carbonic anhydrase 2 (P00918: zinc and cobalt as cofactors, EC 4.2.1.1, cytoplasm and cell
membrane, 1200 PDB structures); a demerged accession (P02023), a deleted one (Q9ZZZ9), an unknown
one (A0A0A0A0A0: 404); an unreviewed entry (D9YZU5, TrEMBL: its submitted name "Beta-globin",
the same gene 3043, existence inferred from homology, score 1); a search for "hemoglobin beta" (57554 entries, three a page) and its second
page by the cursor of the Link header; the double compression of a body asked for `gzip` alone.
The fixtures in `Tests/Fixtures/` are its answers, carbonic anhydrase 2's PDB lines cut to 30.

Again on 2026-10-27: hemoglobin beta by its entry name, its gene, structures and ligands, the
search for `gene:HBB AND organism_id:9606` (18 entries; the example above).

Not verified: UniProt's answer to a client that calls too fast (no 429 met outside the stream
endpoint - the handling is the core's); an obsolete entry merged twice over (the source follows
three merges at most).

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by the UniProt Consortium. UniProt's data are under
[CC BY 4.0](https://creativecommons.org/licenses/by/4.0/): cite UniProt where they are shown, as
the README's [Terms of use](https://github.com/glitchr-omnisense/omnilab-uniprot#terms-of-use)
says. Whoever runs the package is responsible for complying with it and for any use made of what
it returns: see the README's
[Responsibility](https://github.com/glitchr-omnisense/omnilab-uniprot#responsibility) section.
