<?php
/**
 * scan_project.php
 *
 * Escaneia recursivamente um projeto e lista imports + caminhos hardcoded
 * que provavelmente precisarão ser ajustados ao migrar para hospedagem.
 *
 * Uso:
 *   php scan_project.php [diretório] [--no-color]
 *
 * Exemplos:
 *   php scan_project.php C:\xampp\htdocs\meu_projeto
 *   php scan_project.php ./app --no-color > relatorio.txt
 *
 * Requer PHP 7.4+
 */

if (PHP_SAPI !== 'cli') exit("Execute via linha de comando.\n");

// ---------- Argumentos ----------
$target = null; $useColor = true;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--no-color')      $useColor = false;
    elseif ($target === null && $arg[0] !== '-') $target = $arg;
}
$target = rtrim(realpath($target ?? getcwd()) ?: ($target ?? getcwd()), '/\\');
if (!is_dir($target)) exit("Diretório não encontrado: {$target}\n");

// ---------- Configuração ----------
$skipDirs = ['vendor','node_modules','.git','.svn','.hg','.idea','.vscode',
             'cache','logs','tmp','temp','storage','dist','build','coverage'];

$extensions = ['php','phtml','js','mjs','cjs','jsx','ts','tsx',
               'html','htm','css','scss','sass','less'];

// Grupo 1 do regex = caminho/URL do import
$importPatterns = [
    'php' => [
        ['/^\s*(?:require|require_once|include|include_once)\s*\(?\s*[\'"]([^\'"]+)[\'"]/i', 'include/require'],
        ['/^\s*use\s+([A-Za-z_][\w\\\\]+)/i', 'use'],
    ],
    'js' => [
        ['/^\s*import\b[^;]*?\bfrom\s+[\'"]([^\'"]+)[\'"]/m', 'import'],
        ['/^\s*import\s+[\'"]([^\'"]+)[\'"]/m', 'import'],
        ['/\brequire\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)/', 'require()'],
        ['/\bimport\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)/', 'import()'],
    ],
    'css' => [
        ['/@import\s+(?:url\(\s*)?[\'"]([^\'"]+)[\'"]/', '@import'],
        ['/url\(\s*[\'"]?([^\'")]+?)[\'"]?\s*\)/', 'url()'],
    ],
    'html' => [
        ['/(?:src|href)\s*=\s*[\'"]([^\'"]+)[\'"]/i', 'src/href'],
    ],
];

// Testado em TODA linha — o que quebra ao migrar
$suspiciousPatterns = [
    ['/\blocalhost\b|\b127\.0\.0\.1\b/', 'localhost'],
    ['/[A-Za-z]:[\\\\\/](?:xampp|wamp|laragon|htdocs)/i', 'caminho Windows/XAMPP'],
    ['/\/(?:var\/www|xampp\/htdocs)\//i', 'caminho servidor'],
];

function familyOf($ext) {
    if (in_array($ext, ['ts','tsx','jsx','mjs','cjs'], true)) return 'js';
    if (in_array($ext, ['scss','sass','less'], true))        return 'css';
    return $ext;
}
function c($t, $code, $on) { return $on ? "\033[{$code}m{$t}\033[0m" : $t; }
function truncate($s, $n = 60) {
    $s = trim(preg_replace('/\s+/', ' ', $s));
    return strlen($s) > $n ? substr($s, 0, $n - 3) . '...' : $s;
}

// ---------- Varredura ----------
$files = [];
$stats = ['scanned'=>0,'with_items'=>0,'imports'=>0,'suspicious'=>0,'by_type'=>[]];

$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($target, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);

