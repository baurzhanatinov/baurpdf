<?php
declare(strict_types=1);
namespace BaurPDF\Font;

use BaurPDF\Exception\BaurPdfException;

/**
 * Сабсеттинг таблицы CFF (OpenType/CFF, в т.ч. CID-keyed Noto CJK) для встраивания как FontFile3/CIDFontType0C.
 * Glyph id сохраняются: неиспользуемые CharStrings → endchar, неиспользуемые subrs → return.
 * Для CID-keyed шрифтов charset переписывается в тождественный (CID = GID), поэтому коды Identity-H = GID.
 */
final class CffSubsetter
{
    private string $d;
    /** @var array<int,true> */ private array $usedG = [];
    /** @var array<int,array<int,true>> */ private array $usedL = [];
    private int $nStems = 0;
    private int $depth = 0;
    private int $st = 0;
    private bool $ended = false;
    /** @var list<string> */ private array $gsubrs = [];
    /** @var list<list<string>> */ private array $lsubrs = [];

    public function __construct(string $cff) { $this->d = $cff; }

    /**
     * @param array<int,mixed>|null $want только эти элементы (для CharStrings больших CJK-шрифтов: не копировать 65 тыс. строк)
     * @return array{0:array<int,string>,1:int,2:int} элементы INDEX, смещение за ним, число элементов
     */
    private function index(int $o, ?array $want = null): array
    {
        $d = $this->d;
        if ($o + 2 > strlen($d)) throw new BaurPdfException('CFF: truncated INDEX');
        $n = (ord($d[$o]) << 8) | ord($d[$o + 1]);
        if ($n === 0) return [[], $o + 2, 0];
        $os = ord($d[$o + 2] ?? "\0"); $p = $o + 3;
        if ($os < 1 || $os > 4) throw new BaurPdfException('CFF: bad offSize');
        if ($p + ($n + 1) * $os > strlen($d)) throw new BaurPdfException('CFF: truncated INDEX');
        $off = static function (int $i) use ($d, $p, $os): int { $v = 0; $q = $p + $i * $os; for ($k = 0; $k < $os; $k++) $v = ($v << 8) | ord($d[$q + $k]); return $v; };
        $base = $p + ($n + 1) * $os - 1; $items = [];
        if ($want !== null) { foreach ($want as $i => $_) if ($i >= 0 && $i < $n) { $a = $off($i); $items[$i] = substr($d, $base + $a, max(0, $off($i + 1) - $a)); } }
        else { $prev = $off(0); for ($i = 0; $i < $n; $i++) { $nx = $off($i + 1); $items[] = substr($d, $base + $prev, max(0, $nx - $prev)); $prev = $nx; } }
        return [$items, $base + $off($n), $n];
    }

    /** @param list<string> $items */
    private static function buildIndex(array $items): string
    {
        $n = count($items);
        if ($n === 0) return "\0\0";
        $total = 1; foreach ($items as $it) $total += strlen($it);
        $os = $total < 0x100 ? 1 : ($total < 0x10000 ? 2 : ($total < 0x1000000 ? 3 : 4));
        $out = pack('n', $n) . chr($os); $off = 1; $offs = [];
        foreach ($items as $it) { $offs[] = $off; $off += strlen($it); } $offs[] = $off;
        foreach ($offs as $v) $out .= substr(pack('N', $v), 4 - $os);
        return $out . implode('', $items);
    }

    /** @return list<array{0:int,1:list<float|int>}> пары (оператор, операнды); оператор 12 x → 1200+x */
    private static function dict(string $s): array
    {
        $r = []; $ops = []; $i = 0; $n = strlen($s);
        while ($i < $n) {
            $b = ord($s[$i]);
            if ($b <= 21) {
                $op = $b; $i++;
                if ($b === 12) { $op = 1200 + ord($s[$i] ?? "\0"); $i++; }
                $r[] = [$op, $ops]; $ops = [];
            } elseif ($b === 28) { $v = (ord($s[$i + 1]) << 8) | ord($s[$i + 2]); $ops[] = $v >= 0x8000 ? $v - 0x10000 : $v; $i += 3; }
            elseif ($b === 29) { $v = (int)(unpack('N', $s, $i + 1) ?: [1 => 0])[1]; $ops[] = $v >= 0x80000000 ? $v - 0x100000000 : $v; $i += 5; }
            elseif ($b === 30) {
                $str = ''; $i++;
                while ($i < $n) {
                    $c = ord($s[$i++]);
                    foreach ([$c >> 4, $c & 15] as $nb) {
                        if ($nb === 15) break 2;
                        $str .= ['0','1','2','3','4','5','6','7','8','9','.','E','E-','','-'][$nb];
                    }
                }
                $ops[] = (float)$str;
            }
            elseif ($b >= 32 && $b <= 246) { $ops[] = $b - 139; $i++; }
            elseif ($b >= 247 && $b <= 250) { $ops[] = ($b - 247) * 256 + ord($s[$i + 1]) + 108; $i += 2; }
            elseif ($b >= 251 && $b <= 254) { $ops[] = -($b - 251) * 256 - ord($s[$i + 1]) - 108; $i += 2; }
            else $i++;
        }
        return $r;
    }

