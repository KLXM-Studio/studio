<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Copyright (C) 2026 KLXM and contributors (see LICENSE, COPYRIGHT)
declare(strict_types=1);

namespace Core;

/**
 * Gebaute Assets im eigenen public/ auf dem Stand halten (extensions:publish, kits:publish, health).
 *
 * Normale Installation (ROOT = Kern): tools/build.mjs schreibt direkt nach public/assets/… – hier gibt es nur Erweiterungen
 * mit fertigem Ordner public/ (Composer-Pakete), die als Kopie veröffentlicht werden.
 *
 * Projekt-Installation mit verknüpftem Kern (KLXM_ROOT, eigenes public/): der Kern baut seine Kern-Assets
 * (public/assets/{css,js,icons,…}), die mitgelieferten Erweiterungen (public/assets/ext/{name}) und Kits
 * (public/assets/kits/{name}) in SEIN public/. Das Projekt-public/ bekommt sie als Verknüpfung auf den Kern-Ordner –
 * eine alte Kopie (z. B. consent.js von vor Wochen) wird ersetzt. Mit $copy (--copy) bzw. wo keine Verknüpfung
 * möglich ist: Kopie mit Prüfsummen-Vergleich (nur geänderte Dateien; Dateien, die es in der Quelle nicht mehr gibt, fallen weg).
 *
 * Sicherheit: Ziele nur direkt unter public/assets (Kern-Ordner) bzw. public/assets/{ext,kits}/{name}, nie außerhalb;
 * Verknüpfungen nur auf Ordner unter {Kern}/public/assets; beim Kopieren keine Verknüpfungen, versteckten Dateien oder
 * Server-Code (.php, .phtml, .phar, CGI-Skripte …); beim Entfernen werden Verknüpfungen nie verfolgt.
 * Ordner der Installation innerhalb eines Kern-Ordners (KEEP, z. B. public/assets/fonts/installed) bleiben unberührt.
 */
final class AssetSync
{
    public const LINK = 'link';
    public const COPY = 'copy';

    /** Nie veröffentlicht: Server-Code und -Konfiguration (aus public/assets wird nichts ausgeführt) */
    private const DENY = '~\.(php\d?|phtml|phar|pht|phps|inc|cgi|pl|py|sh|bash|shtml|asp|aspx|jsp)$~i';

    /** Ordner der Installation innerhalb von Kern-Asset-Ordnern – nie kopiert, verknüpft oder entfernt */
    public const KEEP = ['fonts' => ['installed']];

    /** Gültiger Ordnername (Kit, Erweiterung, Kern-Ordner) */
    private const NAME = '~^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$~';

    /** Kern-Ordner (dort liegt app/; PHP löst __DIR__ über Verknüpfungen auf) */
    public static function core(): string
    {
        return self::real(dirname(__DIR__));
    }

    /** Installation (ROOT) */
    public static function root(): string
    {
        return self::real(ROOT);
    }

    /** Projekt-Installation mit verknüpftem Kern (eigenes public/)? */
    public static function linked(): bool
    {
        return self::root() !== self::core();
    }

    // ------------------------------------------------------------------ Pläne

    /**
     * Kern-Assets public/assets/{css,js,…} (ohne kits/ und ext/) – nur in Projekt-Installationen.
     * @return list<array<string, mixed>>
     */
    public static function corePlan(?string $root = null, ?string $core = null): array
    {
        $root = $root ?? self::root();
        $core = $core ?? self::core();
        if ($root === $core) return [];
        $out = [];
        foreach (scandir("$core/public/assets") ?: [] as $d) {
            if (in_array($d, ['.', '..', 'kits', 'ext'], true) || !preg_match(self::NAME, $d) || is_link("$core/public/assets/$d") || !is_dir("$core/public/assets/$d")) continue;
            // Ordner mit Daten der Installation (Schriften) nie verknüpfen, sonst landen sie im Kern
            $keep = self::KEEP[$d] ?? [];
            $out[] = self::entry('core', $d, "$core/public/assets/$d", "$root/public/assets", $keep ? self::COPY : self::LINK, $keep, 'extensions:publish');
        }
        return $out;
    }

