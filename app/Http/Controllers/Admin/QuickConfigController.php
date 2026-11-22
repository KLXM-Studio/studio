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
        return $this->view('quickconfig/index', ['me' => $user, 'bp' => $bp, 'steps' => $steps, 'state' => $state, 'step' => $step, 'org' => $org,
            'values' => $values, 'accent' => (string) ($design['accent'] ?? ''), 'hasAccent' => isset(\Core\Design::tokens()['accent']),
            'logo' => (int) app()->settings->get('logo', 0), 'region' => $set('org.region'), 'refs' => \Core\Blueprint\Network::refs(),
            'roles' => \Core\Invites::roleOptions(\Core\Permissions::role((string) ($user['role'] ?? ''))), 'demo' => Demo::counts(),
            'mail' => \Core\Mail\Accounts::standard(), 'netMail' => \Core\Mail\Accounts::network() !== null, 'checklist' => QuickConfig::checklist(),
            'proposal' => Diff::proposal(), 'placeholders' => (array) ($bp['placeholders'] ?? []), 'css' => ['css/blueprint.css']]);
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
