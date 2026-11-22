<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Copyright (C) 2026 KLXM and contributors (see LICENSE, COPYRIGHT)
declare(strict_types=1);

namespace Core\Http\Controllers\Admin;

use Core\Blueprint\Apply;
use Core\Blueprint\Demo;
use Core\Blueprint\Diff;
use Core\Blueprint\QuickConfig;
use Core\Http\HttpException;
use Core\Http\Request;
use Core\Http\Response;

/**
 * Schnelleinrichtung einer Website aus einer Vorlage (/admin/schnelleinrichtung, Core\Blueprint\QuickConfig): Schritte der Vorlage,
 * Abschluss mit Aufgaben, Demoinhalte entfernen, Vorschlag einer neuen Vorlagen-Version übernehmen. Recht system.manage.
 */
final class QuickConfigController extends AdminController
{
    public function index(Request $r): Response
    {
        $user = $this->auth($r, 'system.manage');
        $bp = (array) app()->settings->get(Apply::KEY, []);
        if (!$bp) throw new HttpException(404, __('Diese Website stammt nicht aus einer Website-Vorlage.'));
        $steps = QuickConfig::steps();
        $state = QuickConfig::state();
        $step = $r->str('schritt');
        if (!in_array($step, $steps, true)) {
            $step = '';
            foreach ($steps as $s) if (empty($state['done'][$s])) { $step = $s; break; }
            if ($step === '' && $steps) $step = $steps[0];
        }
        $values = QuickConfig::values();
        $set = fn(string $k) => (string) app()->settings->get($k, '');
        // Vorbelegung des Schritts Organisation: gespeicherte Werte, sonst die Einstellungen des Kits (ohne offene Platzhalter)
        $org = [];
        foreach (QuickConfig::ORG_FIELDS as $f => $key) {
            $v = $values[$key] ?? $set(\Core\Blueprint\Placeholders::KEYS[$key][2]);
            $org[$f] = str_contains($v, '{{') ? '' : $v;
        }
        $design = \Core\Design::enabled() ? \Core\Design::values() : [];
        // Farbe der Auswahl: zuletzt gewählte Ausgangsfarbe (nicht der angepasste Akzent), sonst der Akzent der Vorlage
        $pick = \Core\Design::color((string) ($values['look.color'] ?? '')) ?? \Core\Design::color((string) ($design['accent'] ?? '')) ?? '#0F766E';
        $hasAccent = isset(\Core\Design::tokens()['accent']);
        return $this->view('quickconfig/index', ['me' => $user, 'bp' => $bp, 'steps' => $steps, 'state' => $state, 'step' => $step, 'org' => $org,
            'values' => $values, 'accent' => (string) ($design['accent'] ?? ''), 'hasAccent' => $hasAccent, 'pickColor' => $pick,
            'palettes' => $hasAccent && $step === 'look' ? \Core\Blueprint\Palette::options($pick) : [],
            'logo' => (int) app()->settings->get('logo', 0), 'region' => $set('org.region'), 'refs' => \Core\Blueprint\Network::refs(),
            'roles' => \Core\Invites::roleOptions(\Core\Permissions::role((string) ($user['role'] ?? ''))), 'demo' => Demo::counts(),
            'mail' => \Core\Mail\Accounts::standard(), 'netMail' => \Core\Mail\Accounts::network() !== null, 'checklist' => QuickConfig::checklist(),
            'proposal' => Diff::proposal(), 'placeholders' => (array) ($bp['placeholders'] ?? []), 'css' => ['css/blueprint.css']]);
    }

    /** Paletten zu einer Farbe (Live-Vorschau im Schritt Erscheinungsbild, resources/js/_quickconfig.js) – nur berechnen, nichts speichern */
    public function palettes(Request $r): Response
    {
        $this->auth($r, 'system.manage');
        $c = \Core\Design::color($r->str('color'));
        if ($c === null || !isset(\Core\Design::tokens()['accent'])) return Response::json(['ok' => false], 422);
        return Response::json(['ok' => true, 'palettes' => \Core\Blueprint\Palette::options($c)])->header('Cache-Control', 'no-store');
    }

    public function save(Request $r, string $step): Response
    {
        $user = $this->auth($r, 'system.manage');
        $back = '/admin/schnelleinrichtung';
        if ($step === 'finish') {
            app()->session->flash('success', implode(' ', QuickConfig::finish($user, !empty($r->post['live']))));
            return $this->back('/admin');
        }
        if ($step === 'update') {
            try {
                $lines = Diff::applyProposal();
            } catch (\Throwable $e) {
                return $this->back($back, 'error', $e->getMessage());
            }
            return $this->back($back, 'success', implode(' · ', $lines));
        }
        if (!in_array($step, QuickConfig::STEPS, true)) throw new HttpException(404);
        $in = $r->post;
        if ($step === 'look' && is_array($f = $r->files['logo'] ?? null) && (int) ($f['error'] ?? 4) === UPLOAD_ERR_OK && is_uploaded_file((string) $f['tmp_name'])) {
            $in['logo_file'] = (string) $f['tmp_name'];
            $in['logo_name'] = (string) $f['name'];
        }
        if ($step === 'mail') $in['test'] = !empty($r->post['test']);
        $res = QuickConfig::saveStep($step, $in, $user);
        if ($res['errors']) return $this->back($back . '?schritt=' . $step, 'error', implode(' ', $res['errors']));
        // Weiter zum nächsten offenen Schritt
        $next = '';
        $state = QuickConfig::state();
        foreach (QuickConfig::steps() as $s) if (empty($state['done'][$s])) { $next = $s; break; }
        return $this->back($back . ($next !== '' ? '?schritt=' . $next : '?schritt=' . $step . '#abschluss'), 'success', implode(' ', $res['messages']));
    }
}