    /**
     * Erweiterungen: mitgeliefert (dieselbe Erweiterung liegt im Kern, Projekt verknüpft) → gebauter Kern-Ordner
     * public/assets/ext/{name}; sonst fertiger Ordner public/ des Pakets (Kopie); sonst assets/ unverändert, aber nur, wenn
     * noch nichts veröffentlicht ist (Composer-Paket ohne pnpm auf dem Server – ein pnpm-Build wird nie überschrieben).
     * @param array<string, array<string, mixed>>|null $available Extensions::available()
     * @return list<array<string, mixed>>
     */
    public static function extensionPlan(?array $available = null, ?string $root = null, ?string $core = null): array
    {
        $available = $available ?? Extensions::available();
        $root = $root ?? self::root();
        $core = $core ?? self::core();
        $out = [];
        foreach ($available as $n => $m) {
            $n = (string) $n;
            if (!preg_match(self::NAME, $n)) continue;
            $dir = self::real((string) ($m['dir'] ?? ''));
            $dst = "$root/public/assets/ext/$n";
            $built = "$core/public/assets/ext/$n";
            if ($root !== $core && $dir !== '' && is_dir("$core/extensions/$n") && self::real("$core/extensions/$n") === $dir && is_dir($built)) {
                $out[] = self::entry('ext', $n, $built, "$root/public/assets/ext", self::LINK, [], 'extensions:publish');
            } elseif ($dir !== '' && is_dir("$dir/public")) {
                $out[] = self::entry('ext', $n, "$dir/public", "$root/public/assets/ext", self::COPY, [], 'extensions:publish');
            } elseif ($dir !== '' && is_dir("$dir/assets") && !file_exists($dst) && !is_link($dst)) {
                $out[] = self::entry('ext', $n, "$dir/assets", "$root/public/assets/ext", self::COPY, [], 'extensions:publish') + ['once' => true];
            }
        }
        return $out;
    }

    /**
     * Kits aus dem Kern (Projekt verknüpft kits/{name} auf den Kern) → gebauter Kern-Ordner public/assets/kits/{name}.
     * Kit-Pakete (Upload, Composer) veröffentlicht weiter KitPackages::publish.
     * @param array<string, string>|null $kits Kit::all()
     * @return list<array<string, mixed>>
     */
    public static function kitPlan(?array $kits = null, ?string $root = null, ?string $core = null): array
    {
        $kits = $kits ?? Kit::all();
        $root = $root ?? self::root();
        $core = $core ?? self::core();
        if ($root === $core) return [];
        $out = [];
        foreach ($kits as $n => $dir) {
            $n = (string) $n;
            if (!preg_match(self::NAME, $n) || !is_dir("$core/kits/$n") || self::real("$core/kits/$n") !== self::real((string) $dir)) continue;
            if (is_dir("$core/public/assets/kits/$n")) $out[] = self::entry('kits', $n, "$core/public/assets/kits/$n", "$root/public/assets/kits", self::LINK, [], 'kits:publish');
        }
        return $out;
    }

    /** @param list<string> $keep @return array<string, mixed> */
    private static function entry(string $area, string $name, string $src, string $base, string $mode, array $keep, string $cmd): array
    {
        return ['area' => $area, 'name' => $name, 'src' => $src, 'base' => $base, 'dst' => "$base/$name", 'mode' => $mode, 'keep' => $keep, 'cmd' => $cmd];
    }

    // ------------------------------------------------------------------ Stand

