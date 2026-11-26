<?php
declare(strict_types=1);

namespace Core\Http\Controllers\Admin;

use Core\Data\Exchange;
use Core\Data\Shared;
use Core\Data\Tables;
use Core\Http\HttpException;
use Core\Http\Request;
use Core\Http\Response;
use Core\RateLimiter;

/**
 * Importieren in Adressbücher (vCard) und Kalender (ICS) – Core\Data\Exchange: Datei ziehen bzw. wählen (Kalender auch: Adresse
 * einmalig abrufen), Vorschau als Probelauf, Bestätigen. Kalender-Abos mit regelmäßigem Abruf legt „Abonnieren“ als Externe Quelle an.
 * Rechte: data.import auf die Tabelle; neue Listen/Kategorien nur mit data.schema; Veröffentlichen neuer Einträge nur mit data.publish
 * (bei Freigabe-Workflow).
 */
final class ExchangeController extends AdminController
{
    private function table(Request $r, string $handle): array
    {
        $this->auth($r, 'data.import', $handle);   // Importieren: eigenes Recht (Standard: nur Administration)
        $t = Tables::find($handle) ?? throw new HttpException(404);
        if (Exchange::kind($t) === null) throw new HttpException(404);
        return $t;
    }

    public function form(Request $r, string $handle): Response
    {
        return $this->page($this->table($r, $handle));
    }

