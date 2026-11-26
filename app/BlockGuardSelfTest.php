<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Copyright (C) 2026 KLXM and contributors (see LICENSE, COPYRIGHT)
declare(strict_types=1);

namespace Core;

use Core\Api\ApiError;
use Core\Api\CmsService;

/**
 * Selbsttest der Rollenrechte an Blöcken (Core\BlockGuard) – Teil von roles:selftest:
 *  - jeder Schreibweg mit verbotenem Block wird abgelehnt (Editor/Website = Pages::saveDraft mit angemeldeter Person, REST/MCP = CmsService
 *    mit Token, Token mit den Rechten seines Kontos, Seite mit Blöcken anlegen = Import über API/MCP, Inhaltsblöcke eines Eintrags)
 *  - gesperrter Block: nicht änderbar, nicht löschbar, verschiebbar; Sperre setzen nur mit voller Gestaltung
 *  - Gestaltungsstufen none/basic/full
 *  - Migration: Rollen mit pages.edit bekommen design.blocks.basic; alle vorhandenen Rollen der Website behalten, was sie dürfen
 *    (jede Seite: Inhalt + Abschnitts-Optionen ändern, Block löschen, neuen Block einfügen – wie vorher erlaubt)
 * Alles in einer Transaktion (zurückgerollt); die Testtabelle für Inhaltsblöcke wird danach gelöscht.
 */
