<?php
$s = file_get_contents('c:/xampp/htdocs/unineed/tools/chunk_300.php');
echo 'Open:{ '.substr_count($s,'{').' Close:} '.substr_count($s,'}')."\n";