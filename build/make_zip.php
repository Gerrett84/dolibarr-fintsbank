<?php
// Helper used by build-release.sh to zip a built module directory.
// Needed because some build hosts have the PHP zip extension but no `zip` CLI binary.

if ($argc !== 3) {
    fwrite(STDERR, "Usage: php make_zip.php <source-dir> <dest-zip>\n");
    exit(1);
}

[, $source, $dest] = $argv;
$source = rtrim($source, '/');

if (file_exists($dest)) {
    unlink($dest);
}

$zip = new ZipArchive();
if ($zip->open($dest, ZipArchive::CREATE) !== true) {
    fwrite(STDERR, "Cannot open $dest\n");
    exit(1);
}

$baseDir = dirname($source);
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);

foreach ($iterator as $file) {
    if (basename($file) === '.gitignore') {
        continue;
    }
    $localName = substr($file->getPathname(), strlen($baseDir) + 1);
    if ($file->isDir()) {
        $zip->addEmptyDir($localName);
    } else {
        $zip->addFile($file->getPathname(), $localName);
    }
}

$zip->close();
echo "OK: $dest\n";
