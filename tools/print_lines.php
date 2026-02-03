<?php
$file = 'c:/xampp/htdocs/unineed/student/checkout.php';
$lines = file($file);
foreach ($lines as $i => $line) {
    $num = $i + 1;
    printf("%4d: %s", $num, $line);
}
