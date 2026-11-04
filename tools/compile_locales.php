<?php

/**
 * Compile the plugin gettext catalogs in locales/ (*.po) into binary .mo files.
 *
 * GLPI loads plugin translations from locales/<lang>.mo (see Plugin.php), so a
 * gettext toolchain (msgfmt) is normally required. This script is a pure-PHP
 * fallback that produces GNU .mo binaries compatible with Laminas' gettext
 * loader used by GLPI.
 *
 * Usage: php tools/compile_locales.php
 * It scans <plugin>/locales/*.po and writes the sibling <lang>.mo file.
 */

// CLI-only defence in depth: this script rewrites locales/*.mo in place, so it
// must never be reachable over HTTP (tools/ is export-ignored from the release
// archive, but not from a `git clone` deployment).
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("CLI only.\n");
}

$locales_dir = __DIR__ . '/../locales';

function mo_encode(array $pairs): string
{
   $n = count($pairs);
   $header_size = 7 * 4;
   $o_table = $header_size;
   $t_table = $o_table + $n * 8;
   $str_start = $t_table + $n * 8;

   $msgid_block = '';
   $msgstr_block = '';
   foreach ($pairs as $pair) {
      $msgid_block .= $pair[0] . "\0";
   }
   foreach ($pairs as $pair) {
      $msgstr_block .= $pair[1] . "\0";
   }

   $offset = $str_start;
   $orig_entries = [];
   foreach ($pairs as $pair) {
      $orig_entries[] = [strlen($pair[0]), $offset];
      $offset += strlen($pair[0]) + 1;
   }
   $trans_entries = [];
   foreach ($pairs as $pair) {
      $trans_entries[] = [strlen($pair[1]), $offset];
      $offset += strlen($pair[1]) + 1;
   }

   $bin = pack('V', 0x950412de); // magic (little-endian)
   $bin .= pack('V', 0);         // version
   $bin .= pack('V', $n);        // number of strings
   $bin .= pack('V', $o_table);  // offset of origin table
   $bin .= pack('V', $t_table);  // offset of translation table
   $bin .= pack('V', 0);         // hash table size
   $bin .= pack('V', 0);         // hash table offset
   foreach ($orig_entries as $e) {
      $bin .= pack('V2', $e[0], $e[1]);
   }
   foreach ($trans_entries as $e) {
      $bin .= pack('V2', $e[0], $e[1]);
   }
   $bin .= $msgid_block;
   $bin .= $msgstr_block;

   return $bin;
}

function po_parse(string $content): array
{
   $entries = [];
   $cur = ['id' => '', 'plural' => null, 'forms' => []];
   $started = false;
   $open = null;

   $finish = static function () use (&$cur, &$entries, &$started, &$open): void {
      if (!$started) {
         return;
      }
      $entries[] = $cur;
      $cur = ['id' => '', 'plural' => null, 'forms' => []];
      $started = false;
      $open = null;
   };

   $lines = preg_split('/\r\n|\r|\n/', $content);
   foreach ($lines as $line) {
      $line = rtrim($line);
      if (preg_match('/^msgid\s+(.*)$/', $line, $m)) {
         $finish();
         $started = true;
         $cur['id'] = po_unquote($m[1]);
         $open = &$cur['id'];
      } elseif (preg_match('/^msgid_plural\s+(.*)$/', $line, $m)) {
         $cur['plural'] = po_unquote($m[1]);
         $open = &$cur['plural'];
      } elseif (preg_match('/^msgstr(?:\[(\d+)\])?\s+(.*)$/', $line, $m)) {
         $index = isset($m[1]) && $m[1] !== '' ? (int)$m[1] : 0;
         $cur['forms'][$index] = po_unquote($m[2]);
         $open = &$cur['forms'][$index];
      } elseif ($started && $line !== '' && $line[0] === '"' && $open !== null) {
         $open .= po_unquote($line);
      } elseif ($line === '') {
         $finish();
      }
   }
   $finish();

   // Keep the "" header entry (first) for the plural-forms metadata. Plural
   // entries are stored as "singular\0plural" => "form0\0form1", exactly the
   // layout msgfmt writes and check-locales compares.
   $pairs = [];
   $header = null;
   foreach ($entries as $e) {
      $forms = $e['forms'];
      ksort($forms);
      if ($e['id'] === '' && $e['plural'] === null) {
         $header = implode('', $forms);
         continue;
      }
      if (count(array_filter($forms, static fn (string $v): bool => $v !== '')) === 0) {
         continue; // untranslated entry
      }
      $value = implode("\0", $forms);
      $pairs[] = $e['plural'] !== null
         ? [$e['id'] . "\0" . $e['plural'], $value]
         : [$e['id'], $forms[0] ?? ''];
   }
   if ($header !== null) {
      array_unshift($pairs, ['', $header]);
   }
   return $pairs;
}

function po_unquote(string $token): string
{
   $token = trim($token);
   if (strlen($token) >= 2 && $token[0] === '"' && substr($token, -1) === '"') {
      $token = substr($token, 1, -1);
   }
   $token = str_replace(['\\"', '\\\\'], ['"', '\\'], $token);
   $token = preg_replace('/\\\n/', "\n", $token);
   $token = preg_replace('/\\\t/', "\t", $token);
   $token = preg_replace('/\\\r/', "\r", $token);
   return $token;
}

$files = glob($locales_dir . '/*.po');
if (empty($files)) {
   fwrite(STDERR, "No .po files found in $locales_dir\n");
   exit(1);
}

foreach ($files as $po_file) {
   $mo_file = preg_replace('/\.po$/', '.mo', $po_file);
   $content = file_get_contents($po_file);
   $pairs = po_parse($content);
   if (empty($pairs)) {
      fwrite(STDERR, "No translatable entries in $po_file\n");
      continue;
   }
   $bin = mo_encode($pairs);
   file_put_contents($mo_file, $bin);
   echo basename($po_file) . ' -> ' . basename($mo_file) . ' (' . strlen($bin) . ' bytes, ' . count($pairs) . " entries)\n";
}

echo "Done.\n";