    private static function num(float|int $v): string
    {
        if (is_float($v) && floor($v) != $v) { // real
            $s = strtoupper(rtrim(rtrim(sprintf('%.8F', $v), '0'), '.'));
            $nib = [];
            for ($i = 0, $n = strlen($s); $i < $n; $i++) $nib[] = $s[$i] === '.' ? 10 : ($s[$i] === '-' ? 14 : (int)$s[$i]);
            $nib[] = 15; if (count($nib) % 2) $nib[] = 15;
            $o = "\x1e"; for ($i = 0; $i < count($nib); $i += 2) $o .= chr(($nib[$i] << 4) | $nib[$i + 1]);
            return $o;
        }
        $v = (int)$v;
        if ($v >= -107 && $v <= 107) return chr($v + 139);
        if ($v >= -32768 && $v <= 32767) return "\x1c" . pack('n', $v & 0xFFFF);
        return "\x1d" . pack('N', $v & 0xFFFFFFFF);
    }

    private static function int5(int $v): string { return "\x1d" . pack('N', $v & 0xFFFFFFFF); }

    /** @param list<array{0:int,1:list<float|int>}> $dict @param array<int,list<int>> $fixed */
    private static function writeDict(array $dict, array $fixed): string
    {
        $o = '';
        foreach ($dict as [$op, $ops]) {
            if (isset($fixed[$op])) { foreach ($fixed[$op] as $v) $o .= self::int5($v); }
            else foreach ($ops as $v) $o .= self::num($v);
            $o .= $op >= 1200 ? "\x0c" . chr($op - 1200) : chr($op);
        }
        return $o;
    }

    /** @param list<array{0:int,1:list<float|int>}> $dict @return list<float|int>|null */
    private static function get(array $dict, int $op): ?array { foreach ($dict as [$k, $v]) if ($k === $op) return $v; return null; }

    private static function bias(int $n): int { return $n < 1240 ? 107 : ($n < 33900 ? 1131 : 32768); }

    /** Проход по Type 2 charstring: отмечает вызываемые subrs (учёт стемов для hintmask). */
    private function scan(string $cs, int $fd): void
    {
        if (++$this->depth > 12) { $this->depth--; return; }
        $last = 0; $i = 0; $n = strlen($cs);
        while ($i < $n) {
            $b = ord($cs[$i]);
            if ($b >= 32 || $b === 28) {
                if ($b === 28) { $v = (ord($cs[$i + 1] ?? "\0") << 8) | ord($cs[$i + 2] ?? "\0"); $last = $v >= 0x8000 ? $v - 0x10000 : $v; $i += 3; }
                elseif ($b <= 246) { $last = $b - 139; $i++; }
                elseif ($b <= 250) { $last = ($b - 247) * 256 + ord($cs[$i + 1] ?? "\0") + 108; $i += 2; }
                elseif ($b <= 254) { $last = -($b - 251) * 256 - ord($cs[$i + 1] ?? "\0") - 108; $i += 2; }
                else { $last = 0; $i += 5; } // 16.16 fixed
                $this->st++; continue;
            }
            $i++;
            switch ($b) {
                case 1: case 3: case 18: case 23: $this->nStems += intdiv($this->st, 2); break;
                case 19: case 20: $this->nStems += intdiv($this->st, 2); $i += intdiv($this->nStems + 7, 8); break;
                case 10: case 29:
                    $this->st = max(0, $this->st - 1);
                    if ($b === 10) { $subs = $this->lsubrs[$fd] ?? []; $k = $last + self::bias(count($subs)); if (isset($subs[$k]) && !isset($this->usedL[$fd][$k])) { $this->usedL[$fd][$k] = true; } if (isset($subs[$k])) { $this->scan($subs[$k], $fd); } }
                    else { $k = $last + self::bias(count($this->gsubrs)); if (isset($this->gsubrs[$k])) { $this->usedG[$k] = true; $this->scan($this->gsubrs[$k], $fd); } }
                    if ($this->ended) { $this->depth--; return; }
                    continue 2;
                case 11: $this->depth--; return;
                case 14: $this->ended = true; $this->depth--; return;
                case 12: $i++; break;
            }
            $this->st = 0;
        }
        $this->depth--;
    }

    /** @param array<int,int|true> $gids используемые glyph id */
    public function subset(array $gids): string
    {
        set_error_handler(static function (int $no, string $msg): bool { throw new BaurPdfException('CFF: malformed font (' . $msg . ')'); });
        try { return $this->doSubset($gids); } finally { restore_error_handler(); }
    }

    /** @param array<int,int|true> $gids */
    private function doSubset(array $gids): string
    {
        $d = $this->d;
        $hdr = ord($d[2] ?? "\4");
        [$names, $p] = $this->index($hdr);
        [$tops, $p] = $this->index($p);
        [$strings, $p] = $this->index($p);
        [$this->gsubrs, ] = $this->index($p);
        if (!$tops) throw new BaurPdfException('CFF: no Top DICT');
        $top = self::dict($tops[0]);
        $csOff = self::get($top, 17); if ($csOff === null) throw new BaurPdfException('CFF: no CharStrings');
        $gids[0] = true;
        [$cs, , $nG] = $this->index((int)$csOff[0], $gids);
        $isCid = self::get($top, 1230) !== null;
        // Private dicts: name-keyed — один (fd 0), CID — из FDArray
        $fds = [];
        if ($isCid) {
            $fa = self::get($top, 1236); if ($fa === null) throw new BaurPdfException('CFF: no FDArray');
            [$fdItems, ] = $this->index((int)$fa[0]);
            foreach ($fdItems as $it) $fds[] = self::dict($it);
        } else $fds[] = [[18, self::get($top, 18) ?? [0, 0]]];
        $privs = [];
        foreach ($fds as $i => $fdict) {
            $pr = self::get($fdict, 18) ?? [0, 0]; $sz = (int)$pr[0]; $po = (int)($pr[1] ?? 0);
            $pd = self::dict(substr($d, $po, $sz));
            $sub = self::get($pd, 19);
            $this->lsubrs[$i] = $sub !== null ? $this->index($po + (int)$sub[0])[0] : [];
            $privs[$i] = $pd;
        }
        // FDSelect
        $fdOf = [];
        $fdsRaw = '';
        if ($isCid) {
            $fs = (int)(self::get($top, 1237) ?? [0])[0]; $fmt = ord($d[$fs]);
            if ($fmt === 0) { for ($g = 0; $g < $nG; $g++) $fdOf[$g] = ord($d[$fs + 1 + $g]); $fdsRaw = substr($d, $fs, 1 + $nG); }
            elseif ($fmt === 3) {
                $nr = (ord($d[$fs + 1]) << 8) | ord($d[$fs + 2]);
                $fdsRaw = substr($d, $fs, 5 + 3 * $nr);
                for ($r = 0; $r < $nr; $r++) {
                    $q = $fs + 3 + 3 * $r; $first = (ord($d[$q]) << 8) | ord($d[$q + 1]); $f = ord($d[$q + 2]);
                    $next = (ord($d[$q + 3]) << 8) | ord($d[$q + 4]);
                    for ($g = $first; $g < $next && $g < $nG; $g++) $fdOf[$g] = $f;
                }
            } else throw new BaurPdfException('CFF: unsupported FDSelect format');
        }
        $gids[0] = true;
        $newCs = $isCid ? [] : array_fill(0, $nG, "\x0e");
        foreach (array_keys($gids) as $g) {
            if ($g >= $nG) continue;
            $newCs[$g] = $cs[$g];
            $this->nStems = 0; $this->depth = 0; $this->st = 0; $this->ended = false;
            $this->scan($cs[$g], $fdOf[$g] ?? 0);
        }
        $gs = $this->gsubrs; foreach ($gs as $k => $_) if (!isset($this->usedG[$k])) $gs[$k] = "\x0b";
        if ($isCid) {
            // CID-keyed: оставить только используемые глифы; charset отображает новый GID → исходный CID (= старый GID),
            // поэтому коды в потоке содержимого (Identity-H, CID = старый GID) и /W не меняются
            $order = array_values(array_filter(array_keys($gids), fn($g) => $g < $nG)); sort($order);
            $compact = []; $fdsRaw = "\0"; $charset = "\0";
            foreach ($order as $g) { $compact[] = $newCs[$g] ?? "\x0e"; $fdsRaw .= chr($fdOf[$g] ?? 0); if ($g) $charset .= pack('n', $g); }
            $newCs = $compact;
        }
        // charset: CID — тождественный (CID = GID); name-keyed — исходный
        if (!$isCid) {
            $charset = null;
            $co = (int)(self::get($top, 15) ?? [0])[0];
            if ($co > 2) { // скопировать исходный charset
                $f = ord($d[$co]); $q = $co + 1; $left = $nG - 1;
                if ($f === 0) $q += 2 * $left;
                else while ($left > 0 && $q < strlen($d)) { $q += 2; $nl = $f === 1 ? ord($d[$q]) : (ord($d[$q]) << 8) | ord($d[$q + 1]); $q += $f === 1 ? 1 : 2; $left -= $nl + 1; }
                $charset = substr($d, $co, $q - $co);
            }
        }
        // раскладка: header | Name | Top | String | GSubrs | charset | FDSelect | CharStrings | FDArray | Privates(+Subrs)
        $topOps = array_values(array_filter($top, fn($e) => !in_array($e[0], [15, 16, 17, 18, 1236, 1237], true)));
        $fixedKeys = [17 => [0]]; if ($charset !== null) $fixedKeys[15] = [0];
        if ($isCid) { $fixedKeys[1236] = [0]; $fixedKeys[1237] = [0]; } else $fixedKeys[18] = [0, 0];
        /** @param array<int,list<int>> $vals */
        $mkTop = function (array $vals) use ($topOps): string {
            $t = $topOps; foreach ($vals as $op => $v) $t[] = [(int)$op, $v];
            // ROS должен идти первым
            usort($t, fn($a, $b) => ($b[0] === 1230) <=> ($a[0] === 1230));
            return self::writeDict($t, $vals);
        };
        $head = substr($d, 0, $hdr);
        $nameIdx = self::buildIndex($names); $strIdx = self::buildIndex($strings); $gIdx = self::buildIndex($gs);
        $topLen = strlen(self::buildIndex([$mkTop($fixedKeys)]));
        $o = strlen($head) + strlen($nameIdx) + $topLen + strlen($strIdx) + strlen($gIdx);
        $vals = [];
        $body = '';
        if ($charset !== null) { $vals[15] = [$o + strlen($body)]; $body .= $charset; }
        if ($isCid) { $vals[1237] = [$o + strlen($body)]; $body .= $fdsRaw; }
        $vals[17] = [$o + strlen($body)]; $body .= self::buildIndex($newCs);
        // Private dicts c локальными subrs
        $privBlobs = [];
        foreach ($privs as $i => $pd) {
            $ls = $isCid && !isset($this->usedL[$i]) ? [] : $this->lsubrs[$i]; // FD без используемых глифов — без Subrs
            foreach ($ls as $k => $_) if (!isset($this->usedL[$i][$k])) $ls[$k] = "\x0b";
            $pdN = array_values(array_filter($pd, fn($e) => $e[0] !== 19));
            $hasSubrs = count($ls) > 0;
            $plen = strlen(self::writeDict($pdN, [])) + ($hasSubrs ? 6 : 0);
            $blob = self::writeDict($pdN, []) . ($hasSubrs ? self::int5($plen) . "\x13" : '');
            $privBlobs[$i] = [$blob, $hasSubrs ? self::buildIndex($ls) : ''];
        }
        if ($isCid) {
            // FDArray с фиксированными (5-байтными) ссылками на Private
            $fdDicts = []; foreach ($fds as $i => $fdict) $fdDicts[] = self::writeDict(array_values(array_filter($fdict, fn($e) => $e[0] !== 18)), []) . self::int5(0) . self::int5(0) . "\x12";
            $faOff = $o + strlen($body); $faLen = strlen(self::buildIndex($fdDicts));
            $po = $faOff + $faLen; $fdDicts = [];
            foreach ($fds as $i => $fdict) {
                $fdDicts[] = self::writeDict(array_values(array_filter($fdict, fn($e) => $e[0] !== 18)), []) . self::int5(strlen($privBlobs[$i][0])) . self::int5($po) . "\x12";
                $po += strlen($privBlobs[$i][0]) + strlen($privBlobs[$i][1]);
            }
            $vals[1236] = [$faOff]; $body .= self::buildIndex($fdDicts);
        } else {
            $vals[18] = [strlen($privBlobs[0][0]), $o + strlen($body)];
        }
        foreach ($privBlobs as [$a, $b]) $body .= $a . $b;
        $topIdx = self::buildIndex([$mkTop($vals)]);
        if (strlen($topIdx) !== $topLen) throw new BaurPdfException('CFF: Top DICT size mismatch');
        return $head . $nameIdx . $topIdx . $strIdx . $gIdx . $body;
    }
}
