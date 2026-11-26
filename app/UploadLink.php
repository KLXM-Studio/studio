<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Copyright (C) 2026 KLXM and contributors (see LICENSE, COPYRIGHT)
declare(strict_types=1);

namespace Core;

/**
 * Upload-Link für KI-Assistenten (MCP media_upload_link / media_upload_status): Chat-Programme können ein angehängtes Bild nicht als
 * Datei an den MCP-Server weitergeben. Der Assistent erzeugt deshalb einen kurzlebigen Link (/admin/hochladen/{token}); die Person
 * öffnet ihn im Browser und legt die Datei dort ab – sie landet in der Mediathek (im Namen des Token-Kontos), der Assistent fragt den
 * Stand ab und bindet das Bild über seine ID ein (z. B. data_save mit dem Bildfeld).
 *  - Gültig 30 Minuten, genau eine Datei, nur Arten der Mediathek (Bild, optional PDF/Video/Audio), Größe wie Media::maxBytes().
 *  - Ablage: storage/tmp/upload-links/{site}-{token}.json – keine Datenbankänderung.
 */
final class UploadLink
{
    public const TTL = 1800;

    private static function file(string $token): string
    {
        return ROOT . '/storage/tmp/upload-links/' . site()->key . '-' . (preg_match('~^[a-f0-9]{32}$~', $token) ? $token : 'x') . '.json';
    }

    /** Link anlegen @return array{token: string, url: string, expires_at: string} */
    public static function create(?int $userId, ?int $tokenId, string $label = '', string $alt = '', string $kind = 'image'): array
    {
        $token = bin2hex(random_bytes(16));
        $dir = dirname(self::file($token));
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $exp = time() + self::TTL;
        file_put_contents(self::file($token), json_encode(['user' => $userId, 'api_token' => $tokenId, 'label' => mb_substr(trim($label), 0, 200),
            'alt' => mb_substr(trim($alt), 0, 300), 'kind' => in_array($kind, ['image', 'file'], true) ? $kind : 'image', 'expires' => $exp, 'media' => null], JSON_UNESCAPED_UNICODE));
        return ['token' => $token, 'url' => absolute_url('/admin/hochladen/' . $token), 'expires_at' => date('c', $exp)];
    }

    /** Link lesen (null = unbekannt oder abgelaufen) */
    public static function find(string $token): ?array
    {
        $f = self::file($token);
        if (!is_file($f)) return null;
        $d = json_decode((string) file_get_contents($f), true);
        if (!is_array($d)) return null;
        if ((int) ($d['expires'] ?? 0) < time() && empty($d['media'])) { @unlink($f); return null; }
        return $d;
    }

    /** Hochgeladene Datei vermerken */
    public static function complete(string $token, array $media): void
    {
        $d = self::find($token);
        if (!$d) return;
        $d['media'] = ['id' => (int) $media['id'], 'url' => (string) ($media['url'] ?? ''), 'display' => (string) ($media['display'] ?? ''), 'at' => date('c')];
        file_put_contents(self::file($token), json_encode($d, JSON_UNESCAPED_UNICODE));
    }

    /** Stand für den Assistenten @return array{status: string, media?: array} */
    public static function status(string $token): array
    {
        $d = self::find($token);
        if (!$d) return ['status' => 'expired'];
        return $d['media'] ? ['status' => 'done', 'media' => $d['media']] : ['status' => 'waiting', 'expires_at' => date('c', (int) $d['expires'])];
    }
}
