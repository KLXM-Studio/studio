<?php
declare(strict_types=1);

namespace Core\Http\Controllers\Admin;

use Core\Data\Tables;
use Core\Http\HttpException;
use Core\Http\Request;
use Core\Http\Response;
use Core\Permissions;

/** Rollen anlegen und Rechte zuweisen (Recht: users.manage). */
final class RoleController extends AdminController
{
    public function edit(Request $r, ?string $key = null): Response
    {
        $this->auth($r, 'users.manage');
        $role = $key ? (Permissions::role($key) ?? throw new HttpException(404)) : null;
        return $this->view('role', ['role' => $role, 'catalog' => array_filter(array_map(fn($g) => array_filter($g, fn($p) => \Core\Features::allowsPermission($p), ARRAY_FILTER_USE_KEY), Permissions::catalog())), 'tables' => Tables::all(), 'blockCatalog' => \Core\BlockGuard::catalog(), 'errors' => []]);
    }

    public function save(Request $r, ?string $key = null): Response
    {
        $this->auth($r, 'users.manage');
        $role = $key ? (Permissions::role($key) ?? throw new HttpException(404)) : null;
        if ($role && $role['key'] === 'admin') {
            return $this->back('/admin/users/rollen', 'error', __('Die Rolle „Administration“ hat immer alle Rechte.'));
        }
        if ($role && $role['key'] === 'network') {
            return $this->back('/admin/users/rollen', 'error', __('Netzwerk-Konten werden zentral in der Netzwerk-Verwaltung verwaltet.'));
        }
        $name = mb_substr(trim(strip_tags($r->str('name'))), 0, 60);
        if ($name === '') {
            return $this->back($key ? "/admin/roles/$key" : '/admin/roles/new', 'error', __('Bitte einen Namen angeben.'));
        }
        $perms = array_values(array_intersect((array) ($r->post['perms'] ?? []), Permissions::all()));   // angehakt (Prüfung unten)
        // Ohne alle Rechte (*): nur Rechte vergeben, die die eigene Rolle hat – und keine Rolle ändern, die mehr darf
        $mine = app()->auth->role();
        if ($mine !== null && !in_array('*', $mine['permissions'], true)) {
            if (array_diff($perms, $mine['permissions']) || ($role && !UserController::mayManageRole($role['key']))) {
                return $this->back('/admin/users/rollen', 'error', __('Sie können nur Rechte vergeben, die Ihre eigene Rolle hat.'));
            }
        }
        $tables = $r->str('tables_mode') === 'some'
            ? json_encode(array_values(array_intersect((array) ($r->post['tables'] ?? []), array_column(Tables::all(), 'handle'))))
            : null;
        // Erlaubte Blöcke (Core\BlockGuard): Liste der Typen oder null (alle im Rahmen der Website). Typen außerhalb des Rahmens bleiben
        // gespeichert, wenn die Rolle sie schon hatte (Rahmen kann sich ändern) – neu angehakt werden nur wählbare
        $known = array_keys(array_merge(...array_values(\Core\BlockGuard::catalog()) ?: [[]]));
        $blocks = null;
        if ($r->str('blocks_mode') === 'some') {
            $posted = array_values(array_intersect(array_map('strval', (array) ($r->post['blocks'] ?? [])), $known));
            $kept = array_values(array_filter((array) ($role['blocks'] ?? []), fn($t) => !\Core\Features::allowsBlock((string) $t)));
            $blocks = array_values(array_unique([...$posted, ...$kept]));
        }
        // „nur bearbeiten“: bestehende Blöcke dieser Typen ändern, keine neuen einfügen – nur sinnvoll bei eingeschränkter Auswahl
        $blocksEdit = $blocks === null ? [] : array_values(array_diff(array_intersect(array_map('strval', (array) ($r->post['blocks_edit'] ?? [])), $known), $blocks));
        // Rolle nach dem Speichern prüfen wie beim Zuweisen – eine auf Tabellen beschränkte Rolle darf sich nicht selbst erweitern
        if ($mine !== null && !in_array('*', $mine['permissions'], true)
            && !\Core\Invites::assignable(['key' => $role['key'] ?? 'neu', 'permissions' => $perms, 'tables' => $tables === null ? null : json_decode($tables, true), 'blocks' => $blocks], $mine)) {
            return $this->back('/admin/users/rollen', 'error', __('Sie können nur Rechte und Tabellen vergeben, die Ihre eigene Rolle hat.'));
        }
        // Rechte, die die Oberfläche nicht zeigt (abgeschaltete Erweiterung), bleiben erhalten – entfernen nur mit roles:clean
        $data = ['name' => $name, 'description' => mb_substr(trim(strip_tags($r->str('description'))), 0, 200),
            'permissions_json' => json_encode(Permissions::keepHidden($perms, $role['permissions'] ?? [])), 'tables_json' => $tables,
            'blocks_json' => $blocks === null ? null : json_encode($blocks), 'blocks_edit_json' => $blocksEdit ? json_encode($blocksEdit) : null];
        if ($role) {
            app()->db->update('roles', $data, 'rkey = :k', ['k' => $role['key']]);
        } else {
            $base = Tables::normName($name) ?: 'rolle';
            $k = $base;
            for ($n = 2; Permissions::role($k); $n++) $k = $base . '_' . $n;
            app()->db->insert('roles', $data + ['rkey' => $k, 'builtin' => 0]);
        }
        return $this->back('/admin/users/rollen', 'success', __('Rolle „{name}“ gespeichert.', ['name' => $name]));
    }

