<?php
/**
 * check_case.php
 * Detecta problemas de case (Windows → Linux) em include/require e use.
 *
 * Uso:
 *   php check_case.php [diretório] [--no-color] [--autoload=Prefixo:caminho]...
 *
 * Exemplos:
 *   php check_case.php C:\xampp\htdocs\Transportes
 *   php check_case.php . --autoload=Core:src/Core --autoload=Helpers:src/Helpers
 *   php check_case.php . --no-color > case_report.txt
 *
 * Requer PHP 7.4+
 */

if (PHP_SAPI !== 'cli') exit("Execute via linha de comando.\n");

// ---------- Argumentos ----------
$target = null;
$useColor = true;
$autoloadRoots = [];  // prefixo => base_dir (relativo ao $target)

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--no-color') { $useColor = false; continue; }
    if (preg_match('/^--autoload=([^:]*):(.+)$/', $arg, $m)) {
        $autoloadRoots[$m[1]] = rtrim($m[2], '/\\');
        continue;
    }
    if ($target === null && $arg[0] !== '-') $target = $arg;
}

$target = rtrim(realpath($target ?? getcwd()) ?: ($target ?? getcwd()), '/\\');
if (!is_dir($target)) exit("Diretório não encontrado: {$target}\n");

// ---------- Auto-detect via composer.json ----------
if (empty($autoloadRoots) && is_file($target . '/composer.json')) {
    $composer = json_decode(@file_get_contents($target . '/composer.json'), true);
    if (is_array($composer) && !empty($composer['autoload']['psr-4'])) {
        foreach ($composer['autoload']['psr-4'] as $prefix => $dirs) {
            foreach ((array) $dirs as $dir) {
                $autoloadRoots[rtrim($prefix, '\\')] = rtrim($dir, '/\\');
            }
        }
    }
}

// Fallback: assume src/ como raiz PSR-4
$autoFallback = empty($autoloadRoots) && is_dir($target . '/src');

// ---------- Helpers ----------
function c($t, $code, $on) { return $on ? "\033[{$code}m{$t}\033[0m" : $t; }

function normalizeParts($path) {
    $path  = str_replace('\\', '/', $path);
    $parts = [];
    foreach (explode('/', $path) as $p) {
        if ($p === '' || $p === '.') continue;
        if ($p === '..') { array_pop($parts); continue; }
        $parts[] = $p;
    }
    return $parts;
}

function isWindowsAbs($path) {
    return (bool) preg_match('#^[A-Za-z]:[\\\\/]#', $path);
}

/**
 * Verifica existência com case EXATO (funciona no Windows também).
 */
function existsExact($path) {
    $isUnixAbs = strpos(str_replace('\\', '/', $path), '/') === 0;
    $parts     = normalizeParts($path);
    $current   = $isUnixAbs ? '/' : '';
    $first     = true;

    foreach ($parts as $part) {
        if ($first && preg_match('/^[A-Za-z]:$/', $part)) {
            $current = $part . '/';
            $first   = false;
            continue;
        }
        $first = false;

        $entries = @scandir($current ?: '.');
        if ($entries === false) return false;

        if (!in_array($part, $entries, true)) return false;
        $current = rtrim($current, '/') . '/' . $part;
    }
    return true;
}

/**
 * Retorna o caminho REAL (case do disco) se existir, mesmo com case diferente.
 */
function findRealPath($path) {
    $isUnixAbs = strpos(str_replace('\\', '/', $path), '/') === 0;
    $parts     = normalizeParts($path);
    $current   = $isUnixAbs ? '/' : '';
    $first     = true;

    foreach ($parts as $part) {
        if ($first && preg_match('/^[A-Za-z]:$/', $part)) {
            $current = $part . '/';
            $first   = false;
            continue;
        }
        $first = false;

        $entries = @scandir($current ?: '.');
        if ($entries === false) return null;

        $match = null;
        if (in_array($part, $entries, true)) {
            $match = $part;
        } else {
            foreach ($entries as $e) {
                if (strcasecmp($e, $part) === 0) { $match = $e; break; }
            }
            if ($match === null) return null;
        }
        $current = rtrim($current, '/') . '/' . $match;
    }
    return $current;
}

function pathRelative($from, $to) {
    $f = normalizeParts($from);
    $t = normalizeParts($to);
    while ($f && $t && $f[0] === $t[0]) { array_shift($f); array_shift($t); }
    return str_repeat('../', count($f)) . implode('/', $t);
}

