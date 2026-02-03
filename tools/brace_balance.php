<?php
$lines = file('c:/xampp/htdocs/unineed/student/checkout.php');
$balance = 0;
foreach($lines as $i=>$line){
    $opens = substr_count($line,'{');
    $closes = substr_count($line,'}');
    $balance += $opens - $closes;
    // print only when balance changes
    if ($opens || $closes) {
        printf('%4d: opens=%d closes=%d balance=%d %s', $i+1, $opens, $closes, $balance, rtrim($line)."\n");
    }
}
echo "Final balance: $balance\n";