    public function delete(Request $r, string $key): Response
    {
        $this->auth($r, 'users.manage');
        $role = Permissions::role($key) ?? throw new HttpException(404);
        if ($role['builtin'] || isset(Permissions::defaults()[$key])) {   // Standardrollen nie löschen (auch bei falsch gesetztem Kennzeichen)
            return $this->back('/admin/users/rollen', 'error', __('Diese Rolle kann nicht gelöscht werden.'));
        }
        $used = (int) app()->db->fetchValue('SELECT COUNT(*) FROM users WHERE role = ?', [$key]);
        if ($used) {
            return $this->back('/admin/users/rollen', 'error', __('Die Rolle ist noch {n} Benutzer(n) zugewiesen.', ['n' => $used]));
        }
        app()->db->query('DELETE FROM roles WHERE rkey = ?', [$key]);
        return $this->back('/admin/users/rollen', 'success', __('Rolle gelöscht.'));
    }

    /** Rolle eines Benutzers ändern */
    public function assign(Request $r, string $id): Response
    {
        $me = $this->auth($r, 'users.manage');
        $role = Permissions::role($r->str('role')) ?? throw new HttpException(422, __('Unbekannte Rolle.'));
        // Netzwerk-Administration: weder vergeben noch entziehen (zentral, Core\Network)
        if ($role['key'] === 'network' || UserController::isNetworkAccount((int) $id)) {
            return $this->back('/admin/users/personen', 'error', __('Netzwerk-Konten werden zentral in der Netzwerk-Verwaltung verwaltet.'));
        }
        if ((int) $id === (int) $me['id'] && $role['key'] !== 'admin' && $me['role'] === 'admin'
            && !(int) app()->db->fetchValue("SELECT COUNT(*) FROM users WHERE role = 'admin' AND id != ?", [(int) $id])) {
            return $this->back('/admin/users/personen', 'error', __('Es muss mindestens ein Administrationskonto bestehen bleiben.'));
        }
        $old = UserController::roleOf((int) $id);
        // Nur Rollen vergeben bzw. Konten ändern, deren Rechte man selbst hat (sonst: Selbst-Beförderung zur Administration)
        if ($old === '' || !UserController::mayManageRole($role['key']) || !UserController::mayManageRole($old)) {
            return $this->back('/admin/users/personen', 'error', __('Diese Rolle bzw. dieses Konto hat Rechte, die Ihre eigene Rolle nicht hat – nur die Administration kann das ändern.'));
        }
        app()->db->update('users', ['role' => $role['key']], 'id = :id', ['id' => (int) $id]);
        if ($old !== $role['key']) \Core\Extensions::emit(new \Core\Events\UserRoleChanged((int) $id, $role['key'], $old));   // Erweiterungen
        return $this->back('/admin/users/personen', 'success', __('Rolle geändert.'));
    }

    /** Zusatzrollen eines Kontos (users.roles_extra): Rechte addieren sich zur Hauptrolle – nur Rollen, die man selbst vergeben darf */
    public function extra(Request $r, string $id): Response
    {
        $this->auth($r, 'users.manage');
        if (UserController::isNetworkAccount((int) $id)) {
            return $this->back('/admin/users/personen', 'error', __('Netzwerk-Konten werden zentral in der Netzwerk-Verwaltung verwaltet.'));
        }
        $main = UserController::roleOf((int) $id);
        if ($main === '' || !UserController::mayManageRole($main)) {
            return $this->back('/admin/users/personen', 'error', __('Diese Rolle bzw. dieses Konto hat Rechte, die Ihre eigene Rolle nicht hat – nur die Administration kann das ändern.'));
        }
        $keys = [];
        foreach ((array) ($r->post['extra'] ?? []) as $k) {
            $k = (string) $k;
            if ($k === 'network' || $k === $main || !Permissions::role($k)) continue;
            if (!UserController::mayManageRole($k)) {
                return $this->back('/admin/users/personen', 'error', __('Diese Rolle bzw. dieses Konto hat Rechte, die Ihre eigene Rolle nicht hat – nur die Administration kann das ändern.'));
            }
            $keys[] = $k;
        }
        app()->db->update('users', ['roles_extra' => $keys ? json_encode(array_values(array_unique($keys))) : null], 'id = :id', ['id' => (int) $id]);
        return $this->back('/admin/users/personen', 'success', $keys ? __('Zusatzrollen gespeichert.') : __('Zusatzrollen entfernt.'));
    }
}
