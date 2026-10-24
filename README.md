# Legacy PHP Analyzer

[![CI](https://github.com/mestepa12/legacy-php-analyzer/actions/workflows/ci.yml/badge.svg)](https://github.com/mestepa12/legacy-php-analyzer/actions/workflows/ci.yml)
![PHP](https://img.shields.io/badge/PHP-8.2%2B-777BB4?logo=php&logoColor=white)
![nikic/php-parser](https://img.shields.io/badge/nikic%2Fphp--parser-5.x-4F5B93)
![PHPUnit](https://img.shields.io/badge/PHPUnit-11-3C9CD7)
![Tests](https://img.shields.io/badge/tests-74%20passing-brightgreen)
![vis-network](https://img.shields.io/badge/vis--network-9.1.9-2B7CE9)
![Composer](https://img.shields.io/badge/Composer-ready-885630?logo=composer&logoColor=white)
![License: MIT](https://img.shields.io/badge/license-MIT-green)

A CLI tool for the first day on an **undocumented legacy PHP codebase**. Before you change anything you need to know three things: what exists, what depends on what, and what can safely be deleted. This tool answers all three with static analysis and never runs the code it analyzes.

One run gives you:

1. **A dependency map** between classes: inheritance, interfaces, traits, `new X()`, static calls, type hints, `catch` types, `instanceof` checks and `X::class` references.
2. **Dead code detection**: classes nobody references, private/protected methods nobody calls and functions nobody calls. Each finding has a confidence level (`high` / `medium` / `low`) and a reason.
3. **Generated documentation** built from the existing PHPDoc and the real signatures. It also flags **docblocks that no longer match the code**.

Results come out as **JSON** that other tools can read, plus a **self-contained interactive HTML report** with a navigable dependency graph. The report is one file that opens offline with no server and no build step.

> Plain PHP 8.2+ with no framework. Parsing is done on the AST with [nikic/php-parser](https://github.com/nikic/PHP-Parser); nothing is parsed with regex.

---

## Demo

This is a command-line tool, so there is no hosted demo. The screenshots below come from a **real run** against [`sebastianbergmann/phpunit-mock-objects`](https://github.com/sebastianbergmann/phpunit-mock-objects), an archived legacy library (45 files, 67 graph nodes, 211 edges).

To make your own report:

```bash
php bin/analyze /path/to/any-php-project --html=report.html
# then open report.html in a browser
```

## Screenshots

**Dependency graph.** You can filter edges by type, search a node and isolate it with its neighbours. Dead code is outlined by confidence level. Files that fail to parse are reported, not silently skipped.

![Dependency graph](docs/screenshots/graph.png)

**Dead code.** Every finding comes with a confidence level and the reason for it.

![Dead code findings](docs/screenshots/dead-code.png)

**Documentation discrepancies.** Places where the docblock and the real signature disagree.

![Documentation discrepancies](docs/screenshots/discrepancies.png)

---

## Tech stack

| Area | Choice | Why |
|------|--------|-----|
| Language | **PHP 8.2+** | readonly classes and properties, enums, first-class callables |
| Parsing | **nikic/php-parser 5** + `NameResolver` | an exact AST even for messy legacy code; tolerates syntax errors file by file |
| Graph UI | **vis-network 9.1.9** (vendored) | inlined into the report so it works fully offline |
| Tests | **PHPUnit 11** | 74 tests / 220 assertions against purpose-built fixture projects |
| Packaging | **Composer** (PSR-4, `bin` entry) | works as a standalone clone or as a dependency |

## Install and run locally

**Requirements:** PHP ≥ 8.2 and Composer.

```bash
git clone https://github.com/mestepa12/legacy-php-analyzer
cd legacy-php-analyzer
composer install
```

```bash
# JSON to stdout. The summary goes to stderr, so pipes stay clean
php bin/analyze /path/to/legacy-project | jq .deadCode

# Everything to files: pretty JSON + HTML report + list of files that failed to parse
php bin/analyze /path/to/legacy-project \
    --output=analysis.json --html=report.html --pretty --verbose

# Skip extra directories (these are ADDED to the defaults: vendor, node_modules, .git, .svn, cache)
php bin/analyze /path/to/legacy-project --exclude=cache,storage

# CI gate: fail the pipeline when dead code appears
php bin/analyze src/ --fail-on-dead-code

# Run the test suite
composer test
```

### Docker

```bash
docker build -t legacy-php-analyzer .
docker run --rm -v "$PWD/path/to/legacy-project:/project:ro" legacy-php-analyzer /project --pretty
```

The image contains only PHP 8.3, the production dependencies and the tool, and runs as a non-root user.

### Exit codes

| Code | Meaning |
|------|---------|
| `0` | Analysis finished. Findings don't make it fail; finding things is the tool's job |
| `1` | Invalid usage (unknown flag, missing path) |
| `2` | Path not found, no PHP files, or the output can't be written |
| `3` | Dead code found. **Only** with `--fail-on-dead-code` |

### JSON output

Consumers should check `meta.schemaVersion` instead of guessing the format. It goes up on every breaking change.

```jsonc
{
  "meta":     { "schemaVersion": 1, "tool": {...}, "analyzedPath": "...",
                "generatedAt": "...", "filesScanned": 312,
                "filesWithParseErrors": 2, "excludedDirs": [...] },
  "errors":   [ { "file": "...", "message": "Syntax error ..." } ],
  "graph":    { "nodes": [...], "edges": [...] },
  "deadCode": { "classes": [...], "methods": [...], "functions": [...],
                "dynamicReferenceCounts": {...} },
  "docs":     { "coverage": {...}, "classes": [...], "functions": [...],
                "discrepancies": [...] }
}
```

---

## Technical highlights

### Architecture: one parse, immutable phases

```
project dir ──► ProjectParser (php-parser + NameResolver, error-tolerant)
                   │  one AST traversal per file, two visitors:
                   ├─ SymbolCollector    → Codebase   (what EXISTS)
                   └─ DependencyVisitor  → references (what is USED)
                   ▼
                ParseResult
                   ├─► GraphBuilder      → DependencyGraph (typed edges)
                   ├─► DeadCodeDetector  → DeadCodeReport  (graph + refs)
                   ├─► DocGenerator      → DocReport       (PHPDoc + signatures)
                   ▼
                AnalysisResult ──► JsonReporter ──► analysis.json
                               └─► HtmlReporter ──► report.html (self-contained)
```

Each file's AST is walked once. After that, every phase reads only the previous phase's immutable output.

### Getting the details right

- **Visitor ordering in the AST.** `NameResolver` resolves names as the traverser *enters* each node. So `SymbolCollector` builds classes on `leaveNode`, when their children are already resolved, and `DependencyVisitor` works on `enterNode`, because it only needs the node's own names. The two patterns are used on purpose and the reasons are commented in the code.
- **Case-insensitive symbol tables.** PHP class and function names ignore case, so legacy `new USER()` must match `class User`. Every lookup is lowercased and the original names are kept for display.
- **No invented edges.** `Foo::m()` becomes an edge with a member. `Foo::$m()` becomes an edge to the class with the member unknown. `$var::m()` produces **no edge** and is recorded as a dynamic reference instead, because guessing would make the graph wrong.
- **Traits in dead code detection.** A trait method's usage scope is the transitive closure over trait-uses-trait chains and the classes consuming them, pruned per method by `insteadof`. An `as` alias counts as usage. For protected methods the scope is the whole hierarchy (ancestors call template-method hooks through `$this`).
- **Function resolution matches PHP.** Inside `namespace App`, an unqualified `foo()` has two candidates, `[App\foo, \foo]`, and only the **first one that is declared** counts as called. That is how a shadowed global function correctly shows up as dead.
- **Doc types are normalized before comparing.** `?string` ≡ `string|null`, `integer` ≡ `int`, `Dog[]` counts as a refinement of `array` rather than a mismatch, and `$this` ≡ `static` ≡ `self`. `{@inheritdoc}` is resolved through `extends → implements → traits`, and the inherited doc is checked against the **override's real signature**.
- **stdout is for machines only.** JSON goes to stdout and every human-readable message goes to stderr, so `analyze proj | jq .` just works. The CLI takes its streams by injection, which lets the tests run it end to end through `php://memory`.
- **The HTML report doesn't depend on anything.** vis-network is vendored (provenance in `resources/vendor/`) and inlined at generation time by plain string concatenation. The embedded JSON uses `JSON_HEX_TAG`, so analyzed code that contains `</script>` can't break the report.
- **Broken files are reported, not fatal.** A file with a syntax error is recorded and skipped, and the rest of the project is still analyzed.

### Testing

`composer test` runs **74 tests / 220 assertions**. They exercise the real pipeline against purpose-built fixture projects in `tests/fixtures/`:

- a small legacy app with one broken file and a `vendor/` decoy
- a dead code stress test: trait chains, `insteadof`, template-method hooks, magic constructors
- a documentation drift project with every kind of discrepancy, plus the false-positive cases that must stay silent

The CLI is tested end to end, covering exit codes, which stream each message goes to, and the JSON schema.

## CI pipeline

[`.github/workflows/ci.yml`](.github/workflows/ci.yml) runs on every `push` and every `pull_request`. Three jobs run in parallel; `docker` waits for the tests to pass.

| Job | What it does | Why |
|-----|--------------|-----|
| `tests` | Runs PHPUnit on PHP 8.2 and 8.3 using the committed `composer.lock`. | 8.2 is the minimum declared in `composer.json` and 8.3 is what the Docker image ships, so both ends of the supported range are covered. The lock file makes CI install exactly what developers install. |
| `secrets-scan` | Runs [gitleaks](https://github.com/gitleaks/gitleaks) over the **whole git history** (`fetch-depth: 0`). | A secret deleted in a later commit is still leaked in the history, so scanning only the latest tree is not enough. Findings are redacted (`--redact`) so the secret is not printed again in the logs. |
| `dependency-scan` | Scans `composer.lock` with `trivy fs`, including dev dependencies. | Catches known CVEs in Composer packages without building anything. Dev dependencies are included because they run in CI and on developer machines. |
| `docker` | Builds the image with Buildx (GitHub Actions cache), runs the analyzer inside it against a test fixture and checks the JSON, then scans the image with `trivy image`. | Building on every change keeps the `Dockerfile` from rotting. The smoke test proves the image actually works, not just that it builds. The image scan covers what the dependency scan can't see: Alpine packages and the PHP runtime itself. |

### Failure criteria

- Trivy fails the job on `HIGH` or `CRITICAL` vulnerabilities that **already have a fix** (`--ignore-unfixed`). Blocking on CVEs with no patch only produces noise nobody can act on. The threshold is set in `TRIVY_SEVERITY`.
- gitleaks fails the job on any finding.
- gitleaks and Trivy results are uploaded as SARIF to **Security → Code scanning**. That step has `continue-on-error` because code scanning is unavailable on pull requests from forks and on private repos without GitHub Advanced Security; the job still fails on the scan itself.

### Securing the workflow itself

- **Least privilege:** the workflow starts with `permissions: {}` (no permissions for `GITHUB_TOKEN`) and each job asks only for what it needs: `contents: read` to check out and, in the scan jobs, `security-events: write` to upload SARIF. A compromised step can't use the token to write to the repo.
- **`persist-credentials: false`** on every checkout, so the token isn't left in `.git/config` for later steps.
- **Credentials only through GitHub Secrets:** the pipeline needs none. Optionally, if the `DOCKERHUB_USERNAME` and `DOCKERHUB_TOKEN` secrets exist (*Settings → Secrets and variables → Actions*), they are used to log in to Docker Hub and avoid the anonymous pull limit. Otherwise the step is skipped.
- **Everything pinned:** actions are pinned to a full commit SHA (the tag is kept as a comment), and the Trivy, gitleaks, PHP and Composer images are pinned by digest. A moved tag can't swap the code that runs. [Dependabot](.github/dependabot.yml) opens weekly PRs to bump actions, Docker images and Composer packages, so pinning doesn't mean going stale. It has a **7-day cooldown**: it only proposes versions published at least a week ago, so a compromised release has time to be caught and pulled before it reaches this repo. The same rule was applied to the initial pins.
- **`concurrency`** cancels older runs on the same branch, and every job has `timeout-minutes` so nothing hangs burning minutes.

## Known limitations (by design)

- **Flat analysis, no reachability.** A call inside a dead method still keeps its target alive. Real reachability would need known entry points, which libraries don't have.
- **Dynamic PHP is a blind spot, and the tool says so.** `new $class`, `$obj->$method()` and `call_user_func` can't be resolved statically. The tool counts them (`dynamicReferenceCounts`) and lowers the confidence of affected findings.
- **Files are found by walking the directory tree**, not through Composer/PSR-4 maps. That suits legacy projects, but when two conditionally loaded files declare the same class, the last one parsed wins.
- **Docblock class types are compared by short name**, because `use` imports inside docblocks aren't resolved.
- **Public symbols are never reported as dead**, because code outside the analyzed tree may call them.
- **Anonymous classes** get no graph node of their own. Their dependencies are attributed to the enclosing scope.

## License

MIT, see [LICENSE](LICENSE). The vendored vis-network bundle is dual-licensed MIT/Apache-2.0; provenance is in [resources/vendor/README.md](resources/vendor/README.md).
