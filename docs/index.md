# omnilab/uniprot

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
diseases, interactions, GO terms, references and the cross-references of schemes the core does not
know (RefSeq, HGNC, OMIM, DrugBank, AlphaFold DB...). The ChEMBL target an entry names
(`CHEMBL4331` for hemoglobin beta) is not taken: the core's `chembl` scheme names molecules.
UniRef, UniParc, proteomes and the ID mapping service are not used.

## The Collector

Hemoglobin beta from UniProt into NCBI Gene and Wikidata, answered on 2026-10-15: see the core's
`docs/merging.md`. UniProt gives the gene (GeneID 3043), NCBI's gene report gives the accession
back (`swiss_prot_accessions`), Wikidata's protein item (Q424422) carries it in P352.

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

Not verified: UniProt's answer to a client that calls too fast (no 429 met outside the stream
endpoint - the handling is the core's); an obsolete entry merged twice over (the source follows
three merges at most).
