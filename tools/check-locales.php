#!/usr/bin/env php
<?php

/**
 * Check that every translatable string used by the plugin runtime exists in
 * the .po catalogues.
 *
 *   tools/check-locales.php           # exit 1 when a string is missing
 *   tools/check-locales.php --list    # print the missing msgids, always exit 0
 *
 * The runtime is scanned (front/, inc/, public/, setup.php, hook.php) for
 * __("...", 'kanban') / __('...', 'kanban') calls. Development-only trees
 * (tests/, tools/, docker/, the seeders) are excluded: they are never shipped
 * and GLPI never loads their translations.
 *
 * Only the PRESENCE of a msgid is checked, never its translation: an empty
 * msgstr is a valid untranslated state that falls back to the source string.
 */

$root = dirname(__DIR__);
chdir($root);

$list_only = in_array('--list', $argv, true);

$scan = ['front', 'inc', 'public', 'setup.php', 'hook.php'];
$php_files = [];
foreach ($scan as $entry) {
   if (is_dir($entry)) {
      $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($entry));
      foreach ($it as $file) {
         if ($file->isFile() && $file->getExtension() === 'php') {
            $php_files[] = $file->getPathname();
         }
      }
   } elseif (is_file($entry)) {
      $php_files[] = $entry;
   }
}

/** @return array<string, string> msgid => first file that uses it */
function kanban_used_strings(array $files): array {
   // __("...", 'kanban') and the two-argument $domain form, plus both msgids of
   // _n("one", "many", $nb, 'kanban').
   $patterns = [
      '/__\(\s*([\'"])((?:\\\\.|(?!\1).)*)\1\s*,\s*[\'"]kanban[\'"]/s',
      '/(?<![\w])_n\(\s*([\'"])((?:\\\\.|(?!\1).)*)\1\s*,\s*([\'"])((?:\\\\.|(?!\3).)*)\3\s*,/s',
   ];

   $found = [];
   foreach ($files as $file) {
      $code = file_get_contents($file);
      foreach ($patterns as $pattern) {
         if (!preg_match_all($pattern, $code, $matches, PREG_SET_ORDER)) {
            continue;
         }
         foreach ($matches as $match) {
            // __() contributes [1] (string), [2] (quote);
            // _n() contributes [2] (string), [3] (quote), [4] (plural string).
            foreach ([2, 4] as $group) {
               if (!isset($match[$group]) || $match[$group] === '') {
                  continue;
               }
               $string = stripcslashes($match[$group]);
               if ($string !== '' && !isset($found[$string])) {
                  $found[$string] = $file;
               }
            }
         }
      }
   }
   ksort($found);
   return $found;
}

/**
 * Read the entries of a .po catalogue as msgid => msgstr.
 *
 * A plural entry is keyed by "singular\0plural" and carries its forms joined by
 * the same separator, which is exactly how the .mo stores it, so the two
 * catalogues can be compared field by field.
 *
 * @return array<string, string>
 */
function kanban_catalogue_entries(string $file): array {
   $entries = [];

   // Normalize the line endings first: git checks .po out with CRLF on Windows
   // (core.autocrlf), and the anchored patterns below would then match nothing,
   // turning every string into a false "missing msgid".
   $content = str_replace(["\r\n", "\r"], "\n", (string)file_get_contents($file));

   // gettext wraps long entries over several quoted lines, so a msgid can span
   // more than one physical line. Working entry by entry and concatenating the
   // continuations keeps the check working whichever style the catalogue uses.
   foreach (preg_split('/\n\s*\n/', $content) as $block) {
      // Every msgid / msgid_plural / msgstr line opens a new chunk, and a bare
      // quoted line continues whichever chunk is open.
      $id     = null;
      $plural = null;
      $forms  = [];
      $open   = null;

      foreach (explode("\n", $block) as $line) {
         if (preg_match('/^msgid\s+"(.*)"$/', $line, $m)) {
            $id     = [$m[1]];
            $plural = null;
            $forms  = [];
            $open   = &$id;
            continue;
         }
         if (preg_match('/^msgid_plural\s+"(.*)"$/', $line, $m)) {
            $plural = [$m[1]];
            $open   = &$plural;
            continue;
         }
         if (preg_match('/^msgstr(?:_plural|\[\d+\])?\s+"(.*)"$/', $line, $m)) {
            $forms[] = [$m[1]];
            $open    = &$forms[count($forms) - 1];
            continue;
         }
         if ($id !== null && preg_match('/^"(.*)"$/', $line, $m)) {
            $open[] = $m[1];
         }
      }

      if ($id === null) {
         continue;
      }
      // Join the raw chunks before unescaping, so a sequence that straddles two
      // physical lines is still decoded as one.
      $key = stripcslashes(implode('', $id));
      if ($plural !== null) {
         $key .= "\0" . stripcslashes(implode('', $plural));
      }
      if ($key === '') {
         // The empty msgid is the catalogue metadata, not a translatable entry.
         continue;
      }
      $value = implode("\0", array_map(
         static fn (array $form): string => stripcslashes(implode('', $form)),
         $forms
      ));
      $entries[$key] = $value;
   }

   return $entries;
}

