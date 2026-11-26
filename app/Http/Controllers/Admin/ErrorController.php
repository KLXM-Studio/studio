<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Copyright (C) 2026 KLXM and contributors (see LICENSE, COPYRIGHT)
declare(strict_types=1);

namespace Core\Http\Controllers\Admin;

use Core\Http\Response;

/**
 * Fehler in der Verwaltung (403, 404, 405, 413, 419) für angemeldete Konten im Verwaltungsrahmen statt der öffentlichen Fehlerseite des
 * Kits – mit verständlicher Meldung („Dafür fehlt Ihrer Rolle das Recht …“) und Wegen zurück. Aufruf: Core\App bei HttpException.
 */
final class ErrorController extends AdminController
{
    public function show(int $code, string $message = ''): Response
    {
        [$title, $text] = match ($code) {
            403 => [__('Kein Zugriff'), $message !== '' ? $message : __('Dafür fehlt Ihrer Rolle das Recht. Fragen Sie bei Bedarf die Administration Ihrer Website.')],
            404 => [__('Nicht gefunden'), __('Diese Seite der Verwaltung gibt es nicht (mehr) – vielleicht wurde der Eintrag gelöscht oder die Adresse ist unvollständig.')],
            419 => [__('Sitzung abgelaufen'), $message !== '' ? $message : __('Bitte laden Sie die Seite neu.')],
            default => [__('Das hat nicht geklappt'), $message !== '' ? $message : __('Die Aktion ist hier nicht möglich.')],
        };
        return $this->view('error', ['code' => $code, 'heading' => $title, 'text' => $text], $code);
    }
}