foreach ($it as $file) {
    if (!$file->isFile()) continue;
    $path = $file->getPathname();
    $rel  = ltrim(substr($path, strlen($target)), '/\\');

    if (preg_match('/\.min\.(js|css)$/i', $path)) continue;

    $skip = false;
    foreach ($skipDirs as $sd) {
        if (preg_match('#(^|[\\\\/])' . preg_quote($sd, '#') . '([\\\\/]|$)#', $rel)) { $skip = true; break; }
    }
    if ($skip) continue;

    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if (!in_array($ext, $extensions, true)) continue;

    $stats['scanned']++;
    $lines = @file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) continue;

    $patterns = $importPatterns[familyOf($ext)] ?? [];
    $issues = [];

    foreach ($lines as $i => $line) {
        if (trim($line) === '') continue;

        $suspicious = false; $reason = '';
        foreach ($suspiciousPatterns as [$re, $lbl]) {
            if (preg_match($re, $line)) { $suspicious = true; $reason = $lbl; break; }
        }

        $import = null;
        foreach ($patterns as [$re, $lbl]) {
            if (preg_match($re, $line, $m)) { $import = ['type'=>$lbl,'target'=>$m[1]]; break; }
        }
        if (!$import && !$suspicious) continue;

        $issues[] = [
            'line' => $i + 1,
            'type' => $import['type'] ?? 'caminho',
            'target' => $import['target'] ?? trim($line),
            'suspicious' => $suspicious,
            'reason' => $reason,
        ];
        if ($import) {
            $stats['imports']++;
            $stats['by_type'][$import['type']] = ($stats['by_type'][$import['type']] ?? 0) + 1;
        }
        if ($suspicious) $stats['suspicious']++;
    }

    if ($issues) { $stats['with_items']++; $files[$rel] = $issues; }
}

// ---------- Relatório ----------
$line = str_repeat('=', 72);
echo "\n" . c($line, '1;36', $useColor) . "\n";
echo c("  SCANNER DE IMPORTS E CAMINHOS PARA MIGRAÇÃO\n", '1;36', $useColor);
echo c($line, '1;36', $useColor) . "\n";
printf("Diretório       : %s\n", $target);
printf("Arquivos        : %d escaneados, %d com itens\n", $stats['scanned'], $stats['with_items']);
printf("Imports         : %d\n", $stats['imports']);
printf("Itens suspeitos : %s\n", c($stats['suspicious'], $stats['suspicious'] ? '1;31' : '1;32', $useColor));
echo str_repeat('-', 72) . "\n\n";

if (!$files) { echo c("Nenhum item encontrado. 🎉\n\n", '1;32', $useColor); exit; }

// Ordena: mais suspeitos primeiro
uksort($files, function ($a, $b) use ($files) {
    $sa = count(array_filter($files[$a], function($x){ return $x['suspicious']; }));
    $sb = count(array_filter($files[$b], function($x){ return $x['suspicious']; }));
    return $sa !== $sb ? $sb - $sa : count($files[$b]) - count($files[$a]);
});

$n = 0;
foreach ($files as $rel => $issues) {
    $n++;
    $susp = count(array_filter($issues, function($x){ return $x['suspicious']; }));
    $badge = $susp ? c(" [!] {$susp} suspeito(s)", '1;31', $useColor) : '';
    printf("[%02d] %s%s\n", $n, c($rel, '1;33', $useColor), $badge);

    foreach ($issues as $it) {
        $flag = $it['suspicious'] ? c("  <<< {$it['reason']}", '1;31', $useColor) : '';
        printf("    L%-4d %-16s %s%s\n",
            $it['line'], $it['type'], truncate($it['target']), $flag);
    }
    echo "\n";
}

// Resumo por tipo
if (!empty($stats['by_type'])) {
    echo str_repeat('-', 72) . "\n" . "Resumo por tipo de import:\n";
    arsort($stats['by_type']);
    foreach ($stats['by_type'] as $type => $count) printf("  %-20s %d\n", $type, $count);
    echo "\n";
}

// Top arquivos críticos
$critical = array_filter($files, function($arr){
    return count(array_filter($arr, function($x){ return $x['suspicious']; })) > 0;
});
if ($critical) {
    uasort($critical, function ($a, $b) {
        return count(array_filter($b, function($x){ return $x['suspicious']; }))
             - count(array_filter($a, function($x){ return $x['suspicious']; }));
    });
    echo str_repeat('-', 72) . "\n";
    echo c("TOP arquivos que mais precisam de atenção:\n", '1;31', $useColor);
    $k = 0;
    foreach ($critical as $rel => $arr) {
        if (++$k > 10) break;
        $s = count(array_filter($arr, function($x){ return $x['suspicious']; }));
        printf("  %d. %s (%d item(ns))\n", $k, $rel, $s);
    }
    echo "\n";
}