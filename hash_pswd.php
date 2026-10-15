<?php
$word = "admin123";

$hashedWord = password_hash($word, PASSWORD_BCRYPT);

echo $hashedWord;
?>
