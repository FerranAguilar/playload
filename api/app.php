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
        // Lo primero, atar lo que el club dejó invitado a este correo:
        // si no, la persona entraría y no vería sus equipos.
        vincular_staff($userId, (string) $user['email']);

        $club = db()->prepare('SELECT id, name, city FROM clubs WHERE owner_user_id = ? LIMIT 1');
        $club->execute([$userId]);
        $club = $club->fetch() ?: null;

        $st = db()->prepare(
            "SELECT t.id, t.name, t.category, t.modality, t.formation, t.tint, t.club_id,
                    (SELECT COUNT(*) FROM players p WHERE p.team_id = t.id AND p.active = 1) AS players,
                    -- Mismo orden que acceso_equipo(): el club manda sobre
                    -- la propiedad, porque los equipos del club los creó él.
                    CASE WHEN c.owner_user_id = ? THEN 'club'
                         WHEN t.owner_user_id = ? THEN 'propietario'
                         ELSE 'staff' END AS acceso
               FROM teams t
               LEFT JOIN clubs c ON c.id = t.club_id
              WHERE t.owner_user_id = ?
                 OR c.owner_user_id = ?
                 OR t.id IN (SELECT team_id FROM team_staff
                              WHERE user_id = ? AND status = 'activo')
              ORDER BY t.id"
        );

        try {
            $st->execute([$userId, $userId, $userId, $userId, $userId]);
            $teams = $st->fetchAll();
        } catch (Throwable $e) {
            // Sin migración 05: la lista de siempre, sin equipos de staff.
            $st = db()->prepare(
                "SELECT t.id, t.name, t.category, t.modality, t.formation, t.tint, t.club_id,
                        (SELECT COUNT(*) FROM players p WHERE p.team_id = t.id AND p.active = 1) AS players,
                        CASE WHEN t.owner_user_id = ? THEN 'propietario' ELSE 'club' END AS acceso
                   FROM teams t
                  WHERE t.owner_user_id = ?
                     OR t.club_id IN (SELECT id FROM clubs WHERE owner_user_id = ?)
                  ORDER BY t.id"
            );
            $st->execute([$userId, $userId, $userId]);
            $teams = $st->fetchAll();
        }

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
                // Las preferencias viajan con el estado para que
                // cualquier pantalla pueda sincronizarse con la cuenta.
                'locale' => $user['locale'] ?? 'es',
                'theme'  => $user['theme']  ?? 'sistema',
            ],
            'licencia' => [
                'plan'       => $limits['plan'],
                'nombre'     => $limits['name'],
                'hasta'      => $user['plan_until'],
                'max_equipos'   => $limits['teams'],
                'max_jugadores' => $limits['players'],
                'max_staff'     => $limits['staff'],
                // Los equipos que gastan licencia son los suyos, no los que
                // le ha dado un club: por eso se cuenta con team_count() y
                // no con el tamaño de la lista de arriba.
                'equipos_usados' => team_count($userId),
                'puede_crear_equipo' => $limits['teams'] === null
                    || team_count($userId) < $limits['teams'],
                'licencias_staff' => $club ? staff_count((int) $club['id']) : 0,
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

    // ── Panel del club: equipos con su staff ───────────────────────
    case 'club':
        $club = mi_club($userId);
        if (!$club) {
            fail('Esta cuenta no lleva ningún club.', 403);
        }
        $clubId = (int) $club['id'];

        $st = db()->prepare(
            'SELECT t.id, t.name, t.category, t.modality, t.tint,
                    (SELECT COUNT(*) FROM players p WHERE p.team_id = t.id AND p.active = 1) AS players
               FROM teams t WHERE t.club_id = ? ORDER BY t.name, t.id'
        );
        $st->execute([$clubId]);
        $teams = $st->fetchAll();

        $porEquipo = [];
        try {
            $s = db()->prepare(
                'SELECT s.id, s.team_id, s.email, s.name, s.role, s.status, s.invited_at,
                        u.name AS user_name
                   FROM team_staff s
                   LEFT JOIN users u ON u.id = s.user_id
                  WHERE s.club_id = ? ORDER BY s.invited_at'
            );
            $s->execute([$clubId]);
            foreach ($s->fetchAll() as $row) {
                $row['id']      = (int) $row['id'];
                $row['team_id'] = (int) $row['team_id'];
                // El nombre de la cuenta manda sobre el que escribió el
                // club: si la persona ya entró, es como se llama de verdad.
                $row['name'] = $row['user_name'] ?: $row['name'];
                unset($row['user_name']);
                $porEquipo[$row['team_id']][] = $row;
            }
        } catch (Throwable $e) {
            fail('Falta la migración 05: importa db/migracion-05-staff.sql.', 500);
        }

        $personas = [];
        foreach ($teams as &$t) {
            $t['id']      = (int) $t['id'];
            $t['players'] = (int) $t['players'];
            $t['staff']   = $porEquipo[$t['id']] ?? [];
            foreach ($t['staff'] as $m) {
                $personas[$m['email']] = true;
            }
        }
        unset($t);

        $usadas = staff_count($clubId);

        // ── El día de hoy, de todos los equipos a la vez ────────────
        // Es lo que el club quiere saber al entrar: quién entrena, a qué
        // hora y dónde, sin ir categoría por categoría.
        $h = db()->prepare(
            'SELECT s.id, s.team_id, t.name AS team, t.tint, s.time, s.title, s.kind,
                    s.md_label, s.place, s.duration_min, s.planned_load, s.actual_load, s.status
               FROM sessions s
               JOIN teams t ON t.id = s.team_id
              WHERE t.club_id = ? AND s.date = CURDATE()
              ORDER BY (s.time IS NULL), s.time, t.name'
        );
        $h->execute([$clubId]);
        $hoy = $h->fetchAll();
        foreach ($hoy as &$e) {
            $e['team_id'] = (int) $e['team_id'];
        }
        unset($e);

        // ── Avisos que ha dejado el staff ──────────────────────────
        $mensajes = [];
        $sinLeer  = 0;
        try {
            $m = db()->prepare(
                'SELECT m.id, m.team_id, t.name AS team, t.tint, m.author, m.kind,
                        m.body, m.created_at, m.read_at
                   FROM club_messages m
                   JOIN teams t ON t.id = m.team_id
                  WHERE m.club_id = ?
                  ORDER BY (m.read_at IS NOT NULL), m.created_at DESC
                  LIMIT 30'
            );
            $m->execute([$clubId]);
            $mensajes = $m->fetchAll();
            foreach ($mensajes as &$x) {
                $x['id']      = (int) $x['id'];
                $x['team_id'] = (int) $x['team_id'];
                if ($x['read_at'] === null) {
                    $sinLeer++;
                }
            }
            unset($x);
        } catch (Throwable $e) {
            // Sin la migración 08 todavía no hay avisos: el panel enseña
            // el resto sin romperse.
        }

        json_out([
            'ok'    => true,
            'club'  => $club,
            'teams' => $teams,
            'hoy'      => $hoy,
            'mensajes' => $mensajes,
            'sin_leer' => $sinLeer,
            'resumen' => [
                'equipos'   => count($teams),
                'personas'  => count($personas),
                'jugadores' => array_sum(array_column($teams, 'players')),
            ],
            'licencia' => [
                'nombre'      => $limits['name'],
                'hasta'       => $user['plan_until'],
                'max_staff'   => $limits['staff'],
                'usadas'      => $usadas,
                'puede_mas'   => $limits['staff'] === null || $usadas < $limits['staff'],
                'max_equipos' => $limits['teams'],
            ],
        ]);

    // ── El staff deja un aviso al club ─────────────────────────────
    case 'crear_mensaje':
        $teamId = (int) (body()['team_id'] ?? 0);
        // Lo escribe quien trabaja en el equipo, no el club: el club es
        // quien lo lee.
        exige_acceso($teamId, $userId, ['propietario', 'staff']);

        $body = mb_substr(param('body'), 0, 600);
        if (trim($body) === '') {
            fail('El aviso está vacío.');
        }

        $kind = param('kind', 'aviso');
        if (!in_array($kind, ['aviso', 'incidencia', 'material'], true)) {
            $kind = 'aviso';
        }

        $t = db()->prepare('SELECT club_id FROM teams WHERE id = ?');
        $t->execute([$teamId]);
        $clubId = (int) ($t->fetch()['club_id'] ?? 0);

        if (!$clubId) {
            fail('Este equipo no es de ningún club, así que no hay a quién avisar.', 409);
        }

        try {
            $ins = db()->prepare(
                'INSERT INTO club_messages (club_id, team_id, user_id, author, kind, body)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $ins->execute([$clubId, $teamId, $userId, (string) $user['name'], $kind, $body]);
        } catch (Throwable $e) {
            fail('Falta la migración 08: importa db/migracion-08-avisos.sql.', 500);
        }

        json_out(['ok' => true, 'id' => (int) db()->lastInsertId()], 201);

    // ── El club da un aviso por leído ──────────────────────────────
    case 'leer_mensaje':
        $club = mi_club($userId);
        if (!$club) {
            fail('Solo el club marca sus avisos.', 403);
        }

        $id = (int) (body()['id'] ?? 0);
        $up = db()->prepare(
            'UPDATE club_messages SET read_at = NOW()
              WHERE id = ? AND club_id = ? AND read_at IS NULL'
        );
        $up->execute([$id, (int) $club['id']]);

        json_out(['ok' => true]);

    // ── Datos del club ─────────────────────────────────────────────
    case 'editar_club':
        $club = mi_club($userId);
        if (!$club) {
            fail('Esta cuenta no lleva ningún club.', 403);
        }

        $name = param('name');
        if ($name === '') {
            fail('El club necesita un nombre.');
        }

        $up = db()->prepare('UPDATE clubs SET name = ?, city = ? WHERE id = ?');
        $up->execute([$name, param('city'), (int) $club['id']]);

        json_out(['ok' => true]);

    // ── Dar acceso a alguien, por correo ───────────────────────────
    case 'invitar_staff':
        $club = mi_club($userId);
        if (!$club) {
            fail('Solo una cuenta de club reparte accesos.', 403);
        }
        $clubId = (int) $club['id'];
        $teamId = (int) (body()['team_id'] ?? 0);

        $t = db()->prepare('SELECT id FROM teams WHERE id = ? AND club_id = ?');
        $t->execute([$teamId, $clubId]);
        if (!$t->fetch()) {
            fail('Ese equipo no es de tu club.', 403);
        }

        $email = strtolower(param('email'));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            fail('Escribe un correo válido.');
        }

        // El límite se mira antes de insertar, y cuenta parejas
        // persona-equipo: es lo que el club paga.
        $usadas = staff_count($clubId);
        if ($limits['staff'] !== null && $usadas >= $limits['staff']) {
            json_out([
                'ok'    => false,
                'error' => sprintf(
                    'Tu plan %s incluye %d licencias de staff y ya están todas dadas. '
                  . 'Quita a alguien de un equipo o cambia de plan.',
                    $limits['name'], $limits['staff']
                ),
                'limite' => 'staff',
            ], 409);
        }

        // Si esa persona ya tiene cuenta, entra activa y ve el equipo en
        // cuanto recargue. Si no, la fila espera a que se dé de alta.
        $u = db()->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $u->execute([$email]);
        $existente = $u->fetch();

        try {
            $ins = db()->prepare(
                'INSERT INTO team_staff (club_id, team_id, email, user_id, name, role, status, linked_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $ins->execute([
                $clubId, $teamId, $email,
                $existente ? (int) $existente['id'] : null,
                param('name'),
                param('role', 'Entrenador'),
                $existente ? 'activo' : 'invitado',
                $existente ? date('Y-m-d H:i:s') : null,
            ]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                fail('Esa persona ya está en este equipo.', 409);
            }
            throw $e;
        }

        json_out([
            'ok' => true,
            'id' => (int) db()->lastInsertId(),
            // No se llama `status` a propósito: el navegador mete ahí el
            // código HTTP y una clave repetida se pisaría sola.
            'estado' => $existente ? 'activo' : 'invitado',
        ], 201);

    // ── Quitar a alguien de un equipo ──────────────────────────────
    case 'quitar_staff':
        $club = mi_club($userId);
        if (!$club) {
            fail('Solo una cuenta de club reparte accesos.', 403);
        }

        $id = (int) (body()['staff_id'] ?? 0);
        $del = db()->prepare('DELETE FROM team_staff WHERE id = ? AND club_id = ?');
        $del->execute([$id, (int) $club['id']]);

        if (!$del->rowCount()) {
            fail('Ese acceso no existe o no es de tu club.', 404);
        }
        json_out(['ok' => true]);

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
                // Con cero equipos la frase de siempre quedaba absurda
                // («permite 0 equipos y ya los tienes todos»), y encima
                // se callaba lo único que esa persona necesita saber.
                'error' => $limits['teams'] === 0
                    ? 'Tu acceso viene de un club: puedes trabajar en sus equipos, pero para '
                    . 'crear los tuyos necesitas una licencia propia. Los del club no te contarían.'
                    : sprintf(
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

        // La plantilla la lleva el club; el staff del equipo planifica.
        exige_acceso($teamId, $userId, ['propietario', 'club']);

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

    // ── Editar equipo ──────────────────────────────────────────────
    case 'editar_equipo':
        $teamId = (int) (body()['team_id'] ?? 0);
        $name   = param('name');

        if ($name === '') {
            fail('El equipo necesita un nombre.');
        }
        exige_acceso($teamId, $userId, ['propietario', 'club']);

        $form = param('formation', '1-4-3-3');
        if (!preg_match('/^\d(-\d{1,2}){2,3}$/', $form)) {
            fail('El sistema se escribe como 1-4-3-3.');
        }

        $up = db()->prepare(
            'UPDATE teams SET name = ?, category = ?, modality = ?, formation = ?, tint = ?
              WHERE id = ?'
        );
        $up->execute([
            $name,
            param('category'),
            param('modality', 'Fútbol 11'),
            $form,
            preg_match('/^#[0-9a-f]{6}$/i', param('tint')) ? param('tint') : '#9184d9',
            $teamId,
        ]);

        json_out(['ok' => true]);

    // ── Sesiones de un equipo ──────────────────────────────────────
    case 'sesiones':
        $teamId = (int) ($_GET['team_id'] ?? 0);
        if (!es_mi_equipo($teamId, $userId)) {
            fail('Ese equipo no es tuyo.', 403);
        }

        $campos = 'id, date, time, title, kind, md_label, place, duration_min,
                   planned_rpe, planned_load, actual_rpe, actual_load, status';

        // El calendario pide el tramo que tiene en pantalla. Sin tramo se
        // responde lo de siempre: de hace cuatro semanas en adelante.
        $desde = (string) ($_GET['desde'] ?? '');
        $hasta = (string) ($_GET['hasta'] ?? '');
        $dia   = '/^\d{4}-\d{2}-\d{2}$/';

        if (preg_match($dia, $desde) && preg_match($dia, $hasta)) {
            $st = db()->prepare(
                "SELECT $campos FROM sessions
                  WHERE team_id = ? AND date BETWEEN ? AND ?
                  ORDER BY date, time"
            );
            $st->execute([$teamId, $desde, $hasta]);
        } else {
            $st = db()->prepare(
                "SELECT $campos FROM sessions
                  WHERE team_id = ? AND date >= (CURDATE() - INTERVAL 28 DAY)
                  ORDER BY date, time"
            );
            $st->execute([$teamId]);
        }

        json_out(['ok' => true, 'sesiones' => $st->fetchAll()]);

    // ── Crear sesión ───────────────────────────────────────────────
    case 'crear_sesion':
        $teamId = (int) (body()['team_id'] ?? 0);
        // Planificar es del staff del equipo. El club lo ve, no lo toca.
        exige_acceso($teamId, $userId, ['propietario', 'staff']);

        $date = param('date');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            fail('La fecha debe ser AAAA-MM-DD.');
        }

        $kind = param('kind', 'entrenamiento');
        if (!in_array($kind, ['entrenamiento', 'partido', 'recuperacion', 'descanso'], true)) {
            $kind = 'entrenamiento';
        }

        $dur = max(0, min(240, (int) (body()['duration_min'] ?? 90)));
        $rpe = body()['planned_rpe'] ?? null;
        $rpe = ($rpe === '' || $rpe === null) ? null : max(1, min(10, (int) $rpe));

        $ins = db()->prepare(
            'INSERT INTO sessions
                (team_id, date, time, title, kind, md_label, place,
                 duration_min, planned_rpe, planned_load)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $ins->execute([
            $teamId, $date,
            param('time') !== '' ? param('time') : null,
            param('title'), $kind, param('md_label'), param('place'),
            $dur, $rpe,
            $rpe !== null ? $rpe * $dur : null,
        ]);

        json_out(['ok' => true, 'id' => (int) db()->lastInsertId()], 201);

    // ── Cerrar sesión: cuánto costó de verdad ──────────────────────
    case 'cerrar_sesion':
        $id = (int) (body()['session_id'] ?? 0);

        $st = db()->prepare(
            'SELECT s.id, s.duration_min, s.team_id FROM sessions s WHERE s.id = ?'
        );
        $st->execute([$id]);
        $ses = $st->fetch();

        if (!$ses) {
            fail('Esa sesión no es tuya.', 403);
        }
        exige_acceso((int) $ses['team_id'], $userId, ['propietario', 'staff']);

        $rpe = (float) str_replace(',', '.', (string) (body()['actual_rpe'] ?? 0));
        if ($rpe < 1 || $rpe > 10) {
            fail('El RPE va de 1 a 10.');
        }
        $dur = max(0, min(240, (int) (body()['duration_min'] ?? $ses['duration_min'])));

        $up = db()->prepare(
            "UPDATE sessions SET actual_rpe = ?, duration_min = ?, actual_load = ?,
                    status = 'realizada' WHERE id = ?"
        );
        $up->execute([$rpe, $dur, (int) round($rpe * $dur), $id]);

        json_out(['ok' => true, 'carga' => (int) round($rpe * $dur)]);

    // ── RPE de una sesión, jugador a jugador ───────────────────────
    // La plantilla entera con lo que ya haya contestado cada uno, para
    // que la pantalla enseñe la lista completa y no solo a los que
    // faltan: el entrenador la repasa de arriba abajo.
    case 'rpe_sesion':
        $id = (int) ($_GET['session_id'] ?? 0);

        $st = db()->prepare(
            'SELECT id, team_id, title, date, duration_min, status, actual_rpe, actual_load
               FROM sessions WHERE id = ?'
        );
        $st->execute([$id]);
        $ses = $st->fetch();

        if (!$ses || !es_mi_equipo((int) $ses['team_id'], $userId)) {
            fail('Esa sesión no es tuya.', 403);
        }

        $st = db()->prepare(
            'SELECT p.id, p.name, p.dorsal, p.position,
                    r.rpe, r.minutes, r.load_ua
               FROM players p
               LEFT JOIN rpe_entries r ON r.player_id = p.id AND r.session_id = ?
              WHERE p.team_id = ? AND p.active = 1
              ORDER BY p.dorsal IS NULL, p.dorsal, p.name'
        );
        $st->execute([$id, (int) $ses['team_id']]);

        json_out(['ok' => true, 'sesion' => $ses, 'jugadores' => $st->fetchAll()]);

    // ── Guardar el RPE de cada jugador ─────────────────────────────
    // Llega la lista entera de una vez. Un jugador sin RPE no es un
    // cero: es alguien que no entrenó, y su fila se borra para que no
    // le hunda la media ni le cuente carga que no hizo.
    case 'guardar_rpe':
        $id = (int) (body()['session_id'] ?? 0);

        $st = db()->prepare('SELECT id, team_id, duration_min FROM sessions WHERE id = ?');
        $st->execute([$id]);
        $ses = $st->fetch();

        if (!$ses) {
            fail('Esa sesión no es tuya.', 403);
        }
        exige_acceso((int) $ses['team_id'], $userId, ['propietario', 'staff']);

        $entradas = body()['entradas'] ?? [];
        if (!is_array($entradas)) {
            fail('Faltan los RPE.');
        }

        // La duración de la sesión se puede corregir aquí mismo: se
        // apunta lo que duró de verdad, no lo que se había previsto. Es
        // además el valor que usan los jugadores que no tengan minutos
        // propios, así que tiene que quedar guardado.
        $dur = (int) (body()['duration_min'] ?? 0);
        if ($dur > 0) {
            $dur = min(240, $dur);
            $up = db()->prepare('UPDATE sessions SET duration_min = ? WHERE id = ?');
            $up->execute([$dur, $id]);
        } else {
            $dur = (int) $ses['duration_min'];
        }

        // Solo se tocan jugadores de este equipo: el identificador viene
        // del navegador y podría ser el de cualquier otra plantilla.
        $q = db()->prepare('SELECT id FROM players WHERE team_id = ?');
        $q->execute([(int) $ses['team_id']]);
        $suyos = array_map('intval', array_column($q->fetchAll(), 'id'));

        $ins = db()->prepare(
            'INSERT INTO rpe_entries (session_id, player_id, rpe, minutes, load_ua)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE rpe = VALUES(rpe), minutes = VALUES(minutes),
                                     load_ua = VALUES(load_ua)'
        );
        $del = db()->prepare('DELETE FROM rpe_entries WHERE session_id = ? AND player_id = ?');

        foreach ($entradas as $e) {
            $pid = (int) ($e['player_id'] ?? 0);
            if (!in_array($pid, $suyos, true)) {
                continue;
            }

            $rpe = (int) ($e['rpe'] ?? 0);
            if ($rpe < 1 || $rpe > 10) {
                $del->execute([$id, $pid]);
                continue;
            }

            // Sin minutos propios valen los de la sesión: es el caso
            // normal, y solo se separan los que entraron a mitad.
            $min = (int) ($e['minutes'] ?? 0);
            $min = $min > 0 ? min(240, $min) : $dur;
            $ins->execute([$id, $pid, $rpe, $min, $rpe * $min]);
        }

        $r = recalcular_sesion($id);
        json_out(['ok' => true] + $r);

    // ── Wellness de un día ─────────────────────────────────────────
    case 'wellness_dia':
        $teamId = (int) ($_GET['team_id'] ?? 0);
        if (!es_mi_equipo($teamId, $userId)) {
            fail('Ese equipo no es tuyo.', 403);
        }

        $fecha = (string) ($_GET['fecha'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
            $fecha = (new DateTimeImmutable('today'))->format('Y-m-d');
        }

        $st = db()->prepare(
            'SELECT p.id, p.name, p.dorsal, p.position,
                    w.`sleep`, w.fatigue, w.soreness, w.stress, w.score, w.note
               FROM players p
               LEFT JOIN wellness_entries w ON w.player_id = p.id AND w.`date` = ?
              WHERE p.team_id = ? AND p.active = 1
              ORDER BY p.dorsal IS NULL, p.dorsal, p.name'
        );
        $st->execute([$fecha, $teamId]);

        json_out(['ok' => true, 'fecha' => $fecha, 'jugadores' => $st->fetchAll()]);

    // ── Guardar el wellness del día ────────────────────────────────
    // Los cuatro valores van de 1 a 5 y TODOS en el mismo sentido: 5 es
    // lo bueno. Dormí de maravilla, llego fresco, sin agujetas, tranquilo.
    // Mezclar sentidos es el error clásico de estos cuestionarios: la
    // media dejaría de significar nada.
    case 'guardar_wellness':
        $teamId = (int) (body()['team_id'] ?? 0);
        exige_acceso($teamId, $userId, ['propietario', 'staff']);

        $fecha = param('fecha');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
            fail('La fecha debe ser AAAA-MM-DD.');
        }

        $entradas = body()['entradas'] ?? [];
        if (!is_array($entradas)) {
            fail('Falta el wellness.');
        }

        $q = db()->prepare('SELECT id FROM players WHERE team_id = ?');
        $q->execute([$teamId]);
        $suyos = array_map('intval', array_column($q->fetchAll(), 'id'));

        $ins = db()->prepare(
            // `sleep` es también el nombre de una función de MySQL y
            // `date` el de un tipo: entre comillas no hay duda posible.
            // Ya pasó con `load`, y se arregló tarde.
            'INSERT INTO wellness_entries
                (player_id, `date`, `sleep`, fatigue, soreness, stress, score, note)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE `sleep` = VALUES(`sleep`), fatigue = VALUES(fatigue),
                                     soreness = VALUES(soreness), stress = VALUES(stress),
                                     score = VALUES(score), note = VALUES(note)'
        );
        $del = db()->prepare('DELETE FROM wellness_entries WHERE player_id = ? AND `date` = ?');
        $guardados = 0;

        foreach ($entradas as $e) {
            $pid = (int) ($e['player_id'] ?? 0);
            if (!in_array($pid, $suyos, true)) {
                continue;
            }

            $v = [];
            foreach (['sleep', 'fatigue', 'soreness', 'stress'] as $k) {
                $v[$k] = (int) ($e[$k] ?? 0);
            }

            // Sin los cuatro no hay media que valga: quien deje la fila a
            // medias es que no ha contestado, y se borra lo que hubiera.
            if (min($v) < 1 || max($v) > 5) {
                $del->execute([$pid, $fecha]);
                continue;
            }

            $ins->execute([
                $pid, $fecha,
                $v['sleep'], $v['fatigue'], $v['soreness'], $v['stress'],
                round(array_sum($v) / 4, 2),
                mb_substr((string) ($e['note'] ?? ''), 0, 200),
            ]);
            $guardados++;
        }

        json_out(['ok' => true, 'guardados' => $guardados]);

    // ── Control de carga, jugador a jugador ────────────────────────
    // Lo que sostiene la tabla del panel: cuánto lleva cada uno en la
    // semana, cómo se compara con su propio mes y cómo dice que está.
    case 'carga_plantilla':
        $teamId = (int) ($_GET['team_id'] ?? 0);
        if (!es_mi_equipo($teamId, $userId)) {
            fail('Ese equipo no es tuyo.', 403);
        }

        $st = db()->prepare(
            'SELECT id, name, dorsal, position FROM players
              WHERE team_id = ? AND active = 1
              ORDER BY dorsal IS NULL, dorsal, name'
        );
        $st->execute([$teamId]);
        $jugadores = $st->fetchAll();

        // Carga aguda (7 días) y crónica (el mes entre cuatro), por jugador.
        $st = db()->prepare(
            'SELECT r.player_id,
                    COALESCE(SUM(CASE WHEN s.date > (CURDATE() - INTERVAL 7 DAY)
                                      THEN r.load_ua END), 0) AS aguda,
                    COALESCE(SUM(CASE WHEN s.date > (CURDATE() - INTERVAL 28 DAY)
                                      THEN r.load_ua END), 0) AS mes,
                    COUNT(CASE WHEN s.date > (CURDATE() - INTERVAL 7 DAY)
                               THEN 1 END) AS sesiones
               FROM rpe_entries r
               JOIN sessions s ON s.id = r.session_id
              WHERE s.team_id = ?
              GROUP BY r.player_id'
        );
        $st->execute([$teamId]);
        $carga = [];
        foreach ($st->fetchAll() as $f) {
            $carga[(int) $f['player_id']] = $f;
        }

        $st = db()->prepare(
            'SELECT w.player_id,
                    MAX(CASE WHEN w.`date` = CURDATE() THEN w.score END) AS hoy,
                    AVG(CASE WHEN w.`date` > (CURDATE() - INTERVAL 7 DAY)
                             THEN w.score END) AS media
               FROM wellness_entries w
               JOIN players p ON p.id = w.player_id
              WHERE p.team_id = ?
              GROUP BY w.player_id'
        );
        $st->execute([$teamId]);
        $well = [];
        foreach ($st->fetchAll() as $f) {
            $well[(int) $f['player_id']] = $f;
        }

        // Cuántas sesiones cerradas hubo en la semana: es el denominador
        // que dice si a alguien le falta contestar o es que no hubo nada.
        $st = db()->prepare(
            "SELECT COUNT(*) AS n FROM sessions
              WHERE team_id = ? AND status = 'realizada'
                AND date > (CURDATE() - INTERVAL 7 DAY)"
        );
        $st->execute([$teamId]);
        $sesiones = (int) ($st->fetch()['n'] ?? 0);

        $filas = [];
        foreach ($jugadores as $p) {
            $pid = (int) $p['id'];
            $c   = $carga[$pid] ?? null;
            $w   = $well[$pid]  ?? null;

            $aguda   = $c ? (int) $c['aguda'] : 0;
            $cronica = $c ? ((int) $c['mes']) / 4 : 0.0;

            $filas[] = $p + [
                'aguda'    => $aguda,
                'cronica'  => (int) round($cronica),
                // El ACWR solo significa algo con un mes detrás. Con dos
                // sesiones sueltas sale un número enorme que asusta sin
                // motivo, así que hasta entonces se devuelve null.
                'acwr'     => $cronica > 0 && $sesiones > 0
                                ? round($aguda / $cronica, 2) : null,
                'sesiones' => $c ? (int) $c['sesiones'] : 0,
                'wellness' => $w && $w['hoy']   !== null ? round((float) $w['hoy'], 2)   : null,
                'wmedia'   => $w && $w['media'] !== null ? round((float) $w['media'], 2) : null,
            ];
        }

        json_out([
            'ok'        => true,
            'jugadores' => $filas,
            'sesiones'  => $sesiones,
        ]);

    // ── Resumen de carga para el panel ─────────────────────────────
    case 'resumen':
        $teamId = (int) ($_GET['team_id'] ?? 0);
        if ($teamId && !es_mi_equipo($teamId, $userId)) {
            fail('Ese equipo no es tuyo.', 403);
        }

        // Semana en curso, de lunes a domingo.
        $hoy      = new DateTimeImmutable('today');
        $lunes    = $hoy->modify('monday this week');
        $domingo  = $lunes->modify('+6 days');

        // El `id` no es un extra: sin él, el panel no puede cerrar la
        // sesión de hoy ni pedir su RPE, que es justo lo que ofrece.
        $st = db()->prepare(
            'SELECT id, date, kind, md_label, title, time, place, duration_min,
                    planned_rpe, planned_load, actual_rpe, actual_load, status
               FROM sessions
              WHERE team_id = ? AND date BETWEEN ? AND ?
              ORDER BY date, time'
        );
        $st->execute([$teamId, $lunes->format('Y-m-d'), $domingo->format('Y-m-d')]);
        $semana = $st->fetchAll();

        // Carga aguda (7 días) frente a crónica (media de 4 semanas).
        $c = db()->prepare(
            'SELECT
               COALESCE(SUM(CASE WHEN date > (CURDATE() - INTERVAL 7 DAY)
                                 THEN actual_load END), 0) AS aguda,
               COALESCE(SUM(CASE WHEN date > (CURDATE() - INTERVAL 28 DAY)
                                 THEN actual_load END), 0) AS mes
             FROM sessions WHERE team_id = ? AND status = "realizada"'
        );
        $c->execute([$teamId]);
        $carga = $c->fetch();

        $aguda   = (int) $carga['aguda'];
        $cronica = ((int) $carga['mes']) / 4;
        $acwr    = $cronica > 0 ? round($aguda / $cronica, 2) : null;

        // Wellness de hoy.
        $w = db()->prepare(
            'SELECT AVG(w.score) AS media, COUNT(*) AS enviados
               FROM wellness_entries w
               JOIN players p ON p.id = w.player_id
              WHERE p.team_id = ? AND w.date = CURDATE()'
        );
        $w->execute([$teamId]);
        $well = $w->fetch();

        $p = db()->prepare('SELECT COUNT(*) AS n FROM players WHERE team_id = ? AND active = 1');
        $p->execute([$teamId]);
        $plantilla = (int) ($p->fetch()['n'] ?? 0);

        json_out([
            'ok'     => true,
            'desde'  => $lunes->format('Y-m-d'),
            'hasta'  => $domingo->format('Y-m-d'),
            'semana' => $semana,
            'carga'  => [
                'aguda'   => $aguda,
                'cronica' => round($cronica),
                'acwr'    => $acwr,
                'semana_prevista' => array_sum(array_map(fn($s) => (int) $s['planned_load'], $semana)),
                'semana_real'     => array_sum(array_map(fn($s) => (int) $s['actual_load'], $semana)),
            ],
            'wellness' => [
                'media'     => $well['media'] !== null ? round((float) $well['media'], 1) : null,
                'enviados'  => (int) $well['enviados'],
                'plantilla' => $plantilla,
            ],
        ]);

    // ── Perfil completo ────────────────────────────────────────────
    case 'perfil':
        $st = db()->prepare(
            'SELECT COUNT(*) AS equipos,
                    (SELECT COUNT(*) FROM players p
                      JOIN teams t2 ON t2.id = p.team_id
                     WHERE t2.owner_user_id = ? AND p.active = 1) AS jugadores,
                    (SELECT COUNT(*) FROM sessions s
                      JOIN teams t3 ON t3.id = s.team_id
                     WHERE t3.owner_user_id = ?) AS sesiones
               FROM teams WHERE owner_user_id = ?'
        );
        $st->execute([$userId, $userId, $userId]);
        $uso = $st->fetch();

        json_out([
            'ok'   => true,
            'user' => [
                'id'      => $userId,
                'name'    => $user['name'],
                'email'   => $user['email'],
                'type'    => $user['account_type'],
                'role'    => $user['role'],
                'locale'  => $user['locale'] ?? 'es',
                'theme'   => $user['theme'] ?? 'sistema',
                'admin'   => (bool) $user['is_admin'],
                'dos_pasos' => (bool) $user['two_factor'],
                'con_password' => true,
                'alta'    => $user['created_at'] ?? null,
            ],
            'licencia' => [
                'plan'   => $limits['plan'],
                'nombre' => $limits['name'],
                'hasta'  => $user['plan_until'],
                'max_equipos'   => $limits['teams'],
                'max_jugadores' => $limits['players'],
                'max_staff'     => $limits['staff'],
            ],
            'uso' => [
                'equipos'   => (int) $uso['equipos'],
                'jugadores' => (int) $uso['jugadores'],
                'sesiones'  => (int) $uso['sesiones'],
            ],
        ]);

    // ── Guardar preferencias ───────────────────────────────────────
    case 'preferencias':
        $locale = param('locale', 'es');
        $theme  = param('theme', 'sistema');

        if (!in_array($locale, ['es', 'ca', 'en'], true)) {
            fail('Ese idioma no está disponible.');
        }
        if (!in_array($theme, ['sistema', 'claro', 'oscuro'], true)) {
            fail('Ese tema no existe.');
        }

        $up = db()->prepare('UPDATE users SET locale = ?, theme = ? WHERE id = ?');
        $up->execute([$locale, $theme, $userId]);

        json_out(['ok' => true, 'locale' => $locale, 'theme' => $theme]);

    // ── Datos de la cuenta ─────────────────────────────────────────
    case 'guardar_perfil':
        $name = param('name');
        if ($name === '') {
            fail('El nombre no puede quedar vacío.');
        }
        $up = db()->prepare('UPDATE users SET name = ?, role = ? WHERE id = ?');
        $up->execute([$name, param('role'), $userId]);

        json_out(['ok' => true]);

    // ── Cambiar contraseña ─────────────────────────────────────────
    case 'cambiar_password':
        $actual = param('actual');
        $nueva  = param('nueva');

        if (mb_strlen($nueva) < 8) {
            fail('La contraseña nueva necesita ocho caracteres como mínimo.');
        }

        $st = db()->prepare('SELECT password_hash FROM users WHERE id = ?');
        $st->execute([$userId]);
        $hash = $st->fetch()['password_hash'] ?? null;

        if ($hash === null) {
            fail('Tu cuenta entra con Google, así que no tiene contraseña.', 409);
        }
        if (!password_verify($actual, $hash)) {
            fail('La contraseña actual no es correcta.', 401);
        }

        $up = db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $up->execute([password_hash($nueva, PASSWORD_DEFAULT), $userId]);

        // Cambiar la contraseña cierra las sesiones recordadas.
        $rm = db()->prepare('DELETE FROM remember_tokens WHERE user_id = ?');
        $rm->execute([$userId]);

        json_out(['ok' => true]);

    // ── Exportar los datos ─────────────────────────────────────────
    // Sale como CSV para que se abra en cualquier hoja de cálculo.
    case 'exportar':
        $st = db()->prepare(
            'SELECT t.name AS equipo, t.category AS categoria, t.modality AS modalidad,
                    p.dorsal, p.name AS jugador, p.position AS posicion, p.access_code AS codigo
               FROM teams t
               LEFT JOIN players p ON p.team_id = t.id AND p.active = 1
              WHERE t.owner_user_id = ?
                 OR t.club_id IN (SELECT id FROM clubs WHERE owner_user_id = ?)
              ORDER BY t.name, (p.dorsal IS NULL), p.dorsal'
        );
        $st->execute([$userId, $userId]);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="playload-plantillas.csv"');

        $out = fopen('php://output', 'w');
        fprintf($out, "\xEF\xBB\xBF");   // BOM, para que Excel respete las tildes
        fputcsv($out, ['Equipo', 'Categoría', 'Modalidad', 'Dorsal', 'Jugador', 'Posición', 'Código'], ';');
        foreach ($st->fetchAll() as $r) {
            fputcsv($out, array_values($r), ';');
        }
        fclose($out);
        exit;

    // ── Eliminar la cuenta ─────────────────────────────────────────
    case 'eliminar_cuenta':
        // Se pide escribir el correo entero: un botón solo es demasiado
        // fácil de pulsar sin querer, y esto no tiene vuelta atrás.
        if (mb_strtolower(param('confirmacion')) !== mb_strtolower((string) $user['email'])) {
            fail('Escribe tu correo exactamente para confirmar.', 400);
        }

        $del = db()->prepare('DELETE FROM users WHERE id = ?');
        $del->execute([$userId]);

        $_SESSION = [];
        session_destroy();

        json_out(['ok' => true]);

    default:
        fail('Acción desconocida.', 400);
}


