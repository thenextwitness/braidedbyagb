<?php
// ============================================================
// Guard: no nested <form> in any PHP template.
// FILE: /tools/check-nested-forms.php   (run: php tools/check-nested-forms.php)
//
// Browsers ignore a nested <form> start tag and merge its fields into the outer
// form. Two real bugs came from this: "Save Changes" on a service sent
// action=delete_service_permanent (deleting services), and "Send Reply" on a
// custom request only marked it viewed. Put the second button OUTSIDE the form
// and link it with form="id" instead. Exits 1 on any finding.
// Not deployed (tools/ isn't in .cpanel.yml).
// ============================================================
$root  = dirname(__DIR__);
$files = array_filter(explode("\n", trim((string)shell_exec('git -C ' . escapeshellarg($root) . ' ls-files "*.php"'))));
$hits  = 0;
foreach ($files as $rel) {
    $src = @file_get_contents($root . '/' . $rel);
    if ($src === false || stripos($src, '<form') === false) continue;
    $depth = 0; $openLine = 0;
    foreach (token_get_all($src) as $t) {
        if (!is_array($t) || $t[0] !== T_INLINE_HTML) continue;   // template HTML only
        [, $txt, $line] = $t;
        // Ignore HTML comments, keeping newlines so line numbers stay right.
        $txt = preg_replace_callback('#<!--.*?-->#s', fn($c) => str_repeat("\n", substr_count($c[0], "\n")), $txt);
        if (!preg_match_all('#<form\b|</form\s*>#i', $txt, $m, PREG_OFFSET_CAPTURE)) continue;
        foreach ($m[0] as [$tag, $off]) {
            $ln = $line + substr_count(substr($txt, 0, $off), "\n");
            if (stripos($tag, '</') === 0) { $depth = max(0, $depth - 1); continue; }
            if ($depth >= 1) { fwrite(STDERR, "NESTED <form> in $rel:$ln (inside the form opened at line $openLine)\n"); $hits++; }
            else $openLine = $ln;
            $depth++;
        }
    }
}
if ($hits) { fwrite(STDERR, "$hits nested form(s). Move the inner form outside and use form=\"id\" on its button.\n"); exit(1); }
echo "OK: no nested forms.\n";