    /**
     * Stand eines Eintrags: ok (verknüpft bzw. gleiche Dateien), missing (nichts veröffentlicht), stale (abweichende Dateien).
     * @param array<string, mixed> $e
     * @return array{state: string, changed: int, removed: int}
     */
    public static function status(array $e): array
    {
        $src = (string) $e['src'];
        $dst = (string) $e['dst'];
        if (!is_dir($src)) return ['state' => 'ok', 'changed' => 0, 'removed' => 0];
        if (is_link($dst) && self::real($dst) === self::real($src)) return ['state' => 'ok', 'changed' => 0, 'removed' => 0];
        if (!is_dir($dst)) return ['state' => 'missing', 'changed' => 0, 'removed' => 0];
        $d = self::diff($src, $dst, (array) $e['keep']);
        $state = $d['copy'] || $d['delete'] ? 'stale' : 'ok';
        return ['state' => $state, 'changed' => count($d['copy']), 'removed' => count($d['delete'])];
    }

    /**
     * Unterschiede Quelle → Ziel (nur erlaubte Dateien der Quelle; im Ziel alles, was in der Quelle fehlt).
     * @param list<string> $keep oberste Ordner im Ziel, die bleiben
     * @return array{copy: list<string>, delete: list<string>}
     */
    public static function diff(string $src, string $dst, array $keep = []): array
    {
        $want = self::files($src, $keep, true);
        $have = is_dir($dst) && !is_link($dst) ? self::files($dst, $keep, false) : [];
        $copy = [];
        foreach ($want as $rel => $path) {
            $t = $have[$rel] ?? null;
            if ($t === null || is_link($t) || filesize($t) !== filesize($path) || sha1_file($t) !== sha1_file($path)) $copy[] = $rel;
        }
        $delete = array_values(array_diff(array_keys($have), array_keys($want)));
        return ['copy' => $copy, 'delete' => $delete];
    }

