<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Copyright (C) 2026 KLXM and contributors (see LICENSE, COPYRIGHT)
declare(strict_types=1);

namespace Core\Http\Controllers\Admin;

use Core\Http\HttpException;
use Core\Http\Request;
use Core\Http\Response;
use Core\Media;
use Core\UploadLink;

/**
 * Upload-Link (Core\UploadLink, MCP media_upload_link): Seite /admin/hochladen/{token} – ohne Anmeldung, der geheime Link ist der
 * Zugang (30 Minuten, eine Datei). Die Datei landet in der Mediathek im Namen des Kontos hinter dem API-Token.
 */
final class UploadLinkController extends AdminController
{
    public function show(Request $r, string $token): Response
    {
        $link = UploadLink::find($token) ?? throw new HttpException(404, __('Dieser Upload-Link ist abgelaufen oder unbekannt.'));
        return $this->view('upload_link', ['user' => null, 'title' => __('Datei hochladen'), 'link' => $link, 'token' => $token, 'done' => $link['media'] ?? null, 'error' => '']);
    }

    public function upload(Request $r, string $token): Response
    {
        $link = UploadLink::find($token) ?? throw new HttpException(404, __('Dieser Upload-Link ist abgelaufen oder unbekannt.'));
        if (!empty($link['media'])) return Response::redirect(url('/admin/hochladen/' . $token));
        $f = $r->files['datei'] ?? null;
        $err = '';
        if (!is_array($f) || ($f['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $f['tmp_name'])) {
            $err = __('Bitte eine Datei wählen.');
        } elseif ((int) $f['size'] > Media::maxBytes()) {
            $err = __('Die Datei ist zu groß.');
        } elseif ($link['kind'] === 'image' && !str_starts_with((string) (mime_content_type((string) $f['tmp_name']) ?: ''), 'image/')) {
            $err = __('Bitte ein Bild (JPG, PNG, WebP oder GIF) wählen.');
        } else {
            // Im Namen des Kontos hinter dem API-Token hochladen (Urheber in der Mediathek, Rechte wie beim MCP)
            $u = !empty($link['user']) ? \Core\Auth::userRow(app()->db, (int) $link['user']) : null;
            if ($u) app()->auth->actAs($u);
            try {
                [$m, $e] = Media::import((string) $f['tmp_name'], (string) ($f['name'] ?? 'bild'), (string) $link['alt']);
            } finally {
                if ($u) app()->auth->actAs(null);
            }
            if ($m) {
                UploadLink::complete($token, Media::toJson($m));
                return Response::redirect(url('/admin/hochladen/' . $token));
            }
            $err = (string) $e;
        }
        return $this->view('upload_link', ['user' => null, 'title' => __('Datei hochladen'), 'link' => $link, 'token' => $token, 'done' => null, 'error' => $err], 422);
    }
}