/**
 * Converte "App\Sub\Classe" em caminho absoluto do arquivo esperado.
 */
function guessClassFile($class, $autoloadRoots, $target, $autoFallback) {
    $class = ltrim($class, '\\');

    foreach ($autoloadRoots as $prefix => $baseDir) {
        if ($prefix === '') {
            $rel = str_replace('\\', '/', $class);
        } elseif (strpos($class, $prefix . '\\') === 0) {
            $rel = str_replace('\\', '/', substr($class, strlen($prefix) + 1));
        } else {
            continue;
        }
        return $target . '/' . trim($baseDir, '/\\') . '/' . $rel . '.php';
    }

    if ($autoFallback) {
        $rel = str_replace('\\', '/', $class);
        return $target . '/src/' . $rel . '.php';
    }

    return null;
}

/**
 * Sugere renomeação do primeiro segmento divergente entre esperado e real.
 */
function suggestRename($expected, $real, $target) {
    $e = normalizeParts(substr($expected, strlen($target)));
    $r = normalizeParts(substr($real,     strlen($target)));
    for ($i = 0; $i < min(count($e), count($r)); $i++) {
        if ($e[$i] !== $r[$i]) {
            $dir = implode('/', array_slice($r, 0, $i));
            return [
                'from' => ($dir ? $dir . '/' : '') . $r[$i],
                'to'   => ($dir ? $dir . '/' : '') . $e[$i],
            ];
        }
    }
    return null;
}

// ---------- Coleta de arquivos PHP ----------
$skipDirs = ['vendor', 'node_modules', '.git', '.svn', '.hg', 'cache', 'logs', 'tmp', 'storage'];

$phpFiles = [];
$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($target, FilesystemIterator::SKIP_DOTS)
);
foreach ($it as $file) {
    if (!$file->isFile()) continue;
    if (strtolower($file->getExtension()) !== 'php') continue;
    $rel = ltrim(substr($file->getPathname(), strlen($target)), '/\\');
    foreach ($skipDirs as $sd) {
        if (preg_match('#(^|[\\\\/])' . preg_quote($sd, '#') . '([\\\\/]|$)#', $rel)) continue 2;
    }
    $phpFiles[] = ['abs' => $file->getPathname(), 'rel' => $rel];
}

// ---------- Varredura ----------
$files = [];
$stats = ['scanned' => 0, 'ok' => 0, 'case' => 0, 'missing' => 0, 'skip' => 0];
$suggestions = [];

foreach ($phpFiles as $f) {
    $stats['scanned']++;
    $lines = @file($f['abs'], FILE_IGNORE_NEW_LINES);
    if ($lines === false) continue;

    $fileDir = dirname($f['abs']);
    $issues  = [];

    foreach ($lines as $i => $line) {
        if (trim($line) === '') continue;
        if (preg_match('#^\s*(?://|\*|\#)#', $line)) continue;  // comentário

        $declared = null;
        $resolved = null;
        $kind     = null;

        // include/require com __DIR__
        if (preg_match('/^\s*(?:require|require_once|include|include_once)\s*\(?\s*__DIR__\s*\.\s*([\'"])([^\'"]+)\1/', $line, $m)) {
            $kind     = 'include';
            $declared = '__DIR__' . $m[2];
            $resolved = $fileDir . $m[2];
        }
        // include/require literal
        elseif (preg_match('/^\s*(?:require|require_once|include|include_once)\s*\(?\s*([\'"])([^\'"]+)\1/', $line, $m)) {
            $kind     = 'include';
            $declared = $m[2];
            $resolved = (isWindowsAbs($declared) || strpos($declared, '/') === 0)
                ? $declared
                : $fileDir . '/' . $declared;
        }
        // use (ignora use function / use const / classes globais)
        elseif (preg_match('/^\s*use\s+(?:function|const)\s+/', $line)) {
            $stats['skip']++;
            continue;
        }
        elseif (preg_match('/^\s*use\s+(\\\\?[A-Za-z_][\w\\\\]*)\s*(?:as\s+\w+)?\s*;/', $line, $m)) {
            if (strpos($m[1], '\\') === false) { $stats['skip']++; continue; }
            $kind     = 'use';
            $declared = $m[1];
            $resolved = guessClassFile($declared, $autoloadRoots, $target, $autoFallback);
            if ($resolved === null) { $stats['skip']++; continue; }
        }
        else {
            continue;
        }

        if (existsExact($resolved)) {
            $stats['ok']++;
            continue;
        }

        $real = findRealPath($resolved);
        if ($real !== null) {
            $issues[] = [
                'line'     => $i + 1,
                'kind'     => $kind,
                'declared' => $declared,
                'expected' => $resolved,
                'real'     => $real,
                'status'   => 'CASE',
            ];
            $stats['case']++;
            $sug = suggestRename($resolved, $real, $target);
            if ($sug) $suggestions[$sug['from'] . "\0" . $sug['to']] = $sug;
        } else {
            $issues[] = [
                'line'     => $i + 1,
                'kind'     => $kind,
                'declared' => $declared,
                'expected' => $resolved,
                'real'     => null,
                'status'   => 'MISSING',
            ];
            $stats['missing']++;
        }
    }

    if ($issues) $files[$f['rel']] = $issues;
}

