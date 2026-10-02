#!/usr/bin/env php
<?php

/**
 * Validate the plugin descriptor (kanban.xml).
 *
 *   tools/check-descriptor.php            # exit 1 on any problem
 *   tools/check-descriptor.php --version  # print the current version, exit 0
 *
 * The CI uses --version instead of parsing the XML inline, because a nested
 * `php -r` inside `$( )` inside YAML has no quoting that survives contact with
 * every shell.
 *
 * Three things are checked, because each one has broken a release on its own:
 *
 *  1. The XML is well-formed. xmllint is not installed on the GitHub runner, so
 *     this uses the DOM extension that setup-php already provides.
 *  2. The <num> of the current <version> matches PLUGIN_KANBAN_VERSION in
 *     setup.php. GLPI shows the descriptor version in the plugin list, so a
 *     mismatch here is a support ticket waiting to happen.
 *  3. The <download_url> of that same version points at an asset named after
 *     the version it advertises, so the marketplace entry cannot link to the
 *     wrong tarball.
 *
 * Plus one invariant: the <version> blocks must be in descending order.
 */

$root = dirname(__DIR__);
chdir($root);

$print_version = in_array('--version', $argv, true);

$failures = 0;

function fail(string $message): void
{
   global $failures;
   $failures++;
   fwrite(STDERR, "FALHA: $message\n");
}

/**
 * Progress goes to stderr, never stdout: stdout is reserved for the --version
 * value, which the CI captures with $(...) and would otherwise corrupt.
 */
function note(string $message): void
{
   fwrite(STDERR, $message . "\n");
}

$descriptor = 'kanban.xml';

if (!is_file($descriptor)) {
   fail("$descriptor nao existe");
   exit(1);
}

// 1. Well-formedness.
$previous = libxml_use_internal_errors(true);
$xml = new DOMDocument();
$loaded = $xml->load($descriptor);
$loadErrors = libxml_get_errors();
libxml_clear_errors();
libxml_use_internal_errors($previous);

if (!$loaded) {
   foreach ($loadErrors as $error) {
      fail("$descriptor: " . trim($error->message));
   }
   exit(1);
}
note("OK: $descriptor e XML bem formado");

$descriptorVersions = $xml->getElementsByTagName('version');
if ($descriptorVersions->length === 0) {
   fail("$descriptor nao declara nenhuma <version>");
   exit(1);
}

// The current version is the FIRST <version> block, newest first: the descriptor
// is ordered by descending version, and the marketplace reads it that way.
// GLPI's own core never parses <versions> -- the version it displays in the
// plugin list comes from plugin_version_kanban() in setup.php -- so this file is
// metadata for external tooling, and the ordering has to be explicit.
$declared = [];
foreach ($descriptorVersions as $block) {
   $declared[] = trim($block->getElementsByTagName('num')->item(0)?->textContent ?? '');
}
$latest = $descriptorVersions->item(0);

$sorted = $declared;
usort($sorted, static fn(string $a, string $b): int => version_compare($b, $a));
if ($declared !== $sorted) {
   fail(
      'as <version> do descritor precisam estar em ordem decrescente (a mais nova '
      . "primeira). Vem: " . implode(' > ', $declared)
   );
}

$readTag = static function (DOMElement $node, string $tag): string {
   $found = $node->getElementsByTagName($tag)->item(0);
   return $found instanceof DOMElement ? trim($found->textContent) : '';
};

$descriptorVersion = $readTag($latest, 'num');
if ($descriptorVersion === '') {
   fail("a <version> $descriptorVersion nao tem <num>");
   exit(1);
}

if ($print_version) {
   echo $descriptorVersion;
   exit(0);
}

// 2. The version in the code.
$setup = (string) file_get_contents('setup.php');
if (!preg_match("/PLUGIN_KANBAN_VERSION',\s*'([^']+)'/", $setup, $matches)) {
   fail('nao consegui ler PLUGIN_KANBAN_VERSION de setup.php');
} else {
   $codeVersion = $matches[1];
   if ($codeVersion !== $descriptorVersion) {
      fail("kanban.xml diz $descriptorVersion mas setup.php diz $codeVersion");
   } else {
      note("OK: versao $descriptorVersion consistente entre kanban.xml e setup.php");
   }
}

// 3. The asset the descriptor points at.
$downloadUrl = $readTag($latest, 'download_url');
if ($downloadUrl === '') {
   fail("a <version> $descriptorVersion nao tem <download_url>");
} else {
   $expectedAsset = "glpi-plugin-kanban-$descriptorVersion.tar.bz2";
   if (!str_contains($downloadUrl, $expectedAsset)) {
      fail("download_url nao referencia $expectedAsset (obtido: $downloadUrl)");
   } else {
      note("OK: download_url aponta para $expectedAsset");
   }

   $compatibilities = [];
   foreach ($latest->getElementsByTagName('compatibility') as $compatibility) {
      $compatibilities[] = trim($compatibility->textContent);
   }
   if ($compatibilities === []) {
      fail("a <version> $descriptorVersion nao declara <compatibility>");
   } else {
      note('OK: compatibility ' . implode(', ', $compatibilities));
   }
}

if ($failures > 0) {
   fwrite(STDERR, "$failures problema(s) em $descriptor\n");
   exit(1);
}

note("TUDO OK em $descriptor");
