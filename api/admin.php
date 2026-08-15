<?php
/**
 * Panel de administración · un solo endpoint con acciones.
 *
 * GET  ?action=estado                     → invitados, cuentas y resumen
 * POST { action:'invitar', emails, note, plan }
 * POST { action:'quitar_invitacion', id }
 * POST { action:'licencia', user_id, plan, until }
 *
 * Todas exigen sesión iniciada con una cuenta que tenga is_admin = 1.
 */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

$admin = require_admin();

const PLANES = ['tester', 'rookie', 'amateur', 'pro', 'club-amateur', 'club-pro', 'suspendido'];

$action = $_SERVER['REQUEST_METHOD'] === 'GET'
    ? (string) ($_GET['action'] ?? 'estado')
    : param('action');

switch ($action) {

    // ── Lectura ────────────────────────────────────────────────────
    case 'estado':
        $invitados = db()->query(
            'SELECT a.id, a.email, a.note, a.plan, a.registered_at, a.created_at,
                    u.id AS user_id
               FROM allowed_emails a
               LEFT JOIN users u ON u.email = a.email
              ORDER BY a.created_at DESC'
        )->fetchAll();

        $cuentas = db()->query(
            "SELECT u.id, u.email, u.name, u.account_type, u.role, u.plan, u.plan_until,
                    u.is_admin, u.created_at, u.last_login_at,
                    (u.google_sub IS NOT NULL) AS con_google,
                    (SELECT COUNT(*) FROM teams t WHERE t.owner_user_id = u.id) AS equipos
               FROM users u
              ORDER BY u.created_at DESC"
        )->fetchAll();

        $tarifas = db()->query(
            'SELECT id, track, name, price_m, price_y, teams, players, staff, best, active, sort
               FROM plans ORDER BY track, sort'
        )->fetchAll();

        json_out([
            'ok'        => true,
            'admin'     => ['email' => $admin['email'], 'name' => $admin['name']],
            'planes'    => PLANES,
            'ajustes'   => [
                'registro_abierto' => setting('registro_abierto', '0') === '1',
                'precios_publicos' => setting('precios_publicos', '0') === '1',
            ],
            'tarifas'   => $tarifas,
            'invitados' => $invitados,
            'cuentas'   => $cuentas,
            'resumen'   => [
                'invitados'  => count($invitados),
                'pendientes' => count(array_filter($invitados, fn($i) => $i['registered_at'] === null)),
                'cuentas'    => count($cuentas),
                'activas'    => count(array_filter($cuentas, fn($c) => $c['plan'] !== 'suspendido')),
            ],
        ]);
        // no cae

    // ── Invitar ────────────────────────────────────────────────────
    case 'invitar':
        $raw  = param('emails');
        $note = mb_substr(param('note'), 0, 160);
        $plan = param('plan', 'tester');

        if (!in_array($plan, PLANES, true)) {
            fail('Ese plan no existe.');
        }

        // Admite pegar una lista: separada por comas, espacios o saltos.
        $trozos = preg_split('/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $ok = [];
        $mal = [];
        $ya  = [];

        $ins = db()->prepare(
            'INSERT INTO allowed_emails (email, note, plan, invited_by)
             VALUES (?, ?, ?, ?)'
        );

        foreach ($trozos as $t) {
            $mail = mb_strtolower(trim($t));
            if (!valid_email($mail)) { $mal[] = $t; continue; }
            if (invitation_for($mail)) { $ya[] = $mail; continue; }
            try {
                $ins->execute([$mail, $note, $plan, $admin['id']]);
                $ok[] = $mail;
            } catch (Throwable $e) {
                $mal[] = $mail;
            }
        }

        json_out(['ok' => true, 'invitados' => $ok, 'repetidos' => $ya, 'invalidos' => $mal]);

    // ── Quitar invitación ──────────────────────────────────────────
    case 'quitar_invitacion':
        $id = (int) (body()['id'] ?? 0);
        if ($id <= 0) {
            fail('Falta el identificador.');
        }
        $st = db()->prepare('DELETE FROM allowed_emails WHERE id = ?');
        $st->execute([$id]);

        json_out(['ok' => true, 'borradas' => $st->rowCount()]);

    // ── Licencia de una cuenta ─────────────────────────────────────
    case 'licencia':
        $userId = (int) (body()['user_id'] ?? 0);
        $plan   = param('plan');
        $until  = param('until');

        if ($userId <= 0) {
            fail('Falta la cuenta.');
        }
        if (!in_array($plan, PLANES, true)) {
            fail('Ese plan no existe.');
        }
        if ($until !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $until)) {
            fail('La fecha debe ser AAAA-MM-DD.');
        }
        if ($userId === (int) $admin['id'] && $plan === 'suspendido') {
            fail('No puedes suspender tu propia cuenta de administrador.', 409);
        }

        $st = db()->prepare('UPDATE users SET plan = ?, plan_until = ? WHERE id = ?');
        $st->execute([$plan, $until !== '' ? $until : null, $userId]);

        // Suspender echa también las sesiones recordadas.
        if ($plan === 'suspendido') {
            $rm = db()->prepare('DELETE FROM remember_tokens WHERE user_id = ?');
            $rm->execute([$userId]);
        }

        json_out(['ok' => true]);

    // ── Interruptores generales ────────────────────────────────────
    case 'ajuste':
        $clave = param('clave');
        $valor = !empty(body()['valor']) ? '1' : '0';

        if (!in_array($clave, ['registro_abierto', 'precios_publicos'], true)) {
            fail('Ese ajuste no existe.');
        }
        set_setting($clave, $valor);

        json_out(['ok' => true, 'clave' => $clave, 'valor' => $valor === '1']);

    // ── Tarifa de un plan ──────────────────────────────────────────
    case 'tarifa':
        $id     = param('id');
        $activo = !empty(body()['active']) ? 1 : 0;

        $st = db()->prepare('SELECT id FROM plans WHERE id = ?');
        $st->execute([$id]);
        if (!$st->fetch()) {
            fail('Ese plan no existe.', 404);
        }

        // Vacío significa «por determinar», que no es lo mismo que cero.
        $precio = function (string $campo): ?float {
            $v = body()[$campo] ?? '';
            if ($v === '' || $v === null) {
                return null;
            }
            $v = (float) str_replace(',', '.', (string) $v);
            return $v >= 0 ? round($v, 2) : null;
        };

        $m = $precio('price_m');
        $y = $precio('price_y');

        $up = db()->prepare(
            'UPDATE plans SET price_m = ?, price_y = ?, active = ? WHERE id = ?'
        );
        $up->execute([$m, $y, $activo, $id]);

        json_out(['ok' => true]);

    default:
        fail('Acción desconocida.', 400);
}
