<?php
$lines = file('c:/xampp/htdocs/unineed/student/checkout.php');
$out = array_slice($lines,0,300);
file_put_contents('c:/xampp/htdocs/unineed/tools/chunk_300.php', implode('', $out) . "\n?>");
echo "Wrote chunk_300\n";
