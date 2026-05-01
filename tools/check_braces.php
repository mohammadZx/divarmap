#!/usr/bin/env php
<?php
/** بررسی تعادل { } در یک فایل PHP (بدون نیاز به پارسر PHP). */
declare(strict_types=1);

$file = $argv[1] ?? dirname(__DIR__) . '/divar_map_collect.php';
if (!is_readable($file)) {
    fwrite(STDERR, "Cannot read: {$file}\n");
    exit(2);
}
$s = file_get_contents($file);
if ($s === false) {
    exit(2);
}
$bal = 0;
$line = 1;
for ($i = 0, $len = strlen($s); $i < $len; $i++) {
    $ch = $s[$i];
    if ($ch === "\n") {
        $line++;
        continue;
    }
    if ($ch === '{') {
        $bal++;
    } elseif ($ch === '}') {
        $bal--;
        if ($bal < 0) {
            fwrite(STDERR, "Extra '}' around line {$line}\n");
            exit(1);
        }
    }
}
if ($bal !== 0) {
    fwrite(STDERR, "Unbalanced braces: balance={$bal} (missing closing braces)\n");
    exit(1);
}
fwrite(STDOUT, "OK: braces balanced in {$file}\n");
exit(0);