/** ¿Este equipo es de esta cuenta, directamente o por su club? */
/**
 * Qué es esta cuenta para este equipo. Tres caminos, y el orden importa
 * porque una misma persona puede cumplir varios:
 *
 *   'propietario' → lo creó ella; puede todo
 *   'club'        → es del club del que es dueña; gestiona el equipo y
 *                   la plantilla, y lo deportivo solo lo lee
 *   'staff'       → el club le dio acceso por correo; lo deportivo
 *                   entero, pero no toca el equipo ni la plantilla
 *
 * null = no tiene nada que hacer aquí.
 */
function acceso_equipo(int $teamId, int $userId): ?string
{
    try {
        $st = db()->prepare(
            "SELECT t.owner_user_id, c.owner_user_id AS club_owner, s.id AS staff_id
               FROM teams t
               LEFT JOIN clubs c ON c.id = t.club_id
               LEFT JOIN team_staff s
                      ON s.team_id = t.id AND s.user_id = ? AND s.status = 'activo'
              WHERE t.id = ?"
        );
        $st->execute([$userId, $teamId]);
        $r = $st->fetch();
    } catch (Throwable $e) {
        // Sin la migración 05 no existe `team_staff`. Antes que dejar la
        // aplicación entera sin acceso a ningún equipo, se cae al modelo
        // viejo: propietario y dueño del club.
        return es_mi_equipo_legacy($teamId, $userId) ? 'propietario' : null;
    }

    if (!$r) {
        return null;
    }

    // El club va PRIMERO, y no es un detalle: los equipos que crea una
    // cuenta de club quedan con su `owner_user_id`, así que mirar la
    // propiedad antes la haría 'propietario' y podría planificar. Quien
    // lleva el club gestiona y mira; entrenar es de otro.
    if ($r['club_owner'] !== null && (int) $r['club_owner'] === $userId) {
        return 'club';
    }
    if ((int) $r['owner_user_id'] === $userId) {
        return 'propietario';
    }
    if ($r['staff_id'] !== null) {
        return 'staff';
    }
    return null;
}

