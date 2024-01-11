<?php

$directory = '../resources/views';
$poFile = 'messages.po'; // Output .po file
$copyMsgIdToMsgStr = true; // Set to true to copy msgid to msgstr

$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
$regex = new RegexIterator($iterator, '/^.+\.twig$/i', RecursiveRegexIterator::GET_MATCH);

$translations = [];

foreach ($regex as $file) {
    $content = file_get_contents($file[0]);
    preg_match_all("/\{\{\s*__\('((?:[^'\\\\]|\\\\.)*)'\)\s*\}\}/", $content, $matches);

    if (!empty($matches[1])) {
        $translations = array_merge($translations, $matches[1]);
    }
}

$translations = array_unique($translations); // Remove duplicates

$poContent = "";

foreach ($translations as $translation) {
    // Handle the escaped single quotes correctly
    $translation = str_replace("\\'", "'", $translation);
    $poContent .= "msgid \"" . $translation . "\"\n";
    $poContent .= "msgstr \"" . ($copyMsgIdToMsgStr ? $translation : '') . "\"\n\n";
}

file_put_contents($poFile, $poContent);

echo "Extraction complete. Check the $poFile file.".PHP_EOL;
