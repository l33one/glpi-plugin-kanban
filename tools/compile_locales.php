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
   $cur = null;
   $pending = [];

   $lines = preg_split('/\r\n|\r|\n/', $content);
   foreach ($lines as $line) {
      $line = rtrim($line);
      if (preg_match('/^msgid\s+(.*)$/', $line, $m)) {
         if ($cur !== null) {
            $entries[] = $cur;
         }
         $cur = ['id' => '', 'str' => '', 'id_multi' => '', 'str_multi' => ''];
         $pending = ['id' => '', 'str' => '', 'mode' => 'id'];
         $pending['id'] = po_unquote($m[1]);
      } elseif (preg_match('/^msgstr\s+(.*)$/', $line, $m)) {
         $pending['mode'] = 'str';
         $pending['str'] = po_unquote($m[1]);
      } elseif ($cur !== null && $line !== '' && $line[0] === '"') {
         $val = po_unquote($line);
         if ($pending['mode'] === 'id') {
            $pending['id'] .= $val;
         } else {
            $pending['str'] .= $val;
         }
      } elseif ($cur !== null && $line === '') {
         // Blank line: finish current entry
         $cur['id'] = $pending['id'];
         $cur['str'] = $pending['str'];
         $entries[] = $cur;
         $cur = null;
      }
   }
   if ($cur !== null) {
      $cur['id'] = $pending['id'];
      $cur['str'] = $pending['str'];
      $entries[] = $cur;
   }

   // Keep the "" header entry (first) for the plural-forms metadata, drop
   // plural entries and untranslated ones.
   $pairs = [];
   $header = null;
   foreach ($entries as $e) {
      if ($e['id'] === '') {
         $header = $e['str'];
         continue;
      }
      if (isset($e['id_plural']) || str_contains($e['id'], "\0")) {
         continue;
      }
      if ($e['str'] === '') {
         continue;
      }
      $pairs[] = [$e['id'], $e['str']];
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