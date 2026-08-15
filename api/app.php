<?php
/**
 * API de la aplicación · lo que consumen el panel y el gestor de equipos.
 *
 * GET  ?action=estado                          → cuenta, licencia, club y equipos
 * POST { action:'crear_equipo', name, category, modality, formation, tint }
 * POST { action:'crear_jugador', team_id, name, dorsal, position }
 *
 * Los límites del plan se comprueban AQUÍ. Lo que hace la pantalla es
 * avisar antes de tiempo; lo que impide crear de más es el servidor.
 */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

$user   = require_user();
$userId = (int) $user['id'];
$limits = plan_limits((string) $user['plan']);

$action = $_SERVER['REQUEST_METHOD'] === 'GET'
    ? (string) ($_GET['action'] ?? 'estado')
    : param('action');

switch ($action) {

    // ── Estado general ─────────────────────────────────────────────
    case 'estado':
        $club = db()->prepare('SELECT id, name, city FROM clubs WHERE owner_user_id = ? LIMIT 1');
        $club->execute([$userId]);
        $club = $club->fetch() ?: null;

        $st = db()->prepare(
            'SELECT t.id, t.name, t.category, t.modality, t.formation, t.tint, t.club_id,
                    (SELECT COUNT(*) FROM players p WHERE p.team_id = t.id AND p.active = 1) AS players
               FROM teams t
              WHERE t.owner_user_id = ?
                 OR t.club_id IN (SELECT id FROM clubs WHERE owner_user_id = ?)
              ORDER BY t.id'
        );
        $st->execute([$userId, $userId]);
        $teams = $st->fetchAll();

        foreach ($teams as &$t) {
            $t['id']      = (int) $t['id'];
            $t['players'] = (int) $t['players'];
        }
        unset($t);

        json_out([
            'ok'   => true,
            'user' => [
                'id'    => $userId,
                'name'  => $user['name'],
                'email' => $user['email'],
                'type'  => $user['account_type'],
                'role'  => $user['role'],
                'admin' => (bool) $user['is_admin'],
            ],
            'licencia' => [
                'plan'       => $limits['plan'],
                'nombre'     => $limits['name'],
                'hasta'      => $user['plan_until'],
                'max_equipos'   => $limits['teams'],
                'max_jugadores' => $limits['players'],
                'max_staff'     => $limits['staff'],
                'equipos_usados' => count($teams),
                'puede_crear_equipo' => $limits['teams'] === null || count($teams) < $limits['teams'],
            ],
            'club'  => $club,
            'teams' => $teams,
        ]);

    // ── Un equipo con su plantilla ─────────────────────────────────
    case 'equipo':
        $teamId = (int) ($_GET['id'] ?? 0);

        $st = db()->prepare(
            'SELECT t.*, c.name AS club_name
               FROM teams t
               LEFT JOIN clubs c ON c.id = t.club_id
              WHERE t.id = ? AND (t.owner_user_id = ?
                    OR t.club_id IN (SELECT id FROM clubs WHERE owner_user_id = ?))'
        );
        $st->execute([$teamId, $userId, $userId]);
        $team = $st->fetch();

        if (!$team) {
            fail('Ese equipo no existe o no es tuyo.', 404);
        }

        $p = db()->prepare(
            'SELECT id, name, dorsal, position, access_code
               FROM players WHERE team_id = ? AND active = 1
              ORDER BY (dorsal IS NULL), dorsal, name'
        );
        $p->execute([$teamId]);

        json_out([
            'ok'      => true,
            'team'    => $team,
            'players' => $p->fetchAll(),
            'licencia'=> [
                'max_jugadores' => $limits['players'],
                'nombre'        => $limits['name'],
            ],
        ]);

    // ── Crear equipo ───────────────────────────────────────────────
    case 'crear_equipo':
        $name = param('name');
        if ($name === '') {
            fail('El equipo necesita un nombre.');
        }

        $usados = team_count($userId);
        if ($limits['teams'] !== null && $usados >= $limits['teams']) {
            json_out([
                'ok'    => false,
                'error' => sprintf(
                    'Tu plan %s permite %d equipo%s y ya %s. Cambia de plan para añadir más.',
                    $limits['name'], $limits['teams'],
                    $limits['teams'] === 1 ? '' : 's',
                    $limits['teams'] === 1 ? 'tienes uno' : 'los tienes todos'
                ),
                'limite' => 'equipos',
            ], 409);
        }

        $clubId = null;
        if ($user['account_type'] === 'club') {
            $c = db()->prepare('SELECT id FROM clubs WHERE owner_user_id = ? LIMIT 1');
            $c->execute([$userId]);
            $clubId = ($c->fetch()['id'] ?? null);
        }

        $ins = db()->prepare(
            'INSERT INTO teams (club_id, owner_user_id, name, category, modality, formation, tint)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $ins->execute([
            $clubId, $userId, $name,
            param('category'),
            param('modality', 'Fútbol 11'),
            param('formation', '1-4-3-3'),
            preg_match('/^#[0-9a-f]{6}$/i', param('tint')) ? param('tint') : '#9184d9',
        ]);

        json_out(['ok' => true, 'id' => (int) db()->lastInsertId()], 201);

    // ── Añadir jugador ─────────────────────────────────────────────
    case 'crear_jugador':
        $teamId = (int) (body()['team_id'] ?? 0);
        $name   = param('name');

        if ($name === '') {
            fail('El jugador necesita un nombre.');
        }

        $t = db()->prepare(
            'SELECT id FROM teams
              WHERE id = ? AND (owner_user_id = ?
                    OR club_id IN (SELECT id FROM clubs WHERE owner_user_id = ?))'
        );
        $t->execute([$teamId, $userId, $userId]);
        if (!$t->fetch()) {
            fail('Ese equipo no es tuyo.', 403);
        }

        $c = db()->prepare('SELECT COUNT(*) AS n FROM players WHERE team_id = ? AND active = 1');
        $c->execute([$teamId]);
        $usados = (int) ($c->fetch()['n'] ?? 0);

        if ($limits['players'] !== null && $usados >= $limits['players']) {
            json_out([
                'ok'    => false,
                'error' => sprintf(
                    'Tu plan %s permite %d jugadores por equipo y este ya los tiene.',
                    $limits['name'], $limits['players']
                ),
                'limite' => 'jugadores',
            ], 409);
        }

        // Código de acceso del jugador: único y fácil de dictar.
        $alfabeto = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';   // sin I, O, 0, 1
        do {
            $code = '';
            for ($i = 0; $i < 7; $i++) {
                $code .= $alfabeto[random_int(0, strlen($alfabeto) - 1)];
                if ($i === 2) { $code .= '-'; }
            }
            $q = db()->prepare('SELECT id FROM players WHERE access_code = ?');
            $q->execute([$code]);
        } while ($q->fetch());

        $dorsal = body()['dorsal'] ?? null;
        $dorsal = ($dorsal === '' || $dorsal === null) ? null : (int) $dorsal;

        $ins = db()->prepare(
            'INSERT INTO players (team_id, name, dorsal, position, access_code)
             VALUES (?, ?, ?, ?, ?)'
        );
        $ins->execute([$teamId, $name, $dorsal, param('position'), $code]);

        json_out(['ok' => true, 'id' => (int) db()->lastInsertId(), 'code' => $code], 201);

    default:
        fail('Acción desconocida.', 400);
}
