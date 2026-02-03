<?php
$code = file_get_contents('c:/xampp/htdocs/unineed/student/checkout.php');
$tokens = token_get_all($code);
for ($i=0; $i < count($tokens); $i++) {
    $t = $tokens[$i];
    if (is_array($t) && $t[0] == T_CATCH) {
        echo "Found catch token at index $i\n";
        for ($j=max(0,$i-50); $j<$i+50 && $j<count($tokens); $j++) {
            $tk = $tokens[$j];
            if (is_array($tk)) {
                echo $j . ': ' . token_name($tk[0]) . ' => ' . str_replace("\n","\\n",$tk[1]) . "\n";
            } else {
                echo $j . ': CHAR => ' . $tk . "\n";
            }
        }
        break;
    }
}
