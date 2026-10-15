# omnilab/uniprot

UniProtKB for [glitchr/omnilab](https://github.com/glitchr-studio/omnilab): a protein by its
accession or its entry name; its names, gene, organism, function, length, mass and sequence, where
it sits in the cell, its cofactors and the ligands of its binding sites, its keywords; its genes in
NCBI Gene and Ensembl as cross-references for the Collector to follow, and its organism, its PDB
structures and its ligands as related things; UniProt's search, page after page. Through the
UniProt REST API, no key.

```php
$uniprot = (new UniProtSourceFactory($http))->create(['mailto' => 'support@glitchr.io']);
$hbb = $uniprot->entity('P68871');
$hbb->label;                                     // Hemoglobin subunit beta
$hbb->value('gene');                             // HBB
$hbb->property('mass');                          // 15998 Da
$hbb->property('sequence_length');               // 147 aa
$hbb->identifier(Scheme::NCBI_GENE);             // 3043
$hbb->related('organism')->value(Scheme::NCBI_TAXON); // 9606
$hbb->related('ligand')->keys();                 // chebi:58248, chebi:60344 (2,3-bisphosphoglycerate, heme b)
$uniprot->entity('HBB_HUMAN');                   // the same, by its entry name; 'A4GX73', merged into it, too
$uniprot->search(new Query('gene:HBB AND organism_id:9606', limit: 10));
```

```yaml
omnilab:
    sources:
        uniprot:
            factory: uniprot
            options:
                mailto: 'support@glitchr.io'   # in the User-Agent: whom UniProt writes to before it blocks a caller
                sequence: 2000                 # the longest sequence given as a property, in residues; 0: none
                structures: 20                 # how many PDB structures to keep among the related things; 0: none
```

With NCBI and Wikidata configured too, the `Collector` follows a protein to its gene and its
item: `$collector->collect('uniprot', 'P68871')` reaches NCBI Gene by GeneID 3043 and Wikidata by
the UniProt accession its item carries (P352).

## Terms of use

UniProt's data is under [CC BY 4.0](https://creativecommons.org/licenses/by/4.0/): cite UniProt
where you show it. No key, no account; no rate is published for the REST API (only the `stream`
endpoint, not used here, answers 429 when too many run at once). The source asks each entry for
the fields it reads - one call, a few dozen kilobytes instead of hundreds - and makes its calls in
series: cache what it answers.

See [docs/](docs/index.md) for what is read, how it maps, and what UniProt does not give here.

License: MIT.
