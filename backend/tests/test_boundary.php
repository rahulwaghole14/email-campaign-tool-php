<?php
$sample = "------=_Part_32579655_2115621236.17930362889632\nContent-Type: text/plain;charset=UTF-8\nContent-Transfer-Encoding: quoted-printable\nContent-ID: text-body\n\nHiring trends for Managing Director roles\n\n1% drop in Canada\n\n------=_Part_32579655_2115621236.17930362889632--";

preg_match_all('/^--([A-Za-z0-9_=\-\.\/]{8,120}?)(?:--)?$/m', $sample, $m);
$rawBoundaries = array_unique(array_map(fn($b) => rtrim($b, '-'), $m[1]));
print_r($rawBoundaries);