/**
 * Licencias de staff gastadas por un club. Cuenta parejas persona-equipo:
 * quien lleva tres categorías gasta tres. Es lo que se cobra.
 */
function staff_count(int $clubId): int
{
    try {
        $st = db()->prepare('SELECT COUNT(*) AS n FROM team_staff WHERE club_id = ?');
        $st->execute([$clubId]);
        return (int) ($st->fetch()['n'] ?? 0);
    } catch (Throwable $e) {
        return 0;
    }
}

/** El club del que es dueña esta cuenta, o null si no tiene. */
function mi_club(int $userId): ?array
{
    $st = db()->prepare('SELECT id, name, city FROM clubs WHERE owner_user_id = ? LIMIT 1');
    $st->execute([$userId]);
    return $st->fetch() ?: null;
}

function es_mi_equipo_legacy(int $teamId, int $userId): bool
{
    $st = db()->prepare(
        'SELECT id FROM teams
          WHERE id = ? AND (owner_user_id = ?
                OR club_id IN (SELECT id FROM clubs WHERE owner_user_id = ?))'
    );
    $st->execute([$teamId, $userId, $userId]);
    return (bool) $st->fetch();
}

function es_mi_equipo(int $teamId, int $userId): bool
{
    return acceso_equipo($teamId, $userId) !== null;
}

