<?php
declare(strict_types=1);

namespace Core\Support;

use Core\Api\ApiError;
use Core\Features;
use Core\Permissions;

/**
 * Support für REST-API und MCP (eine Implementierung, zwei Schnittstellen):
 *   GET /api/v1/kb?q=…            veröffentlichte Wissensartikel, die für diese Website sichtbar sind
 *   MCP search_knowledge / report_issue
 *   MCP support_list / support_get / support_reply / support_status – Meldungen lesen und beantworten im Namen des
 *   Token-Benutzers mit dessen Sicht (Support-Team: alle Websites, Website-Administration: eigene Website, sonst eigene Meldungen)
 *   CLI support:list / support:show / support:reply (--as=E-Mail) nutzen dieselben Methoden.
 */
final class Api
{
    private static function enabled(): void
    {
        if (!Features::on('support', false)) throw new ApiError(404, 'Support ist auf dieser Website abgeschaltet.');
    }

    /** @return array{query:string,articles:list<array>} */
    public static function knowledge(string $q, int $limit = 10): array
    {
        self::enabled();
        $q = trim(mb_substr($q, 0, 200));
        $limit = max(1, min(50, $limit));
        $rows = $q === '' ? Knowledge::list([], $limit)['rows'] : Knowledge::search($q, $limit);
        return ['query' => $q, 'articles' => array_values(array_map(fn($a) => [
            'id' => (int) $a['id'], 'title' => $a['title'], 'tags' => Support::tagList((string) $a['tags']),
            'excerpt' => Markdown::plain($a['body'], 240), 'body' => $a['body'], 'body_format' => 'markdown-lite',
            'updated_at' => $a['updated_at'], 'url' => admin_url_abs('/admin/support/wissen/' . $a['id']),
        ], array_filter($rows, fn($a) => $a['status'] === 'published')))];
    }

    /** Meldung im Namen des Benutzers, dem das Token gehört */
    public static function report(array $token, array $args): array
    {
        self::enabled();
        $uid = $token['user_id'] ?? null;
        $user = $uid !== null ? app()->db->fetch('SELECT id, email, name, role FROM users WHERE id = ?', [(int) $uid]) : null;
        if (!$user) throw new ApiError(403, 'report_issue braucht ein Token, das einem Benutzer gehört (API & MCP → Token mit Benutzer anlegen).');
        if (!Permissions::allows(Permissions::role((string) $user['role']), 'support.report')) {
            throw new ApiError(403, 'Die Rolle des Token-Benutzers darf keine Probleme melden (Recht support.report).');
        }
        $title = trim((string) ($args['title'] ?? ''));
        $body = trim((string) ($args['description'] ?? $args['body'] ?? ''));
        if (mb_strlen($title) < 4 || mb_strlen($body) < 10) throw new ApiError(422, 'title (≥ 4 Zeichen) und description (≥ 10 Zeichen) angeben.');
        $res = Tickets::create([
            'title' => $title, 'body' => $body,
            'category' => in_array($args['category'] ?? '', Support::CATEGORIES, true) ? $args['category'] : 'frage',
            'priority' => ($args['priority'] ?? '') === 'dringend' ? 'dringend' : 'normal',
        ], [], ['site' => site()->key . ' (' . Support::siteLabel(site()->key) . ')', 'cms' => CMS_NAME . ' ' . CMS_VERSION,
            'browser' => 'API/MCP-Token „' . ($token['name'] ?? '') . '“', 'environment' => environment()], $user);
        return ['id' => $res['id'], 'status' => 'neu', 'url' => admin_url_abs('/admin/support/meldung/' . $res['id']),
            'hint' => 'Das Support-Team antwortet in der Verwaltung unter Support › Meine Meldungen und per E-Mail.'];
    }

    // ------------------------------------------------------------------ Meldungen lesen und beantworten

