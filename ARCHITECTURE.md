# Architecture: class-map-generator

## Purpose

Scans PHP source files and produces a class map — an associative array mapping fully-qualified class/interface/trait/enum names to the absolute file paths that declare them. Used by Composer's autoloader optimisation and any tool that needs to know where a class is defined.

## Directory Structure

```
src/
  Class_Map_Generator.php   — Public entry point: accepts a File_List and produces a Class_Map
  Class_Map.php             — Value object wrapping the class → file path array
  File_List.php             — Iterable list of PHP files to scan (supports glob patterns and directories)
  Php_File_Cleaner.php      — Strips comments and strings before tokenisation to avoid false positives
  Php_File_Parser.php       — Token-based parser extracting namespaces and class/interface/trait/enum names

tests/
  Class_Map_Generator_Test.php   — Integration tests using fixture PHP files
  Php_File_Parser_Test.php       — Unit tests for the parser
  Fixtures/                      — PHP files covering edge cases (enums, traits, heredocs, unicode, etc.)
```

## Key Design Decisions

- **Token-based parsing** — uses PHP's `token_get_all()` rather than regex to handle all valid PHP syntax, including multi-namespace files, heredocs, and short open tags.
- **Pre-cleaning** — `Php_File_Cleaner` removes strings and comments before tokenisation to avoid class names appearing in docblocks or string literals being picked up.
- **No eval/reflection** — purely static analysis; files are never executed, making it safe to scan untrusted code.
- **PSR violation detection** — can optionally flag files where the declared namespace doesn't match the expected directory structure.
- **PCRE backtrack-limit awareness** — handles very long heredocs/nowdocs that would exceed PHP's default PCRE backtrack limit.

## Extension Points

- Construct a custom `File_List` to scan specific files or glob patterns.
- Use `Class_Map` directly to merge multiple scan results.

## Dependency Flow

```
Class_Map_Generator::build(File_List)
  └── for each PHP file:
        ├── Php_File_Cleaner::clean(source)   → stripped source
        └── Php_File_Parser::parse(stripped)  → [FQCN => file]
              → merged into Class_Map
```
