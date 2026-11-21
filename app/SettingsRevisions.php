<?php
declare(strict_types=1);

namespace Core;

/**
 * Versionen der Einstellungen (Tabelle settings_revisions, je Website): jede Änderung über die Verwaltung, REST-API, MCP oder KI
 * mit Zeit, Person, Kanal und den geänderten Schlüsseln vorher/nachher – Geheimnisse (Passwörter, Schlüssel) nie.
 * „Zurücknehmen“ stellt die Werte vor der Änderung wieder her, solange niemand die Felder inzwischen erneut geändert hat
 * (sonst Hinweis, nichts wird überschrieben); die Rücknahme ist selbst eine Version (Kanal „undo“).
 *
 *   area   'settings' (Website-Einstellungen des Kits, auch Übersetzungen „feld@en“) | 'system' (Grundeinstellungen)
 *   SettingsRevisions::record('settings', $vorher, $nachher, 'admin');   // nur geänderte Schlüssel werden gespeichert
 *   SettingsRevisions::latest('settings', 8);  SettingsRevisions::undo($id, $erlaubteSchluessel);
 */
final class SettingsRevisions
{
    public const KEEP = 200;   // je Bereich höchstens so viele Versionen
    public const CHANNELS = ['admin', 'api', 'mcp', 'ai', 'undo'];

    /** Geänderte Schlüssel speichern. @return int|null ID der Version (null = nichts geändert oder Fehler) */
    public static function record(string $area, array $before, array $after, string $channel = 'admin', string $lang = ''): ?int
    {
        $changes = [];
        foreach ($after as $k => $v) {
            $k = (string) $k;
            if (self::secret($k)) continue;
            $old = $before[$k] ?? null;
            if (self::same($old, $v)) continue;
            $changes[] = ['k' => $k . ($lang !== '' ? '@' . $lang : ''), 'b' => $old, 'a' => $v];
        }
        if (!$changes) return null;
        try {
            $u = app()->auth->user();
            $db = app()->db;
            $id = $db->insert('settings_revisions', ['created_at' => date('Y-m-d H:i:s'), 'user_id' => $u['id'] ?? null,
                'user_name' => $u ? (string) (($u['name'] ?? '') ?: $u['email']) : null, 'channel' => in_array($channel, self::CHANNELS, true) ? $channel : 'api',
                'area' => $area, 'changes_json' => json_encode($changes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
            // Ältere Versionen über der Grenze entfernen (je Bereich)
            $cut = $db->fetchValue('SELECT id FROM settings_revisions WHERE area = ? ORDER BY id DESC LIMIT 1 OFFSET ' . self::KEEP, [$area]);
            if ($cut) $db->query('DELETE FROM settings_revisions WHERE area = ? AND id <= ?', [$area, (int) $cut]);
            return $id;
        } catch (\Throwable $e) {
            error_log('[settings-revisions] ' . $e->getMessage());
            return null;
        }
    }

    /** Neueste Versionen eines Bereichs @return list<array{id:int, created_at:string, user_name:?string, channel:string, changes:list<array{k:string,b:mixed,a:mixed}>, undone:bool}> */
    public static function latest(string $area, int $n = 8): array
    {
        try {
            $rows = app()->db->fetchAll('SELECT * FROM settings_revisions WHERE area = ? ORDER BY id DESC LIMIT ' . max(1, min(100, $n)), [$area]);
        } catch (\Throwable) {
            return [];
        }
        return array_map(fn($r) => ['id' => (int) $r['id'], 'created_at' => (string) $r['created_at'], 'user_name' => $r['user_name'],
            'channel' => (string) $r['channel'], 'changes' => json_decode((string) $r['changes_json'], true) ?: [], 'undone' => (int) ($r['undone_by'] ?? 0) > 0], $rows);
    }

    public static function find(int $id): ?array
    {
        $r = app()->db->fetch('SELECT * FROM settings_revisions WHERE id = ?', [$id]);
        return $r ? ['id' => (int) $r['id'], 'area' => (string) $r['area'], 'changes' => json_decode((string) $r['changes_json'], true) ?: [],
            'undone' => (int) ($r['undone_by'] ?? 0) > 0] : null;
    }

    /**
     * Version zurücknehmen: alle Schlüssel auf den Wert davor – nur, wenn sie noch den Wert danach haben und $allowed sie enthält
     * (Rechte der Person). @return string|null Fehlermeldung oder null bei Erfolg
     */
    public static function undo(int $id, array $allowed, string $area): ?string
    {
        $rev = self::find($id);
        if (!$rev || $rev['area'] !== $area) return __('Diese Änderung gibt es nicht mehr.');
        if ($rev['undone']) return __('Diese Änderung wurde bereits zurückgenommen.');
        $s = app()->settings;
        $before = $after = [];
        foreach ($rev['changes'] as $c) {
            $k = (string) $c['k'];
            $base = explode('@', $k)[0];
            if (!in_array($base, $allowed, true)) return __('Sie dürfen nicht alle Felder dieser Änderung bearbeiten.');
            if (!self::same($s->get($k), $c['a'])) return __('„{key}“ wurde inzwischen erneut geändert – bitte von Hand anpassen.', ['key' => $base]);
            $before[$k] = $c['a'];
            $after[$k] = $c['b'];
        }
        app()->db->transaction(function () use ($after, $s) {
            foreach ($after as $k => $v) {
                $v === null ? $s->delete($k) : $s->set($k, $v);
            }
        });
        $new = self::record($area, $before, $after, 'undo');
        app()->db->query('UPDATE settings_revisions SET undone_by = ? WHERE id = ?', [$new ?? -1, $id]);
        return null;
    }

    /** Kurze Anzeige eines Werts (Liste, Seite, Bild, Text) */
    public static function display(mixed $v, array $f = []): string
    {
        if ($v === null || $v === '' || $v === []) return __('leer');
        if (is_bool($v)) return $v ? __('an') : __('aus');
        $type = $f['type'] ?? '';
        if ($type === 'bool') return $v ? __('an') : __('aus');
        if ($type === 'page' && is_numeric($v)) return (string) (Pages::find((int) $v)['title'] ?? ('#' . $v));
        if ($type === 'media' && is_numeric($v)) return __('Datei #{id}', ['id' => (string) $v]);
        if ($type === 'select' && isset($f['options'][(string) $v]) && is_scalar($f['options'][(string) $v])) return (string) $f['options'][(string) $v];
        if (is_array($v)) return __('{n} Einträge', ['n' => count($v)]);
        return mb_strimwidth(trim(strip_tags((string) $v)), 0, 60, '…');
    }

    private static function same(mixed $a, mixed $b): bool
    {
        $norm = fn($v) => $v === null || $v === '' || $v === [] ? null : json_encode($v, JSON_UNESCAPED_UNICODE);
        return $norm($a) === $norm($b);
    }

    /** Geheimnisse nie in Versionen (verschlüsselte Einstellungen, Passwörter, Schlüssel) */
    private static function secret(string $k): bool
    {
        return in_array($k, Settings::SECRETS, true) || (bool) preg_match('~(pass|secret|token|_key)$~', $k);
    }
}