    /** Konto des Tokens (MCP) bzw. aus --as (CLI) @throws ApiError */
    public static function tokenUser(array $token): array
    {
        $uid = $token['user_id'] ?? null;
        $u = $uid !== null && $uid !== '' ? app()->db->fetch('SELECT id, email, name, role, locale, network_uid, disabled FROM users WHERE id = ?', [(int) $uid]) : null;
        if (!$u || !empty($u['disabled'])) throw new ApiError(403, 'Support-Meldungen lesen braucht ein Token, das einem aktiven Benutzer gehört (API & MCP → Token mit Benutzer anlegen).');
        return $u;
    }

    /** $fn im Namen des Kontos ausführen (Sicht und Rechte wie in der Verwaltung) */
    private static function as(array $user, callable $fn): mixed
    {
        self::enabled();
        $auth = app()->auth;
        $auth->actAs($user);
        Support::reset();
        try {
            if (!Support::canRead() && !Support::isSiteManager()) throw new ApiError(403, 'Die Rolle des Token-Benutzers darf den Support nicht nutzen (Recht support.report).');
            return $fn();
        } finally {
            $auth->actAs(null);
            Support::reset();
        }
    }

    private static function brief(array $i): array
    {
        return ['id' => (int) $i['id'], 'title' => (string) $i['title'], 'status' => (string) $i['status'], 'status_label' => Support::statusLabel((string) $i['status']),
            'category' => (string) $i['category'], 'priority' => (string) $i['priority'], 'site' => (string) $i['site'], 'site_label' => Support::siteLabel((string) $i['site']),
            'reporter' => (string) ($i['reporter_name'] ?? ''), 'assignee' => $i['assignee_name'] ?? null,
            'created_at' => (string) $i['created_at'], 'updated_at' => (string) $i['updated_at'],
            'url' => Support::urlOn((string) $i['site'], '/admin/support/meldung/' . (int) $i['id'])];
    }

    /** Liste: status offen (Standard) | neu | in_arbeit | rueckfrage | geloest | geschlossen | alle */
    public static function issues(array $user, array $a): array
    {
        return self::as($user, function () use ($a) {
            $st = (string) ($a['status'] ?? 'offen');
            $res = Tickets::list(['status' => $st === 'alle' ? '' : $st, 'site' => (string) ($a['site'] ?? ''), 'category' => (string) ($a['category'] ?? ''),
                'priority' => (string) ($a['priority'] ?? ''), 'assignee' => (string) ($a['assignee'] ?? ''), 'q' => (string) ($a['q'] ?? ''),
                'mine' => !empty($a['mine']), 'page' => (int) ($a['page'] ?? 1)], max(1, min(100, (int) ($a['limit'] ?? 25))));
            return ['scope' => Support::isStaff() ? 'staff' : (Support::isSiteManager() ? 'site' : 'own'), 'total' => $res['total'], 'pages' => $res['pages'],
                'issues' => array_map(fn($i) => self::brief($i) + ['replies' => (int) $i['replies'], 'files' => (int) $i['nfiles'], 'unread' => (bool) $i['unread']], $res['rows'])];
        });
    }

