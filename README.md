# CZ AI Agent

An AI agent for discovering an insurance database's structure, reasoning over
declared relationships, and answering policy questions with read-only query
evidence. The project is designed to support policy comparisons and grouped
summaries as well as individual record lookups.

## How question answering works

1. The application verifies the active knowledge library against the live
   database schema.
2. Schema retrieval ranks relevant tables and columns, prioritizes exact field
   names, adds insurance terminology from `InsuranceSchemaGlossary`, and
   includes declared relationship paths and qualified field candidates.
3. The language model returns constrained intent: fields to select, filters,
   optional grouping/aggregations, and sorting. It does not write SQL or joins.
4. Application code maps qualified candidates to tables, creates aliases, and
   builds the shortest unambiguous join path using declared foreign keys only.
   Ambiguous paths are rejected instead of guessed.
5. The query compiler validates identifiers, joins, limits, parameters, and
   result-size budgets before the read-only executor runs the query.
6. The answer generator summarizes only the returned database evidence. The
   response includes retrieval confidence and per-stage timings to make
   retrieval quality and live-schema validation cost measurable.

Questions asking for the latest or last record must sort first by a supported
date or sequence field in descending order. Identifier values are preserved
as strings so leading zeroes are not discarded; ambiguous references require
clarification rather than a guessed filter.

Inferred relationships remain unverified and are not used to construct joins.
Insurance terms and synonyms are maintained in
`src/Database/Context/InsuranceSchemaGlossary.php`; add domain-specific
mappings there only when they are supported by the database and business
definitions.

Database code is grouped by responsibility under `src/Database/`:

- `Connection/` creates database connections.
- `Context/` retrieves relevant schema context and maps insurance terminology.
- `Discovery/` reads and represents the live schema.
- `Knowledge/` builds, fingerprints, loads, and stores schema snapshots.
- `Profiling/` analyzes schema and relationship candidates.
- `Query/` validates, builds, compiles, and executes read-only query plans.

## Database knowledge library

Build the database knowledge library with:

```sh
docker compose exec app php scripts/build-knowledge-base.php
```

The builder writes versioned, immutable builds under `knowledge/builds/` and
switches `knowledge/current.json` only after all files are written. The active
build has a manifest and separate `catalog/`, `relationships/`, and `profiling/`
artifacts; the manifest records checksums so incomplete or mixed builds are
rejected. Existing `knowledge/snapshot.json` files are not imported or used.
The catalog includes full ordered indexes and unique constraints as well as
foreign-key column pairs. Each build profiles every declared foreign-key
constraint using non-null source rows capped per constraint; inferred
candidates remain ranked and capped. The manifest reports coverage and makes
clear which results are sampled, unprofiled, or structurally complete.

Set `KNOWLEDGE_LIBRARY_PATH` to build or read a library at another path. This
can be useful for isolated builds without replacing the active project library.
Use `KNOWLEDGE_PROFILE_SAMPLE_LIMIT` and
`KNOWLEDGE_MAX_INFERRED_TO_PROFILE` to configure per-constraint sample size and
the inferred-candidate profiling cap.

## Development

The application and test suite run in Docker. Run the unit suite with:

```sh
docker compose exec app ./vendor/bin/phpunit
```

The CLI smoke test accepts a natural-language database question:

```sh
docker compose exec app php scripts/smoke-test.php "Show the base premium for a policy period"
```