// ---------- Relatório ----------
$sep = str_repeat('=', 78);
echo "\n" . c($sep, '1;36', $useColor) . "\n";
echo c("  CHECK DE CASE (Windows -> Linux)\n", '1;36', $useColor);
echo c($sep, '1;36', $useColor) . "\n";
printf("Diretório       : %s\n", $target);
printf("Autoload        : %s\n",
    empty($autoloadRoots)
        ? ($autoFallback ? 'auto (src/)' : 'nenhum')
        : json_encode($autoloadRoots));
printf("Arquivos PHP    : %d\n", $stats['scanned']);
printf("OK              : %s\n", c($stats['ok'], '1;32', $useColor));
printf("Ignorados       : %d (globais / sem mapping)\n", $stats['skip']);
printf("CASE mismatch   : %s\n", c($stats['case'], $stats['case'] ? '1;31' : '1;32', $useColor));
printf("Não encontrados : %s\n", c($stats['missing'], $stats['missing'] ? '1;33' : '1;32', $useColor));
echo str_repeat('-', 78) . "\n\n";

if (!$files) {
    echo c("Nenhum problema de case encontrado. Pode subir para Linux.\n\n", '1;32', $useColor);
    exit(0);
}

$n = 0;
foreach ($files as $rel => $issues) {
    $n++;
    printf("[%02d] %s\n", $n, c($rel, '1;33', $useColor));
    foreach ($issues as $it) {
        printf("    L%-4d %-8s %s\n", $it['line'], $it['kind'], $it['declared']);
        printf("            esperado : %s\n", pathRelative($target, $it['expected']));
        if ($it['real'] !== null) {
            printf("            real     : %s  %s\n",
                pathRelative($target, $it['real']),
                c('<- CASE MISMATCH', '1;31', $useColor));
        } else {
            printf("            %s\n", c('<- ARQUIVO NÃO ENCONTRADO', '1;33', $useColor));
        }
    }
    echo "\n";
}

if (!empty($suggestions)) {
    echo str_repeat('-', 78) . "\n";
    echo c("Sugestões de renomeação (corrigem TODOS os CASE mismatches):\n", '1;31', $useColor);
    foreach ($suggestions as $sug) {
        printf("    Renomear  %s  ->  %s\n", c($sug['from'], '1;33', $useColor), c($sug['to'], '1;32', $useColor));
    }
    echo "\n";
    echo "No Windows (PowerShell, feche Apache antes):\n";
    foreach ($suggestions as $sug) {
        $from = $target . '/' . $sug['from'];
        $to   = basename($sug['to']);
        printf("    Rename-Item \"%s\" \"%s\"\n", str_replace('/', '\\', $from), $to);
    }
    echo "\n";
}

echo str_repeat('-', 78) . "\n";
if ($stats['case'] > 0) {
    echo c("ATENCAO: {$stats['case']} CASE MISMATCH(es). Corrija ANTES de subir para Linux.\n", '1;31', $useColor);
    exit(1);
}
if ($stats['missing'] > 0) {
    echo c("ATENCAO: {$stats['missing']} arquivo(s) nao encontrado(s). Verifique se sao erros ou paths dinamicos.\n", '1;33', $useColor);
    exit(1);
}
echo c("Tudo ok.\n", '1;32', $useColor);
exit(0);