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

        json_out([
            'ok'    => true,
            'club'  => $club,
            'teams' => $teams,
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

        $st = db()->prepare(
            'SELECT date, kind, md_label, title, time, place, duration_min,
                    planned_load, actual_load, status
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
