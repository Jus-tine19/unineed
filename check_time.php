<?php
require 'config/database.php';

$r = mysqli_query($conn, "SELECT NOW() as now, @@session.time_zone as session_tz, @@global.time_zone as global_tz");
$row = mysqli_fetch_assoc($r);
echo "PHP date(): " . date('Y-m-d H:i:s') . "\n";
echo "MySQL NOW(): " . $row['now'] . "\n";
echo "MySQL session.time_zone: " . $row['session_tz'] . "\n";
echo "MySQL global.time_zone: " . $row['global_tz'] . "\n";
?>