<?php
// Test the Bible reference regex

$pattern = '/(?<!\p{L})('
         . '(?:[0-9]{1,2}\.?(?:\s|\x{00A0})*)?'   // optional leading number
         . '[\p{L}][\p{L}\p{M}\.]*'              // book name
         . '(?:(?:\s|\x{00A0})+[\p{L}\p{M}\.0-9!]+)*' // optional extra words
         . ')(?:\s|\x{00A0})*(\d+):(\d+)(?:-(\d+))?(?!\p{L})/u';

$test_strings = [
    'Hohelied 2:16',
    'Matthäus 2:15',
    '(Matthäus 2:15)',
    'Matthäus 2:15,',
    'Hohelied 2:15,',
    'Dieser Text mit Hohelied 2:16 sollte rendern.',
    'Und was ist mit Matthäus 2:15, ob das wohl linkT?',
];

echo "Testing Bible reference regex:\n\n";

foreach ($test_strings as $test) {
    echo "Testing: '$test'\n";
    if (preg_match($pattern, $test, $matches)) {
        echo "  ✓ MATCHED\n";
        echo "  Full match: '{$matches[0]}'\n";
        echo "  Book: '{$matches[1]}'\n";
        echo "  Chapter: '{$matches[2]}'\n";
        echo "  Verse: '{$matches[3]}'\n";
        if (isset($matches[4]) && $matches[4] !== '') {
            echo "  Verse end: '{$matches[4]}'\n";
        }
    } else {
        echo "  ✗ NO MATCH\n";
    }
    echo "\n";
}