    /** step=preview (Datei oder Adresse → Probelauf) | apply (Token → übernehmen) */
    public function submit(Request $r, string $handle): Response
    {
        $t = $this->table($r, $handle);
        $kind = (string) Exchange::kind($t);
        if ($r->str('step') === 'apply') return $this->apply($r, $t, $kind);
        $limiter = new RateLimiter(app()->db);
        $key = 'import:' . (int) (app()->auth->user()['id'] ?? 0);
        if ($limiter->tooMany($key, 40, 3600)) return $this->page($t, ['error' => __('Zu viele Importe in kurzer Zeit – bitte einige Minuten warten.')], 429);
        $limiter->hit($key);
        $url = trim($r->str('url'));
        $noFile = !is_array($r->files['datei'] ?? null) || (($r->files['datei']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE);
        if ($kind === 'ics' && $url !== '' && $noFile) {
            [$body, $err] = Exchange::fromUrl($url);
            $label = (string) Exchange::normUrl($url);
        } else {
            [$body, $err] = Exchange::fromUpload($r->files['datei'] ?? null, $kind);
            $label = mb_substr(basename((string) ($r->files['datei']['name'] ?? '')), 0, 120);
        }
        if ($err !== null || $body === null) return $this->page($t, ['error' => (string) $err, 'url' => $url], 422);
        @set_time_limit(300);
        $rep = Exchange::run($t, $kind, $body, $this->options($r, $t, true));
        if (!$rep['ok']) return $this->page($t, ['error' => (string) $rep['error'], 'url' => $url], 422);
        $token = Exchange::stash($t, $kind, $body, $label);
        return $this->page($t, ['report' => $rep, 'token' => $token, 'label' => $label]);
    }

    private function apply(Request $r, array $t, string $kind): Response
    {
        $token = $r->str('token');
        $st = Exchange::unstash($t, $kind, $token);
        if (!$st) return $this->page($t, ['error' => __('Die Vorschau ist abgelaufen – bitte die Datei erneut wählen.')], 410);
        @set_time_limit(300);
        $rep = Exchange::run($t, $kind, $st['body'], $this->options($r, $t, false));
        Exchange::drop($token);
        $this->changed();
        if (!$rep['ok']) return $this->back('/admin/data/' . $t['handle'] . '/import', 'error', (string) $rep['error']);
        $msg = __('Import abgeschlossen: {c} neu, {u} geändert, {s} unverändert.', ['c' => $rep['created'], 'u' => $rep['updated'], 's' => $rep['unchanged']]);
        if ($rep['failed']) $msg .= ' ' . __('{n} mit Fehlern.', ['n' => $rep['failed']]);
        return $this->back('/admin/data/' . $t['handle'] . ($kind === 'ics' ? '?view=calendar' : ''), $rep['failed'] ? 'error' : 'success', $msg);
    }

    /** Optionen aus dem Formular, begrenzt durch die Rechte */
    private function options(Request $r, array $t, bool $dry): array
    {
        $schema = can('data.schema') && Shared::canSchema($t);
        $publish = !$t['settings']['workflow'] || can('data.publish', $t['handle']);
        // Vorschau: so, wie es mit den Standardwerten übernommen würde (neue Listen/Kategorien anlegen, wenn erlaubt)
        $create = $schema && ($dry || $r->str('anlegen') === '1');
        $status = $r->str('status') === 'published' && $publish ? 'published' : ($r->str('status') === '' && Exchange::kind($t) === 'vcf' && $publish ? 'published' : 'draft');
        return ['dry' => $dry, 'status' => $status, 'lists' => $create, 'categories' => $create, 'photos' => $r->str('fotos') !== '0'];
    }

    /** Kalender abonnieren: Externe Quelle (Format ics) mit dieser Tabelle anlegen und zur Quelle wechseln */
    public function subscribe(Request $r, string $handle): Response
    {
        $t = $this->table($r, $handle);
        if (Exchange::kind($t) !== 'ics') throw new HttpException(404);
        if (!\Core\Sources\Sources::canManage()) throw new HttpException(403, __('Kalender-Abos legt an, wer Externe Quellen verwalten darf (Funktion „Externe Quellen“).'));
        $url = Exchange::normUrl($r->str('url'));
        if ($url === null) return $this->page($t, ['error' => __('Bitte eine vollständige Adresse mit https:// (oder webcal://) angeben – ohne Zugangsdaten.'), 'url' => $r->str('url')], 422);
        $name = trim(strip_tags($r->str('name'))) ?: (string) parse_url($url, PHP_URL_HOST);
        [$id, $errors] = \Core\Sources\Sources::save(null, ['name' => mb_substr($name, 0, 120), 'format' => 'ics', 'url' => $url, 'table_handle' => $t['handle'],
            'options' => ['schedule' => in_array($r->str('schedule'), ['hourly', 'daily'], true) ? $r->str('schedule') : 'daily', 'ttl' => 60, 'timeout' => 15, 'max_items' => 2000, 'max_mb' => 5,
                'missing' => in_array($r->str('missing'), ['hide', 'delete', 'keep'], true) ? $r->str('missing') : 'hide', 'status' => $r->str('status') === 'published' ? 'published' : 'draft']]);
        if (!$id) return $this->page($t, ['error' => implode(' ', array_map('strval', $errors)), 'url' => $r->str('url')], 422);
        return $this->back('/admin/quellen/' . $id, 'success', __('Kalender-Abo angelegt. Mit „Jetzt abrufen“ holen Sie die Termine sofort, danach regelmäßig nach Zeitplan.'));
    }

    private function page(array $t, array $s = [], int $status = 200): Response
    {
        $kind = (string) Exchange::kind($t);
        $sources = array_values(array_filter(\Core\Sources\Sources::canManage() ? \Core\Sources\Sources::all() : [], fn($x) => $x['format'] === 'ics' && $x['table_handle'] === $t['handle']));
        return $this->view('data/import', $s + [
            't' => $t, 'kind' => $kind, 'report' => null, 'token' => '', 'label' => '', 'error' => null, 'url' => '', 'abos' => $sources,
            'canSchema' => can('data.schema') && Shared::canSchema($t), 'canPublish' => !$t['settings']['workflow'] || can('data.publish', $t['handle']),
            'drill' => \Core\Theme::capture(ROOT . '/app/Admin/views/data/_nav.php', ['cur' => 'import', 'active' => $t]), 'drillTitle' => __('Daten'),
        ], $status);
    }
}
