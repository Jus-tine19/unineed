<?php
$path = 'c:/xampp/htdocs/unineed/student/checkout.php';
$txt = file_get_contents($path);
$idx = strpos($txt, '} catch (Exception');
if ($idx === false) { echo "Pattern not found\n"; exit; }
$start = max(0, $idx - 20);
$end = min(strlen($txt)-1, $idx + 40);
for ($i = $start; $i <= $end; $i++) {
    $b = ord($txt[$i]);
    $ch = $txt[$i];
    if ($ch === "\n") $ch = '\\n';
    if ($ch === "\r") $ch = '\\r';
    if ($ch === "\t") $ch = '\\t';
    printf("%04d: 0x%02X  %s\n", $i, $b, $ch);
}
echo "\nSnippet: '" . substr($txt, $start, $end - $start + 1) . "'\n";