<?php
if (!defined('ABSPATH')) { exit; }

/** Read-only import. Exported instructions, patch columns and URLs are never executable repairs. */
class GSCSF_Ahrefs {
    const MAX_FILE = 8388608;
    const MAX_TOTAL = 67108864;
    const MAX_URLS = 5000;

    public static function read($path, $name) {
        if (filesize($path) > 33554432) { throw new RuntimeException('Upload must be at most 32 MiB.'); }
        $summary = array('files' => 0, 'duplicates' => 0, 'rows' => 0, 'foreign_rows' => 0, 'utility_urls' => 0, 'urls' => array(), 'reports' => array(), 'warnings' => array());
        $seen = array(); $total = 0;
        $consume = function ($bytes, $filename) use (&$summary, &$seen, &$total) {
            $total += strlen($bytes);
            if ($total > self::MAX_TOTAL || strlen($bytes) > self::MAX_FILE) { throw new RuntimeException('Expanded CSV size limit exceeded (8 MiB per file, 64 MiB total).'); }
            $hash = hash('sha256', $bytes);
            if (isset($seen[$hash])) { $summary['duplicates']++; return; }
            $seen[$hash] = true;
            self::csv($bytes, basename(str_replace('\\', '/', $filename)), $summary);
        };
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($ext === 'zip') {
            if (!class_exists('ZipArchive')) { throw new RuntimeException('PHP ZipArchive is required for ZIP imports. Upload an individual CSV instead.'); }
            $zip = new ZipArchive();
            if ($zip->open($path) !== true) { throw new RuntimeException('Could not read ZIP archive.'); }
            try {
                if ($zip->numFiles > 200) { throw new RuntimeException('ZIP must contain at most 200 entries.'); }
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $entry = $zip->statIndex($i);
                    if (strtolower(pathinfo($entry['name'], PATHINFO_EXTENSION)) !== 'csv') { continue; }
                    if ($entry['size'] > self::MAX_FILE || $total + $entry['size'] > self::MAX_TOTAL) { throw new RuntimeException('Expanded CSV size limit exceeded.'); }
                    // Read in memory; never extract paths or retain the uploaded archive.
                    $bytes = $zip->getFromIndex($i, self::MAX_FILE + 1);
                    if ($bytes === false) { throw new RuntimeException('Unreadable or encrypted CSV entry.'); }
                    $consume($bytes, $entry['name']);
                }
            } finally { $zip->close(); }
        } elseif (in_array($ext, array('csv', 'tsv'), true)) { $consume(file_get_contents($path), $name); }
        else { throw new RuntimeException('Upload an Ahrefs ZIP, CSV or TSV export.'); }
        if (!$summary['files']) { throw new RuntimeException('No recognizable page-level or link-level Ahrefs CSV was found.'); }
        $summary['urls'] = array_keys($summary['urls']);
        $summary['warnings'] = array_values(array_unique($summary['warnings']));
        $summary['imported_at'] = current_time('mysql');
        return $summary;
    }

    private static function csv($bytes, $filename, &$summary) {
        if (substr($bytes, 0, 2) === "\xFF\xFE" || substr($bytes, 0, 2) === "\xFE\xFF") {
            if (!function_exists('iconv')) { throw new RuntimeException('PHP iconv is required for UTF-16 exports.'); }
            $bytes = iconv(substr($bytes, 0, 2) === "\xFF\xFE" ? 'UTF-16LE' : 'UTF-16BE', 'UTF-8', substr($bytes, 2));
            if ($bytes === false) { throw new RuntimeException('Invalid UTF-16 export.'); }
        }
        $bytes = preg_replace('/^\xEF\xBB\xBF/', '', $bytes);
        $first = strtok($bytes, "\r\n");
        $delimiter = strpos((string) $first, "\t") !== false ? "\t" : ',';
        $stream = fopen('php://temp', 'w+'); fwrite($stream, $bytes); rewind($stream);
        try {
            $headers = fgetcsv($stream, 0, $delimiter, '"', '');
            if (!$headers || (!in_array('URL', $headers, true) && !in_array('Source URL', $headers, true))) { $summary['warnings'][] = 'Skipped unrecognized CSV: ' . $filename; return; }
            $report = array('file' => $filename, 'rows' => 0, 'matching_rows' => 0, 'kind' => in_array('URL', $headers, true) ? 'pages' : 'links');
            $primary = $report['kind'] === 'pages' ? 'URL' : 'Source URL';
            while (($values = fgetcsv($stream, 0, $delimiter, '"', '')) !== false) {
                if ($values === array(null)) { continue; }
                if (++$summary['rows'] > 100000) { throw new RuntimeException('Import exceeds 100,000 unique-file rows. Split the exports.'); }
                if (count($values) !== count($headers)) { throw new RuntimeException('Malformed CSV row in ' . $filename); }
                $row = array_combine($headers, $values); $report['rows']++;
                $source = trim($row[$primary]);
                if (!GSCSF_Audit::local_url($source)) { $summary['foreign_rows']++; continue; }
                $report['matching_rows']++;
                // Only explicit URL columns become scan seeds. Patch/content columns are ignored.
                foreach (array($primary, 'Target URL', 'Redirect URL', 'Target final redirect URL') as $column) {
                    $url = trim($row[$column] ?? '');
                    if (!$url || !GSCSF_Audit::local_url($url)) { continue; }
                    if (GSCSF_Extended::utility($url)) { $summary['utility_urls']++; continue; }
                    $url = esc_url_raw(preg_replace('/#.*$/', '', $url));
                    if (strlen($url) > 2048) { continue; }
                    if (count($summary['urls']) >= self::MAX_URLS && !isset($summary['urls'][$url])) { $summary['warnings'][] = 'Only the first 5,000 unique matching URLs were imported. Split larger audits.'; continue; }
                    $summary['urls'][$url] = true;
                }
            }
            $summary['files']++; $summary['reports'][] = $report;
        } finally { fclose($stream); }
    }
}