/**
 * Corta la petición si la cuenta no tiene el nivel que hace falta. El
 * mensaje distingue «no es tuyo» de «sí es tuyo, pero eso no te toca»:
 * son dos errores distintos y el segundo se arregla pidiéndoselo a otro.
 */
function exige_acceso(int $teamId, int $userId, array $niveles): string
{
    $a = acceso_equipo($teamId, $userId);

    if ($a === null) {
        fail('Ese equipo no es tuyo.', 403);
    }
    if (!in_array($a, $niveles, true)) {
        fail($a === 'club'
            ? 'La cuenta del club no planifica: eso lo hace el staff del equipo.'
            : 'Esto lo lleva el club, no el staff del equipo.', 403);
    }
    return $a;
}

/**
 * Ata las invitaciones pendientes a la cuenta que acaba de entrar. El
 * club invita a un correo, no a una cuenta: hasta que alguien entra con
 * ese correo, la fila no sabe a quién pertenece.
 */
function vincular_staff(int $userId, string $email): void
{
    try {
        $up = db()->prepare(
            "UPDATE team_staff
                SET user_id = ?, status = 'activo', linked_at = NOW()
              WHERE email = ? AND user_id IS NULL"
        );
        $up->execute([$userId, $email]);
    } catch (Throwable $e) {
        // Sin migración 05 no hay nada que vincular.
    }
}

/**
 * Rehace la carga de una sesión a partir del RPE de sus jugadores.
 *
 * La carga de la sesión es la MEDIA de lo que le costó a cada uno, no la
 * suma: así se puede comparar con la prevista, que es la de un jugador
 * cualquiera, y no crece sola al fichar gente. La suma del equipo se
 * devuelve aparte, que también interesa.
 *
 * Sin ninguna respuesta la sesión vuelve a estar planificada: es lo que
 * pasa cuando se borra el último RPE, y dejarla «realizada» y a cero
 * sería mentir en el gráfico.
 */
function recalcular_sesion(int $sessionId): array
{
    $st = db()->prepare(
        'SELECT COUNT(*) AS n, AVG(rpe) AS rpe, AVG(load_ua) AS carga, SUM(load_ua) AS total
           FROM rpe_entries WHERE session_id = ?'
    );
    $st->execute([$sessionId]);
    $r = $st->fetch();

    $n = (int) $r['n'];

    if ($n === 0) {
        $up = db()->prepare(
            "UPDATE sessions SET actual_rpe = NULL, actual_load = NULL,
                    status = 'planificada' WHERE id = ?"
        );
        $up->execute([$sessionId]);

        return ['respuestas' => 0, 'carga' => 0, 'rpe' => null, 'total' => 0];
    }

    $rpe   = round((float) $r['rpe'], 1);
    $carga = (int) round((float) $r['carga']);

    $up = db()->prepare(
        "UPDATE sessions SET actual_rpe = ?, actual_load = ?, status = 'realizada'
          WHERE id = ?"
    );
    $up->execute([$rpe, $carga, $sessionId]);

    return [
        'respuestas' => $n,
        'carga'      => $carga,
        'rpe'        => $rpe,
        'total'      => (int) $r['total'],
    ];
}
