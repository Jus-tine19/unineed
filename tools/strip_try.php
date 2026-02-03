<?php
$path = 'c:/xampp/htdocs/unineed/student/checkout.php';
$code = file_get_contents($path);
$pattern = '/try\s*\{.*?\}\s*catch\s*\(.*?\)\s*\{.*?\}/s';
$replaced = preg_replace($pattern, '/* TRY_BLOCK_REMOVED */', $code, 1, $count);
if ($count === 0) { echo "No try-catch found to remove\n"; exit; }
file_put_contents('c:/xampp/htdocs/unineed/tools/checkout_stripped.php', $replaced);
echo "Wrote stripped file\n";