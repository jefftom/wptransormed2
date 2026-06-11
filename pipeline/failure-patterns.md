# Pipeline Failure-Pattern Ledger — v1.1

Supersedes failure-patterns-v1. Commit to `pipeline/failure-patterns.md`. Two patterns added from slice 10b's RETRO (both escaped author AND external audit; caught by build-stage live verification and contradiction-stop respectively). Amendment authority unchanged: builders propose, decision-doc stage amends.

## Authoring patterns
1. Persistent nouns must be decided, not declared to exist.
2. No load-bearing modal verbs ("may/should/prefer" on invariant-bearing behavior).
3. Every response/output origin gets a decided shape, including framework-produced ones.
4. Every enum value gets a covering behavioral rule.
5. Verification assertions name concrete identifiers — AND every named identifier is verified to exist in the facts sheet at decision time. A concrete-but-wrong fixture (an alias that doesn't exist) fails the same way a vague one does. (10b: content-duplication "alias" loop step; caught by builder contradiction-stop.)
6. Example values are labeled illustrative.
7. URL patterns, parameter regexes, identifier formats are pinned contracts.
8. Naming a future surface without scoping it out invites building it.
9. Secrets need their own contract: redaction, sentinel-preserve, identity-based pass-through, type rules, collision rule.
10. Conventions must be verified against behavior before being cited as conventions.
25. Redaction/transform rules enumerate EVERY surface that echoes the value: every verb's response, error payloads, logs, exports. A principle stated under one verb gets implemented under one verb. (10b: POST settings response leaked ciphertext; missed by author and external audit, caught by live verification.)
26. Language/platform floors (PHP version, WP version) are facts-sheet entries; signatures and syntax in decision docs must respect them. (10b: native union type vs PHP 7.4 floor; resolved by builder via docblock.)

## Evidence patterns
11. No "or" between verification methods.
12. Passing tests ≠ working product; the agent's report of tests is worth less. Live evidence with pasted output is the floor.
13. Negative assertions require a named mechanism.
14. Probe-scoped claims stated as probe-scoped.
15. Optional/conditional items carry mandatory decision reports.
16. Loop proofs over route tests: stored state demonstrably steering live behavior is the definition of done.

## Provenance patterns
17. Docblocks are not ground truth; only implementation behavior is.
18. Facts recorded before behavior exists are fiction; sheets update in the commit that creates the behavior.
19. Every numeric claim in a report traces to pasted output.
20. Restating a contract forks it; reference the committed authority.

## Process patterns
21. The reviewer chair outperforms the author chair for every participant; no author self-certifies a new contract.
22. A reviewer whose findings go cosmetic two slices running rotates out; blocking findings earn the seat.
23. Artifact roles (contract vs prompt vs report) are encoded in filenames and checked before commit.
24. The pipeline moves files and evidence, never judgment. Veto points are human-triggered by design.
27. Review and evidence are different filters and both are load-bearing: 10b's external audit caught 18 pre-build holes including a blocking injection; live verification caught the one that survived it. Neither stage substitutes for the other; budget for both on every contract-bearing slice.
