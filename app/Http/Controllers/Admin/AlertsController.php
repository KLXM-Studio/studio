<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Copyright (C) 2026 KLXM and contributors (see LICENSE, COPYRIGHT)
declare(strict_types=1);

namespace Core\Http\Controllers\Admin;

use Core\Http\HttpException;
use Core\Http\Request;
use Core\Http\Response;
use Core\Mail\Alerts;
use Core\Network\Network;
use Core\Permissions;

/**
 * Systemmeldungen (Core\Mail\Alerts): wer technische Warnungen bekommt – Empfänger-Modus, Agentur-Kontakt, Kategorie × Empfänger,
 * Testversand je Kategorie. Einzelinstallation: Grundeinstellungen → Systemmeldungen (/admin/system/meldungen); mit mehreren
 * Websites im Netzwerk-Bereich (/admin/network/meldungen, Core\Network\Area). Nur wer die Installation verwaltet.
 */
final class AlertsController extends AdminController
{
    private const BASE = '/admin/system/meldungen';

    private function guard(Request $r): array
    {
        $user = $this->auth($r);
        if (!Permissions::installationAdmin()) throw new HttpException(403, __('Nur wer die Installation verwaltet, legt fest, wer Systemmeldungen bekommt.'));
        return $user;
    }

    public function index(Request $r): Response
    {
        $this->guard($r);
        return $this->view('system/meldungen', ['title' => __('Systemmeldungen'), 'cfg' => Alerts::config(), 'net' => \Core\Network\Area::here($r->path), 'css' => ['css/mailoverview.css']]);
    }

    public function save(Request $r): Response
    {
        $user = $this->guard($r);
        $in = $r->post;
        $in['also_admins'] = !empty($r->post['also_admins']);
        if ($err = Alerts::save($in)) return $this->back(self::BASE, 'error', $err);
        $cfg = Alerts::config();
        if (\Core\Sites::multi()) Network::log('mail.alerts', null, (string) $user['email'], $cfg['mode'] . ($cfg['agency_email'] !== '' ? ' · ' . $cfg['agency_email'] : ''));
        return $this->back(self::BASE, 'success', __('Empfänger für Systemmeldungen gespeichert.'));
    }

    /** Testnachricht einer Kategorie an ihre wirksamen Empfänger (gespeicherte Einstellung) */
    public function test(Request $r, string $cat): Response
    {
        $this->guard($r);
        if (!isset(Alerts::CATEGORIES[$cat])) throw new HttpException(404);
        [$type, $msg] = Alerts::test($cat);
        return $this->back(self::BASE . '#al-' . $cat, $type, $msg);
    }
}