final class BlockGuardSelfTest
{
    public static function run(): array
    {
        $ok = 0;
        $fails = [];
        $eq = Testing\Check::eq($ok, $fails, 'Blöcke – ');
        // „nur bearbeiten“ (roles.blocks_edit_json): bestehende ändern ja, neue einfügen nein
        $ro = ['key' => 'qa', 'permissions' => ['pages.edit'], 'blocks' => ['qa_a'], 'blocks_edit' => ['qa_b']];
        $eq('nur bearbeiten: Block ändern erlaubt', BlockGuard::protection($ro, ['type' => 'qa_b']), null);
        $eq('nur bearbeiten: andere Art gesperrt', BlockGuard::protection($ro, ['type' => 'qa_c']), 'type');
        // Mehrere Rollen je Konto (Permissions::effective): Rechte addieren sich, Hauptrolle bleibt Schlüssel
        $ar = Permissions::role('author'); $er = Permissions::role('editor');
        if ($ar && $er) {
            $eff = Permissions::effective(['role' => 'author', 'roles_extra' => json_encode(['editor', 'network', 'gibtsnicht'])]);
            $eq('Zusatzrolle: Rechte vereinigt', array_values(array_diff($er['permissions'], (array) ($eff['permissions'] ?? []))), []);
            $eq('Zusatzrolle: Schlüssel der Hauptrolle', $eff['key'] ?? null, 'author');
            $eq('Zusatzrolle: ohne Netzwerk/Unbekanntes', $eff['extra'] ?? null, ['editor']);
            $eq('Ohne Zusatzrolle unverändert', Permissions::effective(['role' => 'author'])['permissions'] ?? null, $ar['permissions']);
        }
        $theme = app()->theme;
        // Zwei einfache, erlaubte Blockarten mit Textfeld: A (erlaubt) und B (für die eingeschränkte Rolle verboten)
        $pick = [];
        // bevorzugt Arten, die auch in Spalten und in Inhaltsblöcken erlaubt sind (dann laufen diese Prüfungen mit)
        $all = $theme->blocks();
        $inEntry = Data\EntryBlocks::choices();
        $rank = fn($t) => [!$theme->nestable((string) $t), !isset($inEntry[(string) $t]), (string) $t];
        uksort($all, fn($x, $y) => $rank($x) <=> $rank($y));
        foreach ($all as $type => $b) {
            $type = (string) $type;
            if ($type === Layout::TYPE || !Features::allowsBlock($type) || !empty($b['central']) || !empty($b['sanitize']) || str_starts_with($type, 'cblk_')) continue;
            foreach ((array) ($b['fields'] ?? []) as $f) {
                if (in_array($f['type'] ?? '', ['text', 'richtext', 'textarea'], true) && empty($f['required'])) { $pick[$type] = (string) $f['name']; break; }
            }
            if (count($pick) >= 2) break;
        }
        if (count($pick) < 2) return ['ok' => $ok, 'fails' => ['Blöcke – Kit hat keine zwei einfachen Blockarten mit Textfeld']];
        [$A, $B] = array_keys($pick);
        $fa = $pick[$A]; $fb = $pick[$B];
        $mk = fn(string $id, string $type, string $field, string $text, array $extra = []) => array_replace(['id' => $id, 'type' => $type, 'data' => [$field => $text], 'tunes' => ['section' => []]], $extra);
        $base = Pages::sanitizeBlocks([
            $mk('qa_a1', $A, $fa, 'Erster Text'),
            $mk('qa_b1', $B, $fb, 'Fremder Block'),
            $mk('qa_l1', $A, $fa, 'Von der Agentur', ['lock' => ['note' => 'Agentur-Abschnitt']]),
        ]);
        $role = fn(array $perms, ?array $blocks = null, string $key = 'qa') => ['key' => $key, 'name' => 'QA', 'description' => '', 'permissions' => $perms, 'tables' => null, 'blocks' => $blocks, 'builtin' => false];
        $limited = $role(['pages.edit', 'pages.publish'], [$A], 'qa_limited');          // „Redaktion eingeschränkt“: nur A, keine Gestaltung
        $basic = $role(['pages.edit', 'design.blocks.basic'], null, 'qa_basic');
        $full = $role(['pages.edit', 'design.blocks'], [$A], 'qa_full');
        $admin = $role(['*'], null, 'admin');
        $denied = function (callable $fn): bool {
            try { $fn(); return false; } catch (BlockDenied) { return true; }
        };
        $check = fn(array $new, ?array $r) => BlockGuard::check($base, Pages::sanitizeBlocks($new), $r);
        $with = function (string $id, callable $fn) use ($base): array {
            return array_map(fn($b) => $b['id'] === $id ? $fn($b) : $b, $base);
        };
        $bgs = array_keys($theme->backgrounds());
        $otherBg = fn(array $b) => (string) (array_values(array_diff($bgs, [$b['tunes']['section']['background'] ?? '']))[0] ?? '');

        // ---------------------------------------------------------- Stufen der Gestaltung
        $eq('Stufe ohne Gestaltung', BlockGuard::level($limited), 'none');
        $eq('Stufe im Rahmen des Kits', BlockGuard::level($basic), 'basic');
        $eq('Stufe voll (schließt basic ein)', [BlockGuard::level($full), Permissions::allows($full, 'design.blocks.basic')], ['full', true]);
        $eq('Alle Rechte = voll', BlockGuard::level($admin), 'full');
        $eq('Rechte im Katalog', array_values(array_intersect(['design.blocks.basic', 'design.blocks'], Permissions::all())), ['design.blocks.basic', 'design.blocks']);

        // ---------------------------------------------------------- Erlaubte Blöcke
        $eq('Erlaubter Block einfügen', $denied(fn() => $check([...$base, $mk('qa_n1', $A, $fa, 'Neu')], $limited)), false);
        $eq('Verbotener Block einfügen → abgelehnt', $denied(fn() => $check([...$base, $mk('qa_n2', $B, $fb, 'Neu')], $limited)), true);
        $eq('Verbotener Block unter alter ID eines erlaubten → abgelehnt', $denied(fn() => $check($with('qa_a1', fn($b) => $mk('qa_a1', $B, $fb, 'Tausch')), $limited)), true);
        $eq('Fremder Block ändern → abgelehnt', $denied(fn() => $check($with('qa_b1', fn($b) => array_replace_recursive($b, ['data' => [$fb => 'Geändert']])), $limited)), true);
        $eq('Fremder Block löschen → abgelehnt', $denied(fn() => $check(array_values(array_filter($base, fn($b) => $b['id'] !== 'qa_b1')), $limited)), true);
        $eq('Fremder Block verschieben → erlaubt', $denied(fn() => $check(array_reverse($base), $limited)), false);
        $eq('Volle Gestaltung ändert fremde Blöcke', $denied(fn() => $check($with('qa_b1', fn($b) => array_replace_recursive($b, ['data' => [$fb => 'Geändert']])), $full)), false);
        $eq('Volle Gestaltung fügt verbotene nicht ein', $denied(fn() => $check([...$base, $mk('qa_n3', $B, $fb, 'Neu')], $full)), true);
        $eq('Alle Rechte: alles', $denied(fn() => $check([$mk('qa_n4', $B, $fb, 'Neu')], $admin)), false);
        $eq('Ohne Auswahl: alle im Rahmen der Website', $denied(fn() => $check([...$base, $mk('qa_n5', $B, $fb, 'Neu')], $basic)), false);

        // ---------------------------------------------------------- Gesperrter Block
        $eq('Sperre bleibt beim Bereinigen erhalten', $base[2]['lock'] ?? null, ['by' => 'design', 'note' => 'Agentur-Abschnitt']);
        $eq('Gesperrt ändern → abgelehnt', $denied(fn() => $check($with('qa_l1', fn($b) => array_replace_recursive($b, ['data' => [$fa => 'Anders']])), $basic)), true);
        $eq('Gesperrt löschen → abgelehnt', $denied(fn() => $check(array_values(array_filter($base, fn($b) => $b['id'] !== 'qa_l1')), $basic)), true);
        $eq('Gesperrt verschieben → erlaubt', $denied(fn() => $check([$base[2], $base[0], $base[1]], $limited)), false);
        $noLock = $with('qa_l1', function ($b) { unset($b['lock']); return $b; });
        $eq('Sperre entfernen ohne volle Gestaltung: bleibt bestehen', (BlockGuard::check($base, $noLock, $basic)[2]['lock'] ?? null) !== null, true);
        $setLock = $with('qa_a1', fn($b) => $b + ['lock' => ['by' => 'design', 'note' => '']]);
        $eq('Sperre setzen ohne volle Gestaltung: entfällt still', isset(BlockGuard::check($base, $setLock, $basic)[0]['lock']), false);
        $eq('Sperre setzen mit voller Gestaltung', isset(BlockGuard::check($base, $setLock, $full)[0]['lock']), true);
        $eq('Gesperrt ändern mit voller Gestaltung', $denied(fn() => $check($with('qa_l1', fn($b) => array_replace_recursive($b, ['data' => [$fa => 'Anders']])), $full)), false);
        $eq('Gesperrt löschen mit voller Gestaltung', $denied(fn() => $check(array_slice($base, 0, 2), $full)), false);

        // ---------------------------------------------------------- Gestaltung (Abschnitts-Optionen, Variante)
        if (count($bgs) > 1) {
            $bg = $with('qa_a1', fn($b) => array_replace_recursive($b, ['tunes' => ['section' => ['background' => $otherBg($b)]]]));
            $eq('Ohne Gestaltung: Hintergrund ändern → abgelehnt', $denied(fn() => $check($bg, $limited)), true);
            $eq('Im Rahmen des Kits: Hintergrund ändern', $denied(fn() => $check($bg, $basic)), false);
            $eq('Ohne Gestaltung: neuer Block mit Hintergrund → abgelehnt', $denied(fn() => $check([...$base, $mk('qa_n6', $A, $fa, 'x', ['tunes' => ['section' => ['background' => $otherBg(['tunes' => ['section' => ['background' => $theme->sanitizeTunes([], $theme->block($A) ?? [])['background']]]])]]])], $limited)), true);
        }
        $eq('Ohne Gestaltung: Inhalt und Sprungmarke ändern', $denied(fn() => $check($with('qa_a1', fn($b) => array_replace_recursive($b, ['data' => [$fa => 'Neu'], 'tunes' => ['section' => ['anchor' => 'probe']]])), $limited)), false);
        $variantType = null;
        foreach ($theme->blocks() as $t => $b) if (count((array) ($b['variants'] ?? [])) > 1 && Features::allowsBlock((string) $t) && empty($b['sanitize'])) { $variantType = (string) $t; break; }
        if ($variantType) {
            $vs = array_keys((array) $theme->block($variantType)['variants']);
            $vb = Pages::sanitizeBlocks([['id' => 'qa_v1', 'type' => $variantType, 'data' => ['variant' => $vs[0]], 'tunes' => ['section' => []]]]);
            $vb2 = Pages::sanitizeBlocks([['id' => 'qa_v1', 'type' => $variantType, 'data' => array_replace($vb[0]['data'], ['variant' => $vs[1]]), 'tunes' => $vb[0]['tunes']]]);
            $vr = $role(['pages.edit'], null, 'qa_v');
            $eq('Ohne Gestaltung: Variante ändern → abgelehnt', $denied(fn() => BlockGuard::check($vb, $vb2, $vr)), true);
            $eq('Im Rahmen des Kits: Variante ändern', $denied(fn() => BlockGuard::check($vb, $vb2, $basic)), false);
        }

        // ---------------------------------------------------------- Blöcke in Spalten (Layout)
        if ($theme->block(Layout::TYPE) && $theme->nestable($A) && $theme->nestable($B)) {
            $preset = (string) (array_keys(Layout::PRESETS)[1] ?? array_key_first(Layout::PRESETS));
            $lay = fn(array $c1, array $c2) => Pages::sanitizeBlocks([['id' => 'qa_lay', 'type' => Layout::TYPE, 'data' => ['preset' => $preset, 'columns' => [['blocks' => $c1], ['blocks' => $c2]]], 'tunes' => ['section' => []]]]);
            $old = $lay([$mk('qa_c1', $B, $fb, 'Kind')], [$mk('qa_c2', $A, $fa, 'Kind A')]);
            if (count($old[0]['data']['columns'] ?? []) >= 2) {
                $eq('Spalte: fremdes Kind ändern → abgelehnt', $denied(fn() => BlockGuard::check($old, $lay([$mk('qa_c1', $B, $fb, 'Anders')], [$mk('qa_c2', $A, $fa, 'Kind A')]), $limited)), true);
                $eq('Spalte: fremdes Kind in andere Spalte verschieben', $denied(fn() => BlockGuard::check($old, $lay([], [$mk('qa_c2', $A, $fa, 'Kind A'), $mk('qa_c1', $B, $fb, 'Kind')]), $limited)), false);
                $eq('Spalte: fremdes Kind löschen → abgelehnt', $denied(fn() => BlockGuard::check($old, $lay([], [$mk('qa_c2', $A, $fa, 'Kind A')]), $limited)), true);
                // Gesperrtes Layout: Spalten mitgesperrt – nur volle Gestaltung fügt ein, ändert, löscht oder schiebt hinaus
                $locked = fn(array $c1, array $c2, array $after = []) => [...array_map(fn($b) => $b + ['lock' => ['by' => 'design', 'note' => 'Agentur-Raster']], $lay($c1, $c2)), ...Pages::sanitizeBlocks($after)];
                $lo = $locked([$mk('qa_k1', $A, $fa, 'Eins')], [$mk('qa_k2', $A, $fa, 'Zwei')], [$mk('qa_top', $A, $fa, 'Oben')]);
                $eq('Gesperrtes Layout: in Spalte einfügen → abgelehnt', $denied(fn() => BlockGuard::check($lo, $locked([$mk('qa_k1', $A, $fa, 'Eins'), $mk('qa_k3', $A, $fa, 'Neu')], [$mk('qa_k2', $A, $fa, 'Zwei')], [$mk('qa_top', $A, $fa, 'Oben')]), $basic)), true);
                $eq('Gesperrtes Layout: Kind ändern → abgelehnt', $denied(fn() => BlockGuard::check($lo, $locked([$mk('qa_k1', $A, $fa, 'Anders')], [$mk('qa_k2', $A, $fa, 'Zwei')], [$mk('qa_top', $A, $fa, 'Oben')]), $basic)), true);
                $eq('Gesperrtes Layout: Kind löschen → abgelehnt', $denied(fn() => BlockGuard::check($lo, $locked([], [$mk('qa_k2', $A, $fa, 'Zwei')], [$mk('qa_top', $A, $fa, 'Oben')]), $basic)), true);
                $eq('Gesperrtes Layout: Kind hinausschieben → abgelehnt', $denied(fn() => BlockGuard::check($lo, $locked([], [$mk('qa_k2', $A, $fa, 'Zwei')], [$mk('qa_top', $A, $fa, 'Oben'), $mk('qa_k1', $A, $fa, 'Eins')]), $basic)), true);
                $eq('Gesperrtes Layout: Block hineinschieben → abgelehnt', $denied(fn() => BlockGuard::check($lo, $locked([$mk('qa_k1', $A, $fa, 'Eins'), $mk('qa_top', $A, $fa, 'Oben')], [$mk('qa_k2', $A, $fa, 'Zwei')]), $basic)), true);
                $eq('Gesperrtes Layout: Kind innerhalb verschieben', $denied(fn() => BlockGuard::check($lo, $locked([$mk('qa_k2', $A, $fa, 'Zwei')], [$mk('qa_k1', $A, $fa, 'Eins')], [$mk('qa_top', $A, $fa, 'Oben')]), $basic)), false);
                $eq('Gesperrtes Layout: volle Gestaltung fügt ein', $denied(fn() => BlockGuard::check($lo, $locked([$mk('qa_k1', $A, $fa, 'Eins'), $mk('qa_k3', $A, $fa, 'Neu')], [$mk('qa_k2', $A, $fa, 'Zwei')], [$mk('qa_top', $A, $fa, 'Oben')]), $full)), false);
                $eq('Gesperrtes Layout: Admin fügt ein', $denied(fn() => BlockGuard::check($lo, $locked([$mk('qa_k1', $A, $fa, 'Eins'), $mk('qa_k3', $A, $fa, 'Neu')], [$mk('qa_k2', $A, $fa, 'Zwei')], [$mk('qa_top', $A, $fa, 'Oben')]), $admin)), false);
                $eq('Ungesperrtes Layout: einfügen erlaubt', $denied(fn() => BlockGuard::check($lay([$mk('qa_k1', $A, $fa, 'Eins')], []), $lay([$mk('qa_k1', $A, $fa, 'Eins'), $mk('qa_k3', $A, $fa, 'Neu')], []), $basic)), false);
            }
        }

        // ---------------------------------------------------------- Schreibwege (echte Konten, Seite, Token) – zurückgerollt
        $pdo = app()->db->pdo;
        $pdo->beginTransaction();
        $auth = app()->auth;
        try {
            $keys = [];
            foreach (['qa_limited' => $limited, 'qa_full' => $full] as $k => $r) {
                app()->db->insert('roles', ['rkey' => $k, 'name' => 'QA ' . $k, 'description' => '', 'builtin' => 0, 'tables_json' => null,
                    'permissions_json' => json_encode($r['permissions']), 'blocks_json' => json_encode($r['blocks'])]);
                $keys[$k] = (int) app()->db->insert('users', ['email' => $k . '-' . bin2hex(random_bytes(3)) . '@example.test', 'name' => 'QA', 'password_hash' => 'x', 'role' => $k, 'created_at' => now()]);
            }
            $eq('Rolle speichert erlaubte Blöcke', Permissions::role('qa_limited')['blocks'] ?? null, [$A]);
            $pid = Pages::create(['slug' => 'qa-blockguard-' . bin2hex(random_bytes(3)), 'title' => 'QA Blockrechte', 'sort' => 9999], $base);
            $actAs = function (?int $uid) use ($auth): void {
                $set = function (string $prop, mixed $v) use ($auth): void { $p = new \ReflectionProperty($auth, $prop); $p->setValue($auth, $v); };
                $set('loaded', true);
                $u = $uid ? app()->db->fetch('SELECT * FROM users WHERE id = ?', [$uid]) : null;
                $set('user', $u);
                $set('role', $u ? Permissions::role((string) $u['role']) : null);
            };
            $forbidden = [...$base, $mk('qa_w1', $B, $fb, 'Verboten')];
            // Editor und Bearbeiten auf der Website: beide speichern über /admin/api/pages/{id}/save → Pages::saveDraft mit der angemeldeten Person
            $actAs($keys['qa_limited']);
            $eq('Editor/Website: verbotener Block → abgelehnt', $denied(fn() => Pages::saveDraft($pid, Pages::sanitizeBlocks($forbidden), $keys['qa_limited'])), true);
            $eq('Editor/Website: gesperrten Block löschen → abgelehnt', $denied(fn() => Pages::saveDraft($pid, array_slice($base, 0, 2), $keys['qa_limited'])), true);
            $eq('Editor/Website: verschieben + Text ändern → gespeichert', $denied(fn() => Pages::saveDraft($pid, Pages::sanitizeBlocks([$base[2], $base[1], array_replace_recursive($base[0], ['data' => [$fa => 'Neu']])]), $keys['qa_limited'])), false);
            $eq('Editor/Website: Sperre im Entwurf erhalten', Pages::blocks(Pages::find($pid) ?? [], true)[0]['lock']['note'] ?? null, 'Agentur-Abschnitt');
            $eq('Angemeldete Person gilt auch ohne Konto-Angabe (z. B. Ansicht als Rolle)', $denied(fn() => Pages::saveDraft($pid, Pages::sanitizeBlocks($forbidden), null)), true);
            $eq('Editor: Blockauswahl ohne verbotene Art', [app()->theme->editorConfig(null)['blocks'][$B]['insertable'] ?? null, app()->theme->editorConfig(null)['blocks'][$B]['guarded'] ?? null], [false, true]);
            $actAs(null);
            Pages::saveDraft($pid, $base, null);   // ohne Person (Zeitplan, Import): keine Prüfung – Ausgangsstand
            // REST-API / MCP (gleicher Dienst CmsService) mit Token des eingeschränkten Kontos: Rechte des Besitzers
            $tok = Api\Tokens::create('QA', 'write', $keys['qa_limited']);
            $row = app()->db->fetch('SELECT * FROM api_tokens WHERE id = ?', [$tok['id']]);
            $svc = (new CmsService($row))->origin('mcp');
            $api = function (callable $fn): int { try { $fn(); return 200; } catch (ApiError $e) { return $e->status; } };
            $eq('API/MCP: verbotenen Block hinzufügen → 403', $api(fn() => $svc->blockAdd($pid, $B, [$fb => 'x'])), 403);
            $eq('API/MCP: fremden Block ändern → 403', $api(fn() => $svc->blockUpdate($pid, 'qa_b1', [$fb => 'x'])), 403);
            $eq('API/MCP: gesperrten Block entfernen → 403', $api(fn() => $svc->blockRemove($pid, 'qa_l1')), 403);
            $eq('API/MCP: gesperrten Block verschieben → erlaubt', $api(fn() => $svc->blockMove($pid, 'qa_l1', 0)), 200);
            $eq('API/MCP: Blöcke ersetzen ohne Sperre – Sperre bleibt', [$api(fn() => $svc->blocksReplace($pid, array_map(fn($b) => ['id' => $b['id'], 'type' => $b['type'], 'data' => $b['data'], 'section' => $b['tunes']['section']], Pages::blocks(Pages::find($pid) ?? [], true)))),
                count(array_filter(Pages::blocks(Pages::find($pid) ?? [], true), fn($b) => !empty($b['lock'])))], [200, 1]);
            // Import: Seite mit Blöcken über API/MCP anlegen
            $eq('Import (Seite anlegen) mit verbotenem Block → 403', $api(fn() => $svc->pageCreate(['title' => 'QA Import', 'slug' => 'qa-import-' . bin2hex(random_bytes(3)), 'blocks' => [['type' => $B, 'data' => [$fb => 'x']]]])), 403);
            $eq('Import (Seite anlegen) mit erlaubtem Block', $api(fn() => $svc->pageCreate(['title' => 'QA Import', 'slug' => 'qa-import-' . bin2hex(random_bytes(3)), 'blocks' => [['type' => $A, 'data' => [$fa => 'x']]]])), 200);
            // Token eines Kontos mit voller Gestaltung bzw. ohne Konto (Kommandozeile)
            $svcFull = new CmsService(['id' => null, 'name' => 'QA', 'scope' => 'write', 'user_id' => $keys['qa_full'], 'review_mode' => 'direct']);
            $eq('Token mit voller Gestaltung: gesperrten Block ändern', $api(fn() => $svcFull->blockUpdate($pid, 'qa_l1', [$fa => 'Agentur ändert'])), 200);
            $svcCli = new CmsService(['id' => null, 'name' => 'QA', 'scope' => 'write', 'user_id' => null, 'review_mode' => 'direct']);
            $eq('Token ohne Konto (Kommandozeile): wie bisher', $api(fn() => $svcCli->blockAdd($pid, $B, [$fb => 'x'])), 200);
            EditLock::setActor(null);
        } catch (\Throwable $e) {
            $fails[] = 'Blöcke – Ausnahme: ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')';
        } finally {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $auth->reset();
            EditLock::setActor(null);
            PageCache::clear();
        }