/**
 * Every msgid the runtime can look up: the singular and, for a plural entry,
 * the plural form too, since both appear as arguments in the source.
 *
 * @return array<string, true>
 */
function kanban_catalogue_ids(string $file): array {
   $ids = [];
   foreach (array_keys(kanban_catalogue_entries($file)) as $key) {
      foreach (explode("\0", $key) as $form) {
         $ids[$form] = true;
      }
   }
   return $ids;
}

/**
 * Read the entries of a compiled .mo catalogue as msgid => msgstr.
 *
 * Parsing the binary here instead of shelling out to msgfmt keeps the check
 * runnable on a developer machine (no gettext) and lets us compare the two
 * catalogues field by field, which is what catches a .mo left behind by an
 * edited .po.
 *
 * @return array<string, string>
 */
function kanban_mo_entries(string $file): array {
   $data = @file_get_contents($file);
   if ($data === false || strlen($data) < 28) {
      return [];
   }

   // Magic 0x950412de identifies the byte order the file was written with.
   $magic = unpack('V', substr($data, 0, 4))[1] ?? 0;
   if ($magic === 0x950412de) {
      $format = 'V';
   } elseif ($magic === 0xde120495) {
      $format = 'N';
   } else {
      return [];
   }

   $header = unpack($format . 'revision/' . $format . 'count/' . $format . 'originals/'
      . $format . 'translations/' . $format . 'hash_size/' . $format . 'hash_offset',
      substr($data, 4, 24));
   if ($header === false) {
      return [];
   }

   $entries = [];
   for ($i = 0; $i < (int)$header['count']; $i++) {
      $read = static function (int $table) use ($data, $format, $i): string {
         $entry = unpack($format . 'length/' . $format . 'offset',
            substr($data, $table + $i * 8, 8));
         if ($entry === false) {
            return '';
         }
         return (string)substr($data, (int)$entry['offset'], (int)$entry['length']);
      };

      // Both sides of a plural entry are NUL separated, in both formats, so the
      // keys line up without any reshaping. The empty msgid is the metadata.
      $id = $read((int)$header['originals']);
      if ($id !== '') {
         $entries[$id] = $read((int)$header['translations']);
      }
   }

   return $entries;
}

$used = kanban_used_strings($php_files);
$catalogues = glob('locales/*.po') ?: [];

if ($catalogues === []) {
   fwrite(STDERR, "no .po catalogue found in locales/\n");
   exit(1);
}

echo count($used) . " translatable string(s) in the runtime, "
   . count($catalogues) . " catalogue(s)\n";

$failed = false;
foreach ($catalogues as $catalogue) {
   $ids = kanban_catalogue_ids($catalogue);
   $missing = array_diff_key($used, $ids);
   if ($missing === []) {
      echo "OK  $catalogue (" . count($ids) . " msgids)\n";
   } else {
      $failed = true;
      echo "KO  $catalogue: " . count($missing) . " missing msgid(s)\n";
      if ($list_only) {
         foreach ($missing as $string => $file) {
            echo "     \"$string\"\n";
            echo "       first used in $file\n";
         }
      }
   }

   // The compiled catalogue is what GLPI actually loads, so a .po edited
   // without a msgfmt run would ship stale or untranslated strings.
   $mo = substr($catalogue, 0, -3) . '.mo';
   if (!is_file($mo)) {
      $failed = true;
      echo "KO  $mo: missing, run msgfmt to compile it\n";
      continue;
   }
   $po_entries = kanban_catalogue_entries($catalogue);
   $mo_entries = kanban_mo_entries($mo);
   if ($mo_entries === []) {
      $failed = true;
      echo "KO  $mo: unreadable as a gettext catalogue\n";
      continue;
   }

   $not_compiled = array_diff_key($po_entries, $mo_entries);
   $obsolete     = array_diff_key($mo_entries, $po_entries);
   // Same msgid, different text: the .po was edited and never recompiled.
   $outdated = array_intersect_key($po_entries, $mo_entries);
   $outdated = array_filter($outdated,
      static fn (string $value, string $id): bool => $value !== $mo_entries[$id],
      ARRAY_FILTER_USE_BOTH);

   if ($not_compiled === [] && $obsolete === [] && $outdated === []) {
      echo "OK  $mo (" . count($mo_entries) . " entries, in sync)\n";
      continue;
   }
   $failed = true;
   echo "KO  $mo: out of sync with $catalogue (" . count($not_compiled) . " not compiled, "
      . count($outdated) . " outdated, " . count($obsolete) . " obsolete)\n";
   if ($list_only) {
      foreach (array_keys($not_compiled + $outdated) as $string) {
         echo "     \"$string\"\n";
         echo "       run: msgfmt -o $mo $catalogue\n";
      }
   }
}

if ($failed && !$list_only) {
   echo "\nRun 'tools/check-locales.php --list' to print what is missing.\n";
}

exit($failed && !$list_only ? 1 : 0);
