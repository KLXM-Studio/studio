<?php
declare(strict_types=1);

namespace Core;

/**
 * Schriftgröße der Verwaltung je Konto (Konto → Darstellung, Schieber im Konto-Menü der Kopfleiste).
 *
 * Gespeichert in users.ui_prefs (JSON, Schlüssel „fs“, wie Core\Accent). Ausgabe als data-fs="125" an
 * <html class="adm-ui"> (layout.php) und an den Schatten-Hosts auf der Website (Werkzeugleiste Core\Theme::toolbarHost,
 * Ebene und Block-Leisten _shadow.js, Seitenleiste „Eintrag bearbeiten“ _entry_edit.js). Standard 100 % = kein Attribut.
 *
 * Technik: Die Verwaltungs-CSS rechnet beim Bauen in rem (tools/adm-scale.mjs, Liste ADM_REM in tools/build.mjs), die Wurzel
 * setzt die Schriftgröße (resources/css/_uiscale.css); Schatten-CSS rechnet mit calc(…px*var(--adm-s,1)). So wachsen Text,
 * Abstände und Bedienelemente gemeinsam, ohne CSS-zoom: Koordinaten für Popover, Ziehen und Zeichenflächen bleiben echte
 * Pixel, die Vorschau der Website (iframe) bleibt unverändert, Browser-Zoom wirkt weiter zusätzlich.
 */
final class UiScale
{
    public const DEFAULT = 100;
    /** Stufen in Prozent – müssen zu resources/css/_uiscale.css und editor.shadow.css passen */
    public const STEPS = [90, 100, 112, 125, 140];

    /** Gültige Stufe (unbekannte Werte → nächstliegende Stufe) */
    public static function normalize(mixed $v): int
    {
        $n = is_numeric($v) ? (int) round((float) $v) : self::DEFAULT;
        $best = self::DEFAULT;
        foreach (self::STEPS as $s) if (abs($s - $n) < abs($best - $n)) $best = $s;
        return $best;
    }

    /** Gespeicherte Stufe eines Kontos */
    public static function get(?array $user = null): int
    {
        $user ??= isset(app()->auth) ? app()->auth->user() : null;
        $p = json_decode((string) ($user['ui_prefs'] ?? ''), true);
        return is_array($p) && isset($p['fs']) ? self::normalize($p['fs']) : self::DEFAULT;
    }

    /** Attribut für <html class="adm-ui"> bzw. Schatten-Hosts: ' data-fs="125"' (Standard: leer) */
    public static function attrs(?array $user = null): string
    {
        $fs = self::get($user);
        return $fs === self::DEFAULT ? '' : ' data-fs="' . $fs . '"';
    }

    /** Wert für JavaScript (Schatten-Hosts): '125' oder '' (Standard) */
    public static function client(?array $user = null): string
    {
        $fs = self::get($user);
        return $fs === self::DEFAULT ? '' : (string) $fs;
    }

    /** Stufe speichern (Standard entfernt den Schlüssel) – liefert die gespeicherte Stufe */
    public static function save(int $userId, mixed $fs): int
    {
        $fs = self::normalize($fs);
        $row = app()->db->fetch('SELECT ui_prefs FROM users WHERE id = ?', [$userId]);
        $p = json_decode((string) ($row['ui_prefs'] ?? ''), true);
        $p = is_array($p) ? $p : [];
        if ($fs === self::DEFAULT) unset($p['fs']); else $p['fs'] = $fs;
        app()->db->update('users', ['ui_prefs' => json_encode($p)], 'id = :id', ['id' => $userId]);
        return $fs;
    }

    /** Index der Stufe für den Schieber (input type=range, 0 … n-1) */
    public static function index(int $fs): int
    {
        $i = array_search($fs, self::STEPS, true);
        return $i === false ? (int) array_search(self::DEFAULT, self::STEPS, true) : (int) $i;
    }

    /**
     * Selbsttest (CLI uiscale:selftest): Stufen, Attribute, gebaute CSS (Wurzel-Regeln vorhanden, Verwaltung ohne px-Maße).
     * @return list<array{0: string, 1: bool}>
     */
    public static function selfTest(): array
    {
        $out = [];
        $out[] = ['normalize: 125 → 125', self::normalize(125) === 125];
        $out[] = ['normalize: 130 → 125 (nächste Stufe)', self::normalize(130) === 125];
        $out[] = ['normalize: Unsinn → 100', self::normalize('x') === 100];
        $out[] = ['normalize: 999 → 140', self::normalize(999) === 140];
        $out[] = ['attrs: Standard leer', self::attrs(['ui_prefs' => '{"accent":"petrol"}']) === ''];
        $out[] = ['attrs: 125', self::attrs(['ui_prefs' => '{"fs":125}']) === ' data-fs="125"'];
        $out[] = ['attrs: kaputtes JSON → leer', self::attrs(['ui_prefs' => '{']) === ''];
        $out[] = ['index: 100 → 1', self::index(100) === 1];
        $pub = ROOT . '/public/assets/css/';
        $admin = (string) @file_get_contents($pub . 'admin.css');
        $shadow = (string) @file_get_contents($pub . 'admin.shadow.css');
        $ed = (string) @file_get_contents($pub . 'editor.shadow.css');
        foreach (self::STEPS as $s) {
            if ($s === self::DEFAULT) continue;
            $out[] = ["admin.css: Regel data-fs=$s", str_contains($admin, 'html.adm-ui[data-fs="' . $s . '"]')];
            $out[] = ["admin.shadow.css: Regel data-fs=$s", str_contains($shadow, ':host(.adm-ui[data-fs="' . $s . '"])')];
            $out[] = ["editor.shadow.css: Regel data-fs=$s", str_contains($ed, ':host([data-fs="' . $s . '"])')];
        }
        // Verwaltung: außer Haarlinien und Bedingungen von @media keine px-Maße mehr (gebaut mit tools/adm-scale.mjs)
        $noMedia = (string) preg_replace('/@(media|container|supports)[^{]*\{/', '', $admin);
        $big = preg_match_all('/(?<![\w.-])-?(?:\d*\.)?\d+px\b/', (string) preg_replace('/(?<![\w.-])-?(?:0?\.\d+|1|0)px\b/', '', $noMedia));
        $out[] = ['admin.css: Maße in rem (gebaut)', $admin !== '' && $big === 0];
        $out[] = ['admin.shadow.css: Maße skaliert (gebaut)', str_contains($shadow, '*var(--adm-s,1))')];
        return $out;
    }
}