        // ---------------------------------------------------------- Inhaltsblöcke eines Eintrags (Feldtyp „blocks“)
        self::entryBlocks($eq, $A, $B, $fa, $fb, $limited);

        // ---------------------------------------------------------- Migration und Bestand
        $pdo->beginTransaction();
        try {
            foreach (['qa_m1' => ['pages.edit', 'data.edit'], 'qa_m2' => ['data.edit'], 'qa_m3' => ['*'], 'qa_m4' => ['pages.edit', 'design.blocks.basic']] as $k => $p) {
                app()->db->insert('roles', ['rkey' => $k, 'name' => $k, 'description' => '', 'builtin' => 0, 'tables_json' => null, 'permissions_json' => json_encode($p)]);
            }
            $changed = Permissions::grantDesignBasic(app()->db);
            $eq('Migration: nur Rollen mit „Seiten bearbeiten“ ohne Stufe', array_values(array_intersect($changed, ['qa_m1', 'qa_m2', 'qa_m3', 'qa_m4'])), ['qa_m1']);
            $eq('Migration: Rechte ergänzt, nichts entfernt', Permissions::role('qa_m1')['permissions'] ?? null, ['pages.edit', 'data.edit', 'design.blocks.basic']);
            $eq('Migration: zweiter Lauf ändert nichts', array_values(array_intersect(Permissions::grantDesignBasic(app()->db), ['qa_m1'])), []);
        } catch (\Throwable $e) {
            $fails[] = 'Blöcke – Migration: ' . $e->getMessage();
        } finally {
            if ($pdo->inTransaction()) $pdo->rollBack();
        }
        // Bestand: jede vorhandene Rolle dieser Website darf auf jeder Seite weiter, was sie vorher durfte
        $pages = app()->db->fetchAll('SELECT id, content_draft, content_published FROM pages');
        $n = 0;
        foreach (Permissions::roles() as $rk => $r) {
            if (str_starts_with((string) $rk, 'qa_') || !Permissions::allows($r, 'pages.edit')) continue;
            if (!is_array($r['blocks']) && !in_array('*', $r['permissions'], true)) {
                $eq("Bestand: Rolle „{$rk}“ hat die Stufe „im Rahmen des Kits“", BlockGuard::level($r) !== 'none', true);
            }
            if (is_array($r['blocks'])) continue;   // schon eingeschränkt (neu angelegt) – kein Altbestand
            foreach ($pages as $p) {
                $old = Pages::sanitizeBlocks(Pages::blocks($p, true));
                if (!$old || array_filter($old, fn($b) => !empty($b['lock']))) continue;
                $new = array_map(function ($b) use ($bgs) {
                    $b['tunes']['section']['anchor'] = 'qa-' . $b['id'];
                    if (isset($b['tunes']['section']['background']) && count($bgs) > 1) $b['tunes']['section']['background'] = (string) (array_values(array_diff($bgs, [$b['tunes']['section']['background']]))[0]);
                    return $b;
                }, $old);
                $new = Pages::sanitizeBlocks(array_slice($new, 1));   // ersten Block löschen, Rest gestalten
                try { BlockGuard::check($old, $new, $r); $n++; } catch (BlockDenied $e) { $fails[] = "Blöcke – Bestand: Rolle „{$rk}“, Seite #{$p['id']}: " . $e->getMessage(); }
            }
        }
                return ['ok' => $ok, 'fails' => $fails];
    }

    /** Inhaltsblöcke (Feldtyp „blocks“) eines Eintrags: gleiche Prüfung wie auf Seiten */
    private static function entryBlocks(\Closure $eq, string $A, string $B, string $fa, string $fb, array $limited): void
    {
        if (!Features::on('data', false)) return;
        $h = 'qa_bg_' . bin2hex(random_bytes(3));
        $uid = 0;
        try {
            [$def, $err] = Data\Tables::validate(['name' => 'QA Blockrechte', 'singular' => 'QA', 'handle' => $h, 'fields' => [
                ['label' => 'Titel', 'name' => 'titel', 'type' => 'text', 'required' => 1],
                ['label' => 'Inhalt', 'name' => 'inhalt', 'type' => 'blocks', 'blocks' => [$A, $B]],
            ]]);
            if ($err) { $eq('Inhaltsblöcke: Testtabelle gültig', $err, []); return; }
            Data\Tables::create($def);
            Data\Tables::flush();
            $t = Data\Tables::find($h);
            if (!$t) return;
            $items = fn(array $list) => array_map(fn($b) => ['id' => $b[0], 'type' => $b[1], 'data' => [$b[2] => $b[3]], 'tunes' => ['section' => []]], $list);
            [$id] = Data\Entries::save($t, null, ['titel' => 'QA', 'inhalt' => $items([['qa_e1', $B, $fb, 'Fremd']]), 'status' => 'draft']);
            app()->db->insert('roles', ['rkey' => 'qa_entry', 'name' => 'QA', 'description' => '', 'builtin' => 0, 'tables_json' => null,
                'permissions_json' => json_encode(array_merge($limited['permissions'], ['data.edit'])), 'blocks_json' => json_encode($limited['blocks'])]);
            $uid = (int) app()->db->insert('users', ['email' => 'qa-entry-' . bin2hex(random_bytes(3)) . '@example.test', 'name' => 'QA', 'password_hash' => 'x', 'role' => 'qa_entry', 'created_at' => now()]);
            EditLock::setActor($uid);   // wie ein Token dieses Kontos (API/MCP) bzw. die angemeldete Person
            [, $e1] = Data\Entries::save($t, (int) $id, ['inhalt' => $items([['qa_e1', $B, $fb, 'Geändert']])]);
            $eq('Inhaltsblöcke: fremden Block ändern → abgelehnt', isset($e1['inhalt']) ? true : $e1, true);
            [, $e2] = Data\Entries::save($t, (int) $id, ['inhalt' => $items([['qa_e1', $B, $fb, 'Fremd'], ['qa_e2', $B, $fb, 'Neu']])]);
            $eq('Inhaltsblöcke: verbotenen Block einfügen → abgelehnt', isset($e2['inhalt']), true);
            [, $e3] = Data\Entries::save($t, (int) $id, ['inhalt' => $items([['qa_e3', $A, $fa, 'Erlaubt'], ['qa_e1', $B, $fb, 'Fremd']])]);
            $eq('Inhaltsblöcke: erlaubten Block einfügen, fremden verschieben', $e3 ?? [], []);
            // Formular der Verwaltung: Sperre kommt als verborgenes JSON-Feld zurück und bleibt erhalten (auch bei voller Gestaltung)
            EditLock::setActor(null);
            $form = fn(string $data) => ['0' => ['type' => $A, 'id' => 'qa_e9', 'tunes' => '{}', 'lock' => '{"by":"design","note":"Agentur"}', 'data' => [$fa => $data]]];
            [, $e4] = Data\Entries::save($t, (int) $id, ['inhalt' => $form('Gesperrt')]);
            $cur = Data\EntryBlocks::items(Data\Entries::find($t, (int) $id)['inhalt'] ?? null);
            $eq('Formular: Sperre gespeichert (volle Gestaltung, ohne Person)', [$e4 ?? [], $cur[0]['lock']['note'] ?? null], [[], 'Agentur']);
            $fullUid = (int) app()->db->insert('users', ['email' => 'qa-entry-full-' . bin2hex(random_bytes(3)) . '@example.test', 'name' => 'QA', 'password_hash' => 'x', 'role' => 'admin', 'created_at' => now()]);
            EditLock::setActor($fullUid);
            $plain = ['0' => ['type' => $A, 'id' => 'qa_e9', 'tunes' => '{}', 'lock' => '{"by":"design","note":"Agentur"}', 'data' => [$fa => 'Admin ändert']]];
            Data\Entries::save($t, (int) $id, ['inhalt' => $plain]);
            $cur = Data\EntryBlocks::items(Data\Entries::find($t, (int) $id)['inhalt'] ?? null);
            $eq('Formular: Admin speichert, Sperre bleibt', $cur[0]['lock']['note'] ?? null, 'Agentur');
            $html = Data\EntryBlocks::renderInput(Data\Tables::field($t, 'inhalt') ?? [], 'f[inhalt]', $cur, [], 'inhalt', 'Inhalt', '');
            $eq('Formular: Sperre als verborgenes Feld', str_contains($html, 'name="f[inhalt][0][lock]"') || str_contains($html, '[lock]'), true);
            app()->db->query('DELETE FROM users WHERE id = ?', [$fullUid]);
            // Eingeschränkte Rolle: geschützter Block kommt als „raw“ (Felder nur lesen) zurück – unverändert, also gespeichert
            EditLock::setActor($uid);
            $raw = ['0' => ['type' => $A, 'id' => 'qa_e9', 'tunes' => json_encode($cur[0]['tunes']), 'lock' => json_encode($cur[0]['lock']), 'raw' => json_encode($cur[0]['data'])]];
            [, $e5] = Data\Entries::save($t, (int) $id, ['inhalt' => $raw]);
            $eq('Formular: geschützter Block unverändert (nur lesen) → gespeichert', $e5 ?? [], []);
            $raw['0']['raw'] = json_encode(array_replace($cur[0]['data'], [$fa => 'Gehackt']));
            [, $e6] = Data\Entries::save($t, (int) $id, ['inhalt' => $raw]);
            $eq('Formular: geschützter Block mit geänderten Daten → abgelehnt', isset($e6['inhalt']), true);
        } catch (\Throwable $e) {
            $eq('Inhaltsblöcke: Ausnahme', $e->getMessage(), '');
        } finally {
            EditLock::setActor(null);
            try {
                if ($x = Data\Tables::find($h)) Data\Tables::delete($x);
                app()->db->query("DELETE FROM roles WHERE rkey = 'qa_entry'");
                if ($uid) app()->db->query('DELETE FROM users WHERE id = ?', [$uid]);
            } catch (\Throwable) {}
        }
    }
}