    /** Eine Meldung mit Text, Umgebung (Seite, Browser, Version …), Verlauf und Anhängen; mark_seen = als gelesen merken */
    public static function issue(array $user, int $id, bool $markSeen = false): array
    {
        return self::as($user, function () use ($id, $markSeen) {
            $i = Tickets::find($id) ?? throw new ApiError(404, 'Meldung #' . $id . ' gibt es nicht oder sie ist für dieses Konto nicht sichtbar.');
            $ctx = json_decode((string) ($i['context_json'] ?? ''), true);
            $files = [];
            foreach (Tickets::files($i) as $pid => $list) foreach ($list as $f) {
                $files[] = ['id' => (int) $f['id'], 'post_id' => $pid ?: null, 'name' => (string) $f['name'], 'mime' => (string) $f['mime'], 'size' => (int) $f['size'],
                    'width' => $f['width'] !== null ? (int) $f['width'] : null, 'height' => $f['height'] !== null ? (int) $f['height'] : null, 'internal' => (bool) $f['internal']];
            }
            $posts = array_map(fn($p) => ['id' => (int) $p['id'], 'kind' => (string) $p['kind'], 'internal' => (bool) $p['internal'],
                'author' => (string) ($p['author_name'] ?? ''), 'staff' => (bool) $p['author_staff'], 'at' => (string) $p['created_at'],
                'body' => (string) ($p['body'] ?? ''), 'meta' => $p['meta'] ? json_decode((string) $p['meta'], true) : null], Tickets::posts($i));
            if ($markSeen) Tickets::markSeen($id);
            return self::brief($i) + ['body' => (string) $i['body'], 'body_format' => 'markdown-lite', 'reporter_email' => Support::isSiteManager() ? (string) ($i['reporter_email'] ?? '') : null,
                'context' => is_array($ctx) ? $ctx : [], 'posts' => $posts, 'files' => $files,
                'can' => ['reply' => $i['status'] !== 'geschlossen' || Support::isStaff(), 'internal' => Support::isStaff(), 'status' => Support::isStaff() ? Support::STATUSES : []]];
        });
    }

    /** Antwort bzw. interne Notiz (nur Team), optional mit neuem Status (nur Team) */
    public static function reply(array $user, array $a): array
    {
        return self::as($user, function () use ($a) {
            $i = Tickets::find((int) ($a['id'] ?? 0)) ?? throw new ApiError(404, 'Meldung nicht gefunden oder nicht sichtbar.');
            $staff = Support::isStaff();
            if (!$staff && $i['status'] === 'geschlossen') throw new ApiError(409, 'Die Meldung ist geschlossen.');
            $body = trim((string) ($a['body'] ?? ''));
            $status = $staff && in_array($a['status'] ?? '', Support::STATUSES, true) ? (string) $a['status'] : null;
            if ($body === '' && ($status === null || $status === $i['status'])) throw new ApiError(422, 'body (Text der Antwort) oder status angeben.');
            if (!$staff && !Tickets::isReporter($i) && !Support::isSiteManager()) throw new ApiError(403, 'Keine Berechtigung.');
            $internal = $staff && !empty($a['internal']);
            Tickets::reply($i, $body, $internal, [], $status);
            $now = Support::db()->fetch('SELECT * FROM issues WHERE id = ?', [(int) $i['id']]) ?? $i;
            return ['ok' => true, 'id' => (int) $i['id'], 'internal' => $internal, 'status' => (string) $now['status'],
                'hint' => $internal ? 'Interne Notiz – nur das Support-Team sieht sie.' : 'Die meldende Person wird per E-Mail benachrichtigt.'];
        });
    }

    /** Status ändern (Team; Meldende nur „geloest“ bzw. wieder „in_arbeit“) */
    public static function status(array $user, array $a): array
    {
        return self::as($user, function () use ($a) {
            $i = Tickets::find((int) ($a['id'] ?? 0)) ?? throw new ApiError(404, 'Meldung nicht gefunden oder nicht sichtbar.');
            $to = (string) ($a['status'] ?? '');
            $ok = Support::isStaff() || (Tickets::isReporter($i) && (($to === 'geloest' && in_array($i['status'], Support::OPEN, true)) || ($to === 'in_arbeit' && $i['status'] === 'geloest')));
            if (!in_array($to, Support::STATUSES, true)) throw new ApiError(422, 'status: ' . implode(' | ', Support::STATUSES));
            if (!$ok) throw new ApiError(403, 'Den Status ändert das Support-Team.');
            Tickets::setStatus($i, $to, !isset($a['notify']) || !empty($a['notify']));
            return ['ok' => true, 'id' => (int) $i['id'], 'status' => $to, 'status_label' => Support::statusLabel($to)];
        });
    }
}