    /**
     * Dateien eines Ordners [relativ => absolut], ohne Verknüpfungen zu verfolgen; $source: nur veröffentlichbare Dateien.
     * @param list<string> $keep
     * @return array<string, string>
     */
    private static function files(string $dir, array $keep, bool $source): array
    {
        $out = [];
        $dir = rtrim($dir, '/');
        $it = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            function (\SplFileInfo $f) use ($dir, $keep): bool {
                // oberste Ordner der Installation (fonts/installed) auslassen; Verknüpfte Ordner nie betreten
                if ($f->isDir() && !$f->isLink()) return !in_array(substr($f->getPathname(), strlen($dir) + 1), $keep, true);
                return true;
            }), \RecursiveIteratorIterator::LEAVES_ONLY, \RecursiveIteratorIterator::CATCH_GET_CHILD);
        foreach ($it as $f) {
            /** @var \SplFileInfo $f */
            $rel = substr($f->getPathname(), strlen($dir) + 1);
            if ($f->isDir() && !$f->isLink()) continue;
            if ($source && !self::publishable($rel, $f)) continue;
            $out[$rel] = $f->getPathname();
        }
        ksort($out);
        return $out;
    }

    /** Darf diese Datei öffentlich liegen? Keine Verknüpfung, kein versteckter Pfadteil, kein Server-Code */
    private static function publishable(string $rel, \SplFileInfo $f): bool
    {
        if ($f->isLink() || !$f->isFile()) return false;
        foreach (explode('/', $rel) as $part) if ($part === '' || $part[0] === '.') return false;
        return !preg_match(self::DENY, $rel) && strtolower(basename($rel)) !== 'web.config';
    }

    // ------------------------------------------------------------------ Veröffentlichen

    /**
     * Eintrag veröffentlichen: verknüpfen (LINK, wenn nicht $copy) bzw. abgleichen (COPY). Meldung oder Exception bei
     * unsicherem Ziel/Quelle.
     * @param array<string, mixed> $e
     * @return array{changed: bool, message: string}
     */
    public static function apply(array $e, bool $copy = false, ?string $root = null, ?string $core = null): array
    {
        $root = $root ?? self::root();
        $core = $core ?? self::core();
        [$src, $dst] = self::guard($e, $root, $core);
        if ((string) $e['mode'] === self::LINK && !$copy) {
            if (is_link($dst) && self::real($dst) === $src) return ['changed' => false, 'message' => __('verknüpft, aktuell')];
            $old = file_exists($dst) || is_link($dst);
            self::remove($dst);
            if (@symlink($src, $dst)) return ['changed' => true, 'message' => $old ? __('alte Kopie durch Verknüpfung auf den Kern ersetzt') : __('mit dem Kern verknüpft')];
            // Verknüpfung nicht möglich (Rechte, Dateisystem): Kopie
        }
        if (is_link($dst)) {
            // Verknüpfung auf die Quelle bleibt – außer --copy verlangt bei einem verknüpfbaren Ordner ausdrücklich eine Kopie
            if (self::real($dst) === $src && !($copy && (string) $e['mode'] === self::LINK)) return ['changed' => false, 'message' => __('verknüpft, aktuell')];
            @unlink($dst);   // Verknüpfung woandershin: nur die Verknüpfung entfernen, nie ihr Ziel
        }
        if (!is_dir($dst) && !@mkdir($dst, 0775, true)) throw new \RuntimeException(__('Ordner lässt sich nicht anlegen: {dir}', ['dir' => $dst]));
        $keep = (array) $e['keep'];
        $d = self::diff($src, $dst, $keep);
        $n = 0;
        foreach ($d['copy'] as $rel) {
            $t = "$dst/$rel";
            self::inside($t, $dst);
            if (is_link($t) || is_dir($t)) self::remove($t);
            if (!is_dir(dirname($t))) @mkdir(dirname($t), 0775, true);
            if (@copy("$src/$rel", $t)) $n++;
        }
        foreach ($d['delete'] as $rel) {
            self::inside("$dst/$rel", $dst);
            @unlink("$dst/$rel");
        }
        self::pruneEmpty($dst, $keep);
        if (!$n && !$d['delete']) return ['changed' => false, 'message' => __('Kopie aktuell')];
        return ['changed' => true, 'message' => __('Kopie abgeglichen: {n} Datei(en) aktualisiert, {m} entfernt', ['n' => $n, 'm' => count($d['delete'])])];
    }

    /**
     * Plan veröffentlichen. @param list<array<string, mixed>> $plan
     * @return array<string, string> [public/assets/… => Meldung]
     */
    public static function run(array $plan, bool $copy = false): array
    {
        $out = [];
        foreach ($plan as $e) {
            $label = self::label($e);
            try {
                $out[$label] = self::apply($e, $copy)['message'];
            } catch (\Throwable $ex) {
                $out[$label] = __('übersprungen: {reason}', ['reason' => $ex->getMessage()]);
            }
        }
        return $out;
    }

    /** Pfad für Meldungen, z. B. public/assets/ext/consent_kit @param array<string, mixed> $e */
    public static function label(array $e): string
    {
        $dst = (string) $e['dst'];
        $root = self::root();
        return str_starts_with($dst, $root . '/') ? substr($dst, strlen($root) + 1) : $dst;
    }

    /**
     * Sicherheitsprüfung: Ziel = {base}/{name} mit base unter {root}/public/assets; Verknüpfungsziel unter {core}/public/assets.
     * @param array<string, mixed> $e
     * @return array{0: string, 1: string} [Quelle real, Ziel]
     */
    private static function guard(array $e, string $root, string $core): array
    {
        $name = (string) ($e['name'] ?? '');
        if (!preg_match(self::NAME, $name)) throw new \RuntimeException(__('ungültiger Name: {name}', ['name' => $name]));
        $assets = self::real("$root/public/assets");
        $given = (string) ($e['base'] ?? '');
        $base = self::real($given);
        // public/, public/assets und der Bereichsordner selbst dürfen keine Verknüpfung sein (sonst schriebe es in den Kern)
        foreach (["$root/public", "$root/public/assets", $given] as $p) if ($p === '' || is_link($p)) throw new \RuntimeException(__('Ziel liegt nicht unter public/assets'));
        if ($assets === '' || $base === '' || !in_array($base, [$assets, "$assets/ext", "$assets/kits"], true)) {
            throw new \RuntimeException(__('Ziel liegt nicht unter public/assets'));
        }
        if ((string) ($e['dst'] ?? '') !== $e['base'] . '/' . $name) throw new \RuntimeException(__('Ziel liegt nicht unter public/assets'));
        $src = self::real((string) ($e['src'] ?? ''));
        if ($src === '' || !is_dir($src)) throw new \RuntimeException(__('Quelle fehlt'));
        if ((string) $e['mode'] === self::LINK && !str_starts_with($src, self::real("$core/public/assets") . '/')) {
            throw new \RuntimeException(__('Verknüpfung nur auf Ordner des Kerns (public/assets)'));
        }
        if (!is_link("$given/$name") && $src === self::real("$given/$name")) throw new \RuntimeException(__('Quelle und Ziel sind derselbe Ordner'));
        return [$src, "$given/$name"];
    }

    /** Pfad liegt (ohne Verknüpfungen aufzulösen) unter $dir? Sonst Abbruch */
    private static function inside(string $path, string $dir): void
    {
        if (str_contains($path, '/../') || !str_starts_with($path, rtrim($dir, '/') . '/')) throw new \RuntimeException(__('Ziel liegt nicht unter public/assets'));
        // kein Pfadteil zwischen $dir und der Datei darf eine Verknüpfung sein (sonst schriebe copy/unlink woandershin)
        for ($p = dirname($path); strlen($p) > strlen(rtrim($dir, '/')); $p = dirname($p)) {
            if (is_link($p)) throw new \RuntimeException(__('Ziel liegt nicht unter public/assets'));
        }
    }

    /** Datei, Verknüpfung oder Ordner entfernen – Verknüpfungen werden nie verfolgt */
    private static function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) { @unlink($path); return; }
        if (!is_dir($path)) return;
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            /** @var \SplFileInfo $f */
            ($f->isDir() && !$f->isLink()) ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($path);
    }

    /** Leere Unterordner entfernen (außer KEEP) @param list<string> $keep */
    private static function pruneEmpty(string $dir, array $keep): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            /** @var \SplFileInfo $f */
            if (!$f->isDir() || $f->isLink()) continue;
            $rel = substr($f->getPathname(), strlen($dir) + 1);
            foreach ($keep as $k) if ($rel === $k || str_starts_with($rel, "$k/")) continue 2;
            if (count(scandir($f->getPathname()) ?: []) === 2) @rmdir($f->getPathname());
        }
    }

    private static function real(string $path): string
    {
        return $path === '' ? '' : rtrim((string) (realpath($path) ?: $path), '/');
    }

    // ------------------------------------------------------------------ Prüfung

    /**
     * Für health (technisch): veraltete bzw. fehlende Asset-Kopien (Prüfsummen Quelle ↔ public/).
     * @return array<string, bool>
     */
    public static function health(): array
    {
        $out = [];
        $plan = array_merge(self::corePlan(), self::extensionPlan(), self::kitPlan());
        $bad = 0;
        foreach ($plan as $e) {
            if (!empty($e['once'])) continue;
            $s = self::status($e);
            if ($s['state'] === 'ok') continue;
            $bad++;
            $cmd = 'php bin/console ' . $e['cmd'];
            $label = $s['state'] === 'missing'
                ? __('Assets fehlen: {path} – {cmd}', ['path' => self::label($e), 'cmd' => $cmd])
                : __('Veraltete Asset-Kopie: {path} ({n} Datei(en) abweichend) – {cmd}', ['path' => self::label($e), 'n' => $s['changed'] + $s['removed'], 'cmd' => $cmd]);
            $out[$label] = false;
        }
        $checked = count(array_filter($plan, fn($e) => empty($e['once'])));
        if ($checked && !$bad) $out[__('Veröffentlichte Assets aktuell ({n} Ordner)', ['n' => $checked])] = true;
        return $out;
    }
}
