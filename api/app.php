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

        $club = mi_club($userId);

        $st = db()->prepare(
            "SELECT t.id, t.name, t.category, t.modality, t.formation, t.tint, t.club_id,
                    c.name AS club_name, c.badge_url AS club_badge_url,
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
                        c.name AS club_name, c.badge_url AS club_badge_url,
                        (SELECT COUNT(*) FROM players p WHERE p.team_id = t.id AND p.active = 1) AS players,
                        CASE WHEN t.owner_user_id = ? THEN 'propietario' ELSE 'club' END AS acceso
                   FROM teams t
                   LEFT JOIN clubs c ON c.id = t.club_id
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

        // acceso_equipo() y no una consulta propia: antes esto solo miraba
        // propiedad y club, así que el staff que un club invita a un
        // equipo —correo por medio, sin ser su dueño— no podía abrirlo
        // nunca. Cualquier nivel vale para leer; lo que se pueda escribir
        // lo decide `acceso` en el navegador y lo vuelve a mirar el
        // servidor en cada acción de escritura.
        $acceso = acceso_equipo($teamId, $userId);
        if ($acceso === null) {
            fail('Ese equipo no existe o no es tuyo.', 404);
        }

        $st = db()->prepare(
            'SELECT t.*, c.name AS club_name, c.badge_url AS club_badge_url
               FROM teams t
               LEFT JOIN clubs c ON c.id = t.club_id
              WHERE t.id = ?'
        );
        $st->execute([$teamId]);
        $team = $st->fetch();

        // La ficha completa del jugador es de la migración 09. Sin ella,
        // se cae a las cinco columnas de siempre en vez de romper la
        // página entera por una columna que no existe todavía.
        try {
            $p = db()->prepare(
                'SELECT id, name, dorsal, position, position_alt, foot,
                        birth_date, email, notes,
                        invite_status, invited_at, registered_at
                   FROM players WHERE team_id = ? AND active = 1
                  ORDER BY (dorsal IS NULL), dorsal, name'
            );
            $p->execute([$teamId]);
            $jugadores = $p->fetchAll();
        } catch (Throwable $e) {
            $p = db()->prepare(
                'SELECT id, name, dorsal, position, access_code
                   FROM players WHERE team_id = ? AND active = 1
                  ORDER BY (dorsal IS NULL), dorsal, name'
            );
            $p->execute([$teamId]);
            $jugadores = array_map(fn($j) => $j + [
                'position_alt' => '', 'foot' => '', 'birth_date' => null,
                'email' => null, 'notes' => '', 'invite_status' => 'sin_invitar',
                'invited_at' => null, 'registered_at' => null,
            ], $p->fetchAll());
        }

        json_out([
            'ok'      => true,
            'acceso'  => $acceso,
            'team'    => $team,
            'players' => $jugadores,
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

        // La ficha completa del equipo es de la migración 11. Sin ella, se
        // cae a las columnas de siempre en vez de romper la página del
        // club entera por una columna que no existe todavía.
        try {
            $st = db()->prepare(
                'SELECT t.id, t.name, t.category, t.gender, t.modality, t.formation, t.tint,
                        (SELECT COUNT(*) FROM players p WHERE p.team_id = t.id AND p.active = 1) AS players
                   FROM teams t WHERE t.club_id = ? ORDER BY t.name, t.id'
            );
            $st->execute([$clubId]);
            $teams = $st->fetchAll();
        } catch (Throwable $e) {
            $st = db()->prepare(
                'SELECT t.id, t.name, t.category, t.modality, t.formation, t.tint,
                        (SELECT COUNT(*) FROM players p WHERE p.team_id = t.id AND p.active = 1) AS players
                   FROM teams t WHERE t.club_id = ? ORDER BY t.name, t.id'
            );
            $st->execute([$clubId]);
            $teams = array_map(fn($t) => $t + ['gender' => ''], $st->fetchAll());
        }

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

    // ── Quitar el escudo ─────────────────────────────────────────────
    // Subirlo es cosa de api/subir_escudo.php, que necesita leer
    // $_FILES; quitarlo es una fila normal y cabe aquí, con el resto.
    case 'quitar_escudo':
        $club = mi_club($userId);
        if (!$club) {
            fail('Esta cuenta no lleva ningún club.', 403);
        }

        db()->prepare('UPDATE clubs SET badge_url = NULL WHERE id = ?')->execute([(int) $club['id']]);

        // El archivo se borra después de quitarlo de la base, no antes:
        // si el borrado del archivo fallara, mejor una fila sin escudo
        // que un escudo roto apuntando a un archivo que ya no existe.
        if (!empty($club['badge_url'])) {
            $dir    = realpath(__DIR__ . '/../uploads/escudos');
            $archivo = realpath(__DIR__ . '/../' . $club['badge_url']);
            if ($dir && $archivo && strpos($archivo, $dir) === 0) {
                @unlink($archivo);
            }
        }

        json_out(['ok' => true]);

    // ── Dar acceso a alguien, por correo ───────────────────────────
    case 'invitar_staff':
        $club = mi_club($userId);
        if (!$club) {
            fail('Solo una cuenta de club reparte accesos.', 403);
        }
        $clubId = (int) $club['id'];
        $teamId = (int) (body()['team_id'] ?? 0);

        $t = db()->prepare('SELECT id, name FROM teams WHERE id = ? AND club_id = ?');
        $t->execute([$teamId, $clubId]);
        $team = $t->fetch();
        if (!$team) {
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

        // El aviso es aparte de la plaza: si el correo no sale, la plaza
        // se queda igual —esa persona entra en cuanto se registre con
        // este correo, como siempre— y el navegador puede avisar de que
        // convendría decírselo de otra forma.
        if ($existente) {
            $link = rtrim($CONFIG['app_url'], '/') . '/acceso.html';
            $enviado = send_mail(
                $email,
                'Ya tienes acceso a ' . $team['name'] . ' en PlayLoad',
                "{$user['name']} te ha dado acceso a {$team['name']}, de {$club['name']}, en PlayLoad.\n\n" .
                "Entra con tu cuenta de siempre y lo verás en tu panel:\n{$link}\n\n" .
                "Si no esperabas este correo, puedes ignorarlo tranquilamente.\n",
                correo_html(
                    'Un equipo nuevo en tu cuenta',
                    [
                        "{$user['name']} te ha dado acceso a {$team['name']}, de {$club['name']}, en PlayLoad.",
                        'Entra con tu cuenta de siempre y lo verás en tu panel, junto a los que ya tenías.',
                    ],
                    ['texto' => 'Entrar en PlayLoad', 'href' => $link],
                    'Si no esperabas este correo, puedes ignorarlo tranquilamente.'
                )
            );
        } else {
            $link = rtrim($CONFIG['app_url'], '/') . '/registro.html?email=' . rawurlencode($email);
            $enviado = send_mail(
                $email,
                'Te han invitado a ' . $team['name'] . ' en PlayLoad',
                "{$user['name']} te ha dado acceso a {$team['name']}, de {$club['name']}, en PlayLoad.\n\n" .
                "Crea tu cuenta con este correo y el equipo ya te estará esperando, sin nada más\n" .
                "que hacer:\n{$link}\n\n" .
                "Si no esperabas este correo, puedes ignorarlo: sin crear una cuenta con este\n" .
                "correo, nadie entra.\n",
                correo_html(
                    $club['name'] . ' te espera en PlayLoad',
                    [
                        "{$user['name']} te ha dado acceso a {$team['name']}, de {$club['name']}, en PlayLoad.",
                        "Crea tu cuenta con este correo —{$email}— y el equipo aparecerá directamente "
                            . 'en tu panel, sin nada más que hacer.',
                    ],
                    ['texto' => 'Crear mi cuenta', 'href' => $link],
                    'Si no esperabas este correo, puedes ignorarlo: sin crear una cuenta con este '
                        . 'correo, nadie entra.'
                )
            );
        }

        json_out([
            'ok' => true,
            'id' => (int) db()->lastInsertId(),
            // No se llama `status` a propósito: el navegador mete ahí el
            // código HTTP y una clave repetida se pisaría sola.
            'estado'         => $existente ? 'activo' : 'invitado',
            'correo_enviado' => $enviado,
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

        // Sin catálogo cerrado: la categoría la escribe quien crea el
        // equipo, porque cambia según la federación. El género sí tiene
        // tres valores fijos.
        $gender = in_array(param('gender'), ['masculino', 'femenino', 'mixto'], true)
            ? param('gender') : '';

        try {
            $ins = db()->prepare(
                'INSERT INTO teams (club_id, owner_user_id, name, category, gender, modality, formation, tint)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $ins->execute([
                $clubId, $userId, $name,
                param('category'), $gender,
                param('modality', 'Fútbol 11'),
                param('formation', '1-4-3-3'),
                preg_match('/^#[0-9a-f]{6}$/i', param('tint')) ? param('tint') : '#9184d9',
            ]);
        } catch (Throwable $e) {
            // Sin la migración 11 no hay `gender` que rellenar.
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
        }

        json_out(['ok' => true, 'id' => (int) db()->lastInsertId()], 201);

    // ── Añadir jugador ─────────────────────────────────────────────
    case 'crear_jugador':
        $teamId = (int) (body()['team_id'] ?? 0);
        $name   = param('name');

        if ($name === '') {
            fail('El jugador necesita un nombre.');
        }

        // La plantilla la lleva el club y quien creó el equipo; el resto
        // del staff del equipo también, desde esta versión: solo queda
        // fuera de la ficha del jugador lo que no le corresponde, como
        // los ajustes del equipo.
        exige_acceso($teamId, $userId, ['propietario', 'club', 'staff']);

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

        $dorsal = body()['dorsal'] ?? null;
        $dorsal = ($dorsal === '' || $dorsal === null) ? null : (int) $dorsal;
        [$fecha, $email] = datos_jugador_opcionales();

        try {
            // Esquema al día: el jugador entra por correo, no hace falta
            // ningún código que generar.
            $ins = db()->prepare(
                'INSERT INTO players
                    (team_id, name, dorsal, position, position_alt, foot,
                     birth_date, email, notes)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $ins->execute([
                $teamId, $name, $dorsal, param('position'), param('position_alt'),
                param('foot'), $fecha, $email, mb_substr(param('notes'), 0, 500),
            ]);
        } catch (Throwable $e) {
            // Sin la migración 10, `access_code` todavía es obligatoria
            // en la base: se genera uno como siempre para que el alta no
            // se rompa. Si además falta la 09, esas columnas tampoco
            // existen y se pierden por esta vez; se recuperan en cuanto
            // se edite el jugador con las migraciones ya puestas.
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

            try {
                $ins = db()->prepare(
                    'INSERT INTO players
                        (team_id, name, dorsal, position, position_alt, foot,
                         birth_date, email, notes, access_code)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $ins->execute([
                    $teamId, $name, $dorsal, param('position'), param('position_alt'),
                    param('foot'), $fecha, $email, mb_substr(param('notes'), 0, 500), $code,
                ]);
            } catch (Throwable $e2) {
                $ins = db()->prepare(
                    'INSERT INTO players (team_id, name, dorsal, position, access_code)
                     VALUES (?, ?, ?, ?, ?)'
                );
                $ins->execute([$teamId, $name, $dorsal, param('position'), $code]);
            }
        }

        json_out(['ok' => true, 'id' => (int) db()->lastInsertId()], 201);

    // ── Editar la ficha de un jugador ────────────────────────────────
    // El nombre es lo único obligatorio, igual que al crearlo.
    case 'editar_jugador':
        $id = (int) (body()['player_id'] ?? 0);
        $name = param('name');

        if ($name === '') {
            fail('El jugador necesita un nombre.');
        }

        $st = db()->prepare('SELECT id, team_id, email, invite_status FROM players WHERE id = ?');
        try {
            $st->execute([$id]);
            $j = $st->fetch();
        } catch (Throwable $e) {
            // Sin migración 09 no hay `invite_status` que leer.
            $st = db()->prepare('SELECT id, team_id FROM players WHERE id = ?');
            $st->execute([$id]);
            $j = $st->fetch();
        }

        if (!$j) {
            fail('Ese jugador no existe.', 404);
        }
        // La ficha del jugador la edita también el staff del equipo, no
        // solo quien lo creó o el club: es trabajo del día a día, a
        // diferencia de los ajustes del equipo.
        exige_acceso((int) $j['team_id'], $userId, ['propietario', 'club', 'staff']);

        $dorsal = body()['dorsal'] ?? null;
        $dorsal = ($dorsal === '' || $dorsal === null) ? null : (int) $dorsal;
        [$fecha, $email] = datos_jugador_opcionales();

        // Si el correo cambia mientras había una invitación esperando,
        // ese enlace se mandó a una dirección que ya no vale: el estado
        // vuelve a «sin invitar» para que no quede uno colgado que nadie
        // va a abrir. Un jugador que ya entró no se toca: entrar no
        // depende del correo que haya en la ficha hoy, y corregirlo
        // después no debería desregistrar a nadie ni cerrarle el acceso
        // que ya tiene.
        $reabre = isset($j['invite_status'])
            && $j['invite_status'] === 'invitado'
            && $email !== ($j['email'] ?? null);

        try {
            $up = db()->prepare(
                'UPDATE players SET
                    name = ?, dorsal = ?, position = ?, position_alt = ?, foot = ?,
                    birth_date = ?, email = ?, notes = ?
                    ' . ($reabre ? ", invite_status = 'sin_invitar', invited_at = NULL" : '') . '
                  WHERE id = ?'
            );
            $up->execute([
                $name, $dorsal, param('position'), param('position_alt'), param('foot'),
                $fecha, $email, mb_substr(param('notes'), 0, 500), $id,
            ]);
        } catch (Throwable $e) {
            $up = db()->prepare(
                'UPDATE players SET name = ?, dorsal = ?, position = ? WHERE id = ?'
            );
            $up->execute([$name, $dorsal, param('position'), $id]);
        }

        // El enlace viejo queda inválido aparte: en un `UPDATE` separado,
        // para que si `login_token_hash` todavía no existe (falta la
        // migración 10) no se lleve por delante el resto de la ficha,
        // que si acababa de guardarse bien.
        if ($reabre) {
            try {
                db()->prepare('UPDATE players SET login_token_hash = NULL WHERE id = ?')
                    ->execute([$id]);
            } catch (Throwable $e) {
                // Sin migración 10 no hay testigo que invalidar.
            }
        }

        json_out(['ok' => true, 'reabierta' => $reabre]);

    // ── Invitar a un jugador por correo ──────────────────────────────
    // El enlace ES la credencial: no hay contraseña ni código que
    // dictar. No caduca —para poder guardarlo en la pantalla de inicio
    // del móvil y que siga sirviendo—, pero cambia cada vez que se
    // manda un correo nuevo, así que solo el último enlace enviado vale;
    // los anteriores, si se reenvía, dejan de servir.
    //
    // Se puede invitar aunque ya esté «registrado»: es como se le manda
    // un enlace nuevo a quien perdió el suyo, y es la única forma de
    // recuperar el acceso ahora que no hay código que volver a dictar.
    case 'invitar_jugador':
        $id = (int) (body()['player_id'] ?? 0);

        // Sin las migraciones 09/10, `invite_status` ni siquiera existe:
        // antes esto tumbaba la petición entera y el navegador solo veía
        // una respuesta vacía, sin decir por qué. Ahora se explica.
        try {
            $st = db()->prepare(
                'SELECT p.id, p.team_id, p.name, p.email, p.invite_status, t.name AS team_name
                   FROM players p JOIN teams t ON t.id = p.team_id
                  WHERE p.id = ?'
            );
            $st->execute([$id]);
            $j = $st->fetch();
        } catch (Throwable $e) {
            fail(
                'Faltan las migraciones 09 y 10 en la base de datos: sin ellas no se puede '
                    . 'invitar a nadie. Impórtalas desde phpMyAdmin (db/migracion-09-jugador-perfil.sql '
                    . 'y db/migracion-10-acceso-por-correo.sql).',
                500
            );
        }

        if (!$j) {
            fail('Ese jugador no existe.', 404);
        }
        exige_acceso((int) $j['team_id'], $userId, ['propietario', 'club', 'staff']);

        if (!$j['email']) {
            fail('Este jugador todavía no tiene un correo en su ficha.');
        }

        $token = bin2hex(random_bytes(32));
        $link  = rtrim($CONFIG['app_url'], '/') . '/acceso.html?v=player&ptoken=' . $token;

        $enviado = send_mail(
            $j['email'],
            'Tu acceso a ' . $j['team_name'] . ' en PlayLoad',
            "Hola {$j['name']},\n\n" .
            "{$user['name']} te ha dado acceso a {$j['team_name']} en PlayLoad.\n\n" .
            "Abre este enlace desde tu móvil para entrar, sin contraseña:\n{$link}\n\n" .
            "Es tuyo: guárdalo o añade la página a la pantalla de inicio, y te servirá\n" .
            "cada vez que quieras mandar cómo te encuentras o el esfuerzo de la sesión.\n\n" .
            "Si no esperabas este correo, puedes ignorarlo: sin abrir el enlace nadie entra.\n",
            correo_html(
                'Ya puedes entrar a ' . $j['team_name'],
                [
                    "Hola {$j['name']},",
                    "{$user['name']} te ha dado acceso a {$j['team_name']} en PlayLoad.",
                    'Abre el enlace de abajo desde tu móvil para entrar, sin contraseña. Es tuyo: '
                        . 'guárdalo o añade la página a la pantalla de inicio, y te servirá cada vez '
                        . 'que quieras mandar cómo te encuentras o el esfuerzo de la sesión.',
                ],
                ['texto' => 'Entrar en PlayLoad', 'href' => $link],
                'Si no esperabas este correo, puedes ignorarlo: sin abrir el enlace nadie entra.'
            )
        );

        if (!$enviado) {
            fail('No se ha podido enviar el correo. Revisa mail_from en config.php.', 502);
        }

        // Un jugador que ya entró sigue «registrado» aunque se le mande
        // un enlace nuevo: eso no deshace que ya haya entrado.
        try {
            db()->prepare(
                "UPDATE players SET
                    login_token_hash = ?, invited_at = NOW(),
                    invite_status = IF(invite_status = 'registrado', 'registrado', 'invitado')
                  WHERE id = ?"
            )->execute([hash('sha256', $token), $id]);
        } catch (Throwable $e) {
            // El correo ya ha salido, pero sin la migración 10 no hay
            // `login_token_hash` donde guardar el testigo: el enlace que
            // acaba de recibir no va a funcionar. Mejor decirlo claro que
            // dejar creer que la invitación quedó completa.
            fail(
                'El correo se ha mandado, pero falta la migración 10 en la base de datos y el '
                    . 'enlace no va a funcionar todavía. Impórtala desde phpMyAdmin '
                    . '(db/migracion-10-acceso-por-correo.sql) y vuelve a invitarlo.',
                500
            );
        }

        json_out(['ok' => true]);

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

        $gender = in_array(param('gender'), ['masculino', 'femenino', 'mixto'], true)
            ? param('gender') : '';
        $tint = preg_match('/^#[0-9a-f]{6}$/i', param('tint')) ? param('tint') : '#9184d9';

        try {
            $up = db()->prepare(
                'UPDATE teams SET name = ?, category = ?, gender = ?, modality = ?, formation = ?, tint = ?
                  WHERE id = ?'
            );
            $up->execute([$name, param('category'), $gender, param('modality', 'Fútbol 11'), $form, $tint, $teamId]);
        } catch (Throwable $e) {
            // Sin la migración 11 no hay `gender` que guardar.
            $up = db()->prepare(
                'UPDATE teams SET name = ?, category = ?, modality = ?, formation = ?, tint = ?
                  WHERE id = ?'
            );
            $up->execute([$name, param('category'), param('modality', 'Fútbol 11'), $form, $tint, $teamId]);
        }

        json_out(['ok' => true]);

    // ── Sesiones de un equipo ──────────────────────────────────────
    case 'sesiones':
        $teamId = (int) ($_GET['team_id'] ?? 0);
        if (!es_mi_equipo($teamId, $userId)) {
            fail('Ese equipo no es tuyo.', 403);
        }

        // El número de bloques viaja con cada fila: la lista de sesiones
        // distingue así la que está montada de la que es solo un hueco
        // en el calendario, sin pedir el detalle de todas.
        $campos = 'id, date, time, title, kind, color, md_label, place, duration_min,
                   planned_rpe, planned_load, actual_rpe, actual_load, status,
                   (SELECT COUNT(*) FROM session_blocks b WHERE b.session_id = sessions.id)
                     AS bloques';

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

        $filas   = $st->fetchAll();
        $colores = colores_tipo_sesion($teamId);
        foreach ($filas as &$f) {
            $f['color'] = $f['color'] ?: ($colores[$f['kind']] ?? $colores['otro']);
        }
        unset($f);

        json_out(['ok' => true, 'sesiones' => $filas, 'colores' => $colores]);

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
        if (!in_array($kind, tipos_sesion(), true)) {
            $kind = 'entrenamiento';
        }

        $color = color_valido(param('color'));
        if ($color !== null) {
            guardar_color_tipo($teamId, $kind, $color);
        }

        $dur = max(0, min(240, (int) (body()['duration_min'] ?? 90)));
        $rpe = body()['planned_rpe'] ?? null;
        $rpe = ($rpe === '' || $rpe === null) ? null : max(1, min(10, (int) $rpe));

        $ins = db()->prepare(
            'INSERT INTO sessions
                (team_id, date, time, title, kind, color, md_label, place,
                 duration_min, planned_rpe, planned_load)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $ins->execute([
            $teamId, $date,
            param('time') !== '' ? param('time') : null,
            param('title'), $kind, $color, param('md_label'), param('place'),
            $dur, $rpe,
            $rpe !== null ? $rpe * $dur : null,
        ]);

        json_out(['ok' => true, 'id' => (int) db()->lastInsertId()], 201);

    // ── Una sesión con sus bloques ─────────────────────────────────
    case 'sesion':
        $id = (int) ($_GET['session_id'] ?? 0);

        $st = db()->prepare(
            'SELECT id, team_id, date, time, title, kind, color, md_label, place,
                    duration_min, planned_rpe, planned_load,
                    actual_rpe, actual_load, status
               FROM sessions WHERE id = ?'
        );
        $st->execute([$id]);
        $ses = $st->fetch();

        if (!$ses || !es_mi_equipo((int) $ses['team_id'], $userId)) {
            fail('Esa sesión no es tuya.', 403);
        }

        $colores      = colores_tipo_sesion((int) $ses['team_id']);
        $ses['color'] = $ses['color'] ?: ($colores[$ses['kind']] ?? $colores['otro']);

        $st = db()->prepare(
            'SELECT id, name, block_type, location, minutes, intensity, sort
               FROM session_blocks WHERE session_id = ?
              ORDER BY sort, id'
        );
        $st->execute([$id]);
        $bloques = $st->fetchAll();

        // Los ejercicios de todos los bloques, de una vez, y luego se
        // reparten por bloque: una consulta por bloque no compensa
        // cuando una sesión puede tener media docena.
        $porBloque = [];
        if ($bloques) {
            $ids = array_map(static fn ($b) => (int) $b['id'], $bloques);
            $ph  = implode(',', array_fill(0, count($ids), '?'));
            $eq  = db()->prepare(
                "SELECT id, block_id, exercise_id, name, notes, sort
                   FROM block_exercises WHERE block_id IN ($ph)
                  ORDER BY sort, id"
            );
            $eq->execute($ids);
            foreach ($eq->fetchAll() as $e) {
                $porBloque[(int) $e['block_id']][] = $e;
            }
        }
        foreach ($bloques as &$b) {
            $b['ejercicios'] = $porBloque[(int) $b['id']] ?? [];
        }
        unset($b);

        // Cuántos han contestado ya: el botón de pasar lista dice una
        // cosa u otra según lo haya hecho alguien o nadie.
        $q = db()->prepare('SELECT COUNT(*) AS n FROM rpe_entries WHERE session_id = ?');
        $q->execute([$id]);

        json_out([
            'ok'         => true,
            'sesion'     => $ses,
            'bloques'    => $bloques,
            'respuestas' => (int) ($q->fetch()['n'] ?? 0),
        ]);

    // ── Editar la cabecera de una sesión ───────────────────────────
    // Los minutos y el RPE no se tocan aquí cuando la sesión tiene
    // bloques: los manda el contenido, y dejar cambiarlos por detrás
    // sería poder decir que dura 60 mientras los bloques suman 90.
    case 'editar_sesion':
        $id = (int) (body()['session_id'] ?? 0);

        $st = db()->prepare('SELECT id, team_id FROM sessions WHERE id = ?');
        $st->execute([$id]);
        $ses = $st->fetch();

        if (!$ses) {
            fail('Esa sesión no es tuya.', 403);
        }
        exige_acceso((int) $ses['team_id'], $userId, ['propietario', 'staff']);

        $date = param('date');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            fail('La fecha debe ser AAAA-MM-DD.');
        }

        $kind = param('kind', 'entrenamiento');
        if (!in_array($kind, tipos_sesion(), true)) {
            $kind = 'entrenamiento';
        }

        $q = db()->prepare('SELECT COUNT(*) AS n FROM session_blocks WHERE session_id = ?');
        $q->execute([$id]);
        $conBloques = ((int) ($q->fetch()['n'] ?? 0)) > 0;

        $up = db()->prepare(
            'UPDATE sessions SET date = ?, time = ?, title = ?, kind = ?,
                    md_label = ?, place = ? WHERE id = ?'
        );
        $up->execute([
            $date,
            param('time') !== '' ? param('time') : null,
            param('title'), $kind, param('md_label'), param('place'),
            $id,
        ]);

        // Un color solo se toca si llega uno válido: si no, este guardado
        // no habla de color y el que hubiera se queda como estaba. Y en
        // cuanto se toca, de paso pasa a ser el color por defecto de su
        // tipo, que es justo lo que pide poder elegirlo y que las
        // siguientes sesiones de ese tipo lo hereden.
        $color = color_valido(param('color'));
        if ($color !== null) {
            db()->prepare('UPDATE sessions SET color = ? WHERE id = ?')->execute([$color, $id]);
            guardar_color_tipo((int) $ses['team_id'], $kind, $color);
        }

        if (!$conBloques) {
            $dur = max(0, min(240, (int) (body()['duration_min'] ?? 90)));
            $rpe = body()['planned_rpe'] ?? null;
            $rpe = ($rpe === '' || $rpe === null) ? null : max(1, min(10, (int) $rpe));

            $up = db()->prepare(
                'UPDATE sessions SET duration_min = ?, planned_rpe = ?, planned_load = ?
                  WHERE id = ?'
            );
            $up->execute([$dur, $rpe, $rpe !== null ? $rpe * $dur : null, $id]);
        }

        json_out(['ok' => true, 'bloques' => $conBloques]);

    // ── Los bloques de una sesión ──────────────────────────────────
    // Llega la lista entera. A diferencia de antes, el id de cada
    // bloque se conserva (se actualiza, no se borra y recrea): con
    // guardado automático esto se llama todo el rato, y los ejercicios
    // enganchados a un bloque (block_exercises) van atados a su id por
    // clave foránea. Recrear el bloque en cada guardado los borraría.
    case 'guardar_bloques':
        $id = (int) (body()['session_id'] ?? 0);

        $st = db()->prepare('SELECT id, team_id FROM sessions WHERE id = ?');
        $st->execute([$id]);
        $ses = $st->fetch();

        if (!$ses) {
            fail('Esa sesión no es tuya.', 403);
        }
        exige_acceso((int) $ses['team_id'], $userId, ['propietario', 'staff']);

        $bloques = body()['bloques'] ?? [];
        if (!is_array($bloques)) {
            fail('Faltan los bloques.');
        }

        $st = db()->prepare('SELECT id FROM session_blocks WHERE session_id = ?');
        $st->execute([$id]);
        $previos = array_map('intval', array_column($st->fetchAll(), 'id'));

        $tiposBloque = tipos_bloque();
        $espaciosOk  = espacios();

        $up = db()->prepare(
            'UPDATE session_blocks SET name = ?, block_type = ?, location = ?,
                    minutes = ?, intensity = ?, sort = ?
              WHERE id = ? AND session_id = ?'
        );
        $ins = db()->prepare(
            'INSERT INTO session_blocks
                (session_id, name, block_type, location, minutes, intensity, sort)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );

        $orden  = 0;
        $min    = 0;
        $carga  = 0;
        $vistos = [];
        $salida = [];

        foreach ($bloques as $b) {
            $nombre = trim(mb_substr((string) ($b['name'] ?? ''), 0, 120));
            if ($nombre === '') {
                continue;                       // un bloque sin nombre no es un bloque
            }

            $tipo    = in_array($b['block_type'] ?? '', $tiposBloque, true) ? $b['block_type'] : 'otro';
            $espacio = in_array($b['location'] ?? '', $espaciosOk, true) ? $b['location'] : '';

            $m = max(0, min(240, (int) ($b['minutes'] ?? 0)));
            $i = (int) ($b['intensity'] ?? 0);
            $i = ($i < 1 || $i > 10) ? null : $i;

            $bid = (int) ($b['id'] ?? 0);
            if ($bid > 0 && in_array($bid, $previos, true)) {
                $up->execute([$nombre, $tipo, $espacio, $m, $i, $orden, $bid, $id]);
            } else {
                $ins->execute([$id, $nombre, $tipo, $espacio, $m, $i, $orden]);
                $bid = (int) db()->lastInsertId();
            }
            $vistos[] = $bid;

            $salida[] = [
                'id' => $bid, 'name' => $nombre, 'block_type' => $tipo, 'location' => $espacio,
                'minutes' => $m, 'intensity' => $i, 'sort' => $orden,
            ];

            $orden++;
            $min += $m;
            // Un bloque sin intensidad suma tiempo pero no carga: es lo
            // que pasa con una charla o un rondo de vuelta a la calma.
            $carga += $i !== null ? $m * $i : 0;
        }

        // Los que ya no vienen en la lista se han quitado: se borran, y
        // sus ejercicios se van con ellos por la clave foránea.
        $fuera = array_diff($previos, $vistos);
        if ($fuera) {
            $ph = implode(',', array_fill(0, count($fuera), '?'));
            db()->prepare("DELETE FROM session_blocks WHERE id IN ($ph)")
                ->execute(array_values($fuera));
        }

        // Con bloques, la sesión ya no se describe a ojo: dura lo que
        // suman y cuesta lo que suman. El RPE previsto pasa a ser la
        // media pesada por minutos, que es lo que significaba.
        //
        // Y si se quedó sin ninguno, se le quita también el plan: la
        // carga que tenía la habían puesto esos bloques, y dejarla
        // sería enseñar la suma de algo que ya no existe.
        if ($orden > 0) {
            $up = db()->prepare(
                'UPDATE sessions SET duration_min = ?, planned_rpe = ?, planned_load = ?
                  WHERE id = ?'
            );
            $up->execute([
                $min,
                $min > 0 && $carga > 0 ? (int) round($carga / $min) : null,
                $carga > 0 ? $carga : null,
                $id,
            ]);
        } else {
            // La duración se queda: es el hueco reservado en el
            // calendario y no la puso ningún bloque.
            db()->prepare(
                'UPDATE sessions SET planned_rpe = NULL, planned_load = NULL WHERE id = ?'
            )->execute([$id]);
        }

        json_out([
            'ok' => true, 'bloques' => $salida, 'n_bloques' => $orden,
            'minutos' => $min, 'carga' => $carga,
        ]);

    // ── Biblioteca de ejercicios ─────────────────────────────────────
    // Es de la cuenta, no del equipo: un mismo entrenador con varios
    // equipos ve y reutiliza los mismos ejercicios en todos. `team_id`
    // solo sirve para comprobar que quien pregunta gestiona al menos
    // un equipo — no filtra nada.
    case 'ejercicios':
        $teamId = (int) ($_GET['team_id'] ?? 0);
        if ($teamId && !es_mi_equipo($teamId, $userId)) {
            fail('Ese equipo no es tuyo.', 403);
        }

        $tipo = (string) ($_GET['block_type'] ?? '');
        $q    = trim((string) ($_GET['q'] ?? ''));

        $sql  = 'SELECT id, name, block_type, description, materials,
                        duration_min, intensity, space, diagram
                   FROM exercises WHERE owner_user_id = ?';
        $args = [$userId];

        if (in_array($tipo, tipos_bloque(), true)) {
            $sql   .= ' AND block_type = ?';
            $args[] = $tipo;
        }
        if ($q !== '') {
            $sql   .= ' AND name LIKE ?';
            $args[] = '%' . $q . '%';
        }
        $sql .= ' ORDER BY name';

        $st = db()->prepare($sql);
        $st->execute($args);

        json_out(['ok' => true, 'ejercicios' => $st->fetchAll()]);

    // ── Crear un ejercicio en la biblioteca ──────────────────────────
    case 'crear_ejercicio':
        $teamId = (int) (body()['team_id'] ?? 0);
        exige_acceso($teamId, $userId, ['propietario', 'staff']);

        $nombre = trim(mb_substr(param('name'), 0, 120));
        if ($nombre === '') {
            fail('El ejercicio necesita un nombre.');
        }

        $ej = insertar_ejercicio($userId, $nombre);
        json_out(['ok' => true, 'ejercicio' => $ej], 201);

    // ── Editar un ejercicio de la biblioteca ─────────────────────────
    case 'editar_ejercicio':
        $id = (int) (body()['exercise_id'] ?? 0);
        exige_ejercicio_propio($id, $userId);

        $nombre = trim(mb_substr(param('name'), 0, 120));
        if ($nombre === '') {
            fail('El ejercicio necesita un nombre.');
        }

        $tipo = in_array(param('block_type'), tipos_bloque(), true) ? param('block_type') : 'otro';
        $esp  = in_array(param('space'), espacios(), true) ? param('space') : '';
        $dur  = body()['duration_min'] ?? null;
        $dur  = ($dur === '' || $dur === null) ? null : max(0, min(240, (int) $dur));
        $int  = body()['intensity'] ?? null;
        $int  = ($int === '' || $int === null) ? null : max(1, min(10, (int) $int));

        // El dibujo de la pizarra: JSON tal cual lo manda el cliente, o
        // NULL si viene vacío o no es JSON válido — un ejercicio sin
        // dibujo válido es lo mismo que uno sin dibujar, no un error.
        $dibujoRaw = param('diagram');
        $dibujo    = ($dibujoRaw !== '' && json_decode($dibujoRaw) !== null) ? $dibujoRaw : null;

        $up = db()->prepare(
            'UPDATE exercises SET name = ?, block_type = ?, description = ?, materials = ?,
                    duration_min = ?, intensity = ?, space = ?, diagram = ? WHERE id = ?'
        );
        $up->execute([
            $nombre, $tipo, mb_substr(param('description'), 0, 400),
            mb_substr(param('materials'), 0, 200), $dur, $int, $esp, $dibujo, $id,
        ]);

        json_out(['ok' => true]);

    // ── Borrar un ejercicio de la biblioteca ─────────────────────────
    // Los bloques que ya lo tenían enganchado no lo pierden: guardan su
    // nombre por su cuenta (block_exercises.name), solo se desvincula.
    case 'borrar_ejercicio':
        $id = (int) (body()['exercise_id'] ?? 0);
        exige_ejercicio_propio($id, $userId);

        db()->prepare('DELETE FROM exercises WHERE id = ?')->execute([$id]);

        json_out(['ok' => true]);

    // ── Añadir un ejercicio a un bloque ───────────────────────────────
    // O bien llega `exercise_id` de la biblioteca, o bien los datos para
    // crear uno nuevo — que de paso queda guardado en la biblioteca,
    // porque para eso está: no se escribe un ejercicio dos veces.
    case 'anadir_ejercicio_bloque':
        $blockId = (int) (body()['block_id'] ?? 0);
        $bloque  = bloque_propio($blockId, $userId);

        $exerciseId = (int) (body()['exercise_id'] ?? 0);

        if ($exerciseId > 0) {
            $st = db()->prepare('SELECT id, name FROM exercises WHERE id = ? AND owner_user_id = ?');
            $st->execute([$exerciseId, $userId]);
            $ej = $st->fetch();
            if (!$ej) {
                fail('Ese ejercicio no está en tu biblioteca.', 403);
            }
            $nombre = $ej['name'];
        } else {
            $nombre = trim(mb_substr(param('name'), 0, 120));
            if ($nombre === '') {
                fail('El ejercicio necesita un nombre.');
            }
            $ej = insertar_ejercicio($userId, $nombre, (string) $bloque['block_type']);
            $exerciseId = (int) $ej['id'];
        }

        $q = db()->prepare('SELECT COALESCE(MAX(sort), -1) + 1 AS n FROM block_exercises WHERE block_id = ?');
        $q->execute([$blockId]);
        $sort = (int) ($q->fetch()['n'] ?? 0);

        $ins = db()->prepare(
            'INSERT INTO block_exercises (block_id, exercise_id, name, notes, sort)
             VALUES (?, ?, ?, ?, ?)'
        );
        $ins->execute([$blockId, $exerciseId, $nombre, mb_substr(param('notes'), 0, 240), $sort]);

        json_out(['ok' => true, 'id' => (int) db()->lastInsertId(), 'exercise_id' => $exerciseId,
                   'name' => $nombre], 201);

    // ── Quitar un ejercicio de un bloque ──────────────────────────────
    case 'quitar_ejercicio_bloque':
        $id = (int) (body()['id'] ?? 0);

        $st = db()->prepare(
            'SELECT be.id, s.team_id
               FROM block_exercises be
               JOIN session_blocks sb ON sb.id = be.block_id
               JOIN sessions s ON s.id = sb.session_id
              WHERE be.id = ?'
        );
        $st->execute([$id]);
        $fila = $st->fetch();

        if (!$fila) {
            fail('Ese ejercicio no es tuyo.', 403);
        }
        exige_acceso((int) $fila['team_id'], $userId, ['propietario', 'staff']);

        db()->prepare('DELETE FROM block_exercises WHERE id = ?')->execute([$id]);

        json_out(['ok' => true]);

    // ── Duplicar una sesión ────────────────────────────────────────
    // Una semana de trabajo se parece a la anterior: copiar y mover la
    // fecha es el gesto que de verdad se hace, no montarla otra vez.
    case 'duplicar_sesion':
        $id = (int) (body()['session_id'] ?? 0);

        $st = db()->prepare(
            'SELECT id, team_id, time, title, kind, color, md_label, place,
                    duration_min, planned_rpe, planned_load
               FROM sessions WHERE id = ?'
        );
        $st->execute([$id]);
        $ses = $st->fetch();

        if (!$ses) {
            fail('Esa sesión no es tuya.', 403);
        }
        exige_acceso((int) $ses['team_id'], $userId, ['propietario', 'staff']);

        $date = param('date');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            fail('La fecha debe ser AAAA-MM-DD.');
        }

        // La copia nace planificada y sin carga real: lo que se copia es
        // el plan, no lo que costó aquel día.
        $ins = db()->prepare(
            'INSERT INTO sessions
                (team_id, date, time, title, kind, color, md_label, place,
                 duration_min, planned_rpe, planned_load)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $ins->execute([
            (int) $ses['team_id'], $date, $ses['time'], $ses['title'], $ses['kind'], $ses['color'],
            $ses['md_label'], $ses['place'], $ses['duration_min'],
            $ses['planned_rpe'], $ses['planned_load'],
        ]);
        $nuevo = (int) db()->lastInsertId();

        $st = db()->prepare(
            'SELECT id, name, block_type, location, minutes, intensity, sort
               FROM session_blocks WHERE session_id = ? ORDER BY sort, id'
        );
        $st->execute([$id]);
        $bloquesPrevios = $st->fetchAll();

        $ins = db()->prepare(
            'INSERT INTO session_blocks
                (session_id, name, block_type, location, minutes, intensity, sort)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $insEj = db()->prepare(
            'INSERT INTO block_exercises (block_id, exercise_id, name, notes, sort)
             VALUES (?, ?, ?, ?, ?)'
        );
        $selEj = db()->prepare(
            'SELECT exercise_id, name, notes, sort FROM block_exercises
              WHERE block_id = ? ORDER BY sort, id'
        );

        foreach ($bloquesPrevios as $b) {
            $ins->execute([
                $nuevo, $b['name'], $b['block_type'], $b['location'],
                $b['minutes'], $b['intensity'], $b['sort'],
            ]);
            $bloqueNuevo = (int) db()->lastInsertId();

            $selEj->execute([(int) $b['id']]);
            foreach ($selEj->fetchAll() as $e) {
                $insEj->execute([$bloqueNuevo, $e['exercise_id'], $e['name'], $e['notes'], $e['sort']]);
            }
        }

        json_out(['ok' => true, 'id' => $nuevo], 201);

    // ── Borrar una sesión ──────────────────────────────────────────
    case 'borrar_sesion':
        $id = (int) (body()['session_id'] ?? 0);

        $st = db()->prepare('SELECT id, team_id FROM sessions WHERE id = ?');
        $st->execute([$id]);
        $ses = $st->fetch();

        if (!$ses) {
            fail('Esa sesión no es tuya.', 403);
        }
        exige_acceso((int) $ses['team_id'], $userId, ['propietario', 'staff']);

        // Los bloques y los RPE se van con ella por la clave foránea.
        db()->prepare('DELETE FROM sessions WHERE id = ?')->execute([$id]);

        json_out(['ok' => true]);

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
                    p.dorsal, p.name AS jugador, p.position AS posicion
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
        fputcsv($out, ['Equipo', 'Categoría', 'Modalidad', 'Dorsal', 'Jugador', 'Posición'], ';');
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

// mi_club() vive en bootstrap.php: la necesita también
// api/subir_escudo.php, que no carga este archivo.

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
 * Fecha de nacimiento y correo de un jugador, leídos del cuerpo de la
 * petición. Los dos son opcionales —vacío es válido, es justo lo normal
 * al dar de alta a alguien deprisa— pero si llega algo que no tiene la
 * forma correcta, se avisa en vez de guardarlo a medias o descartarlo en
 * silencio.
 *
 * @return array{0: ?string, 1: ?string} [fecha, correo]
 */
function datos_jugador_opcionales(): array
{
    $fecha = param('birth_date');
    if ($fecha !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
        fail('La fecha de nacimiento debe ser AAAA-MM-DD.');
    }

    $email = mb_strtolower(trim(param('email')));
    if ($email !== '' && !valid_email($email)) {
        fail('Ese correo no es válido.');
    }

    return [$fecha !== '' ? $fecha : null, $email !== '' ? $email : null];
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

// ── Catálogos fijos: tipo de sesión, tipo de bloque, espacio ────────
// El mismo catálogo que impone el ENUM en la base, repetido aquí para
// poder validar sin una vuelta a la base solo para eso.
function tipos_sesion(): array
{
    return ['entrenamiento', 'partido', 'charla', 'recuperacion', 'descanso', 'otro'];
}

function tipos_bloque(): array
{
    return ['activacion', 'fisico', 'tecnico', 'tactico', 'posesion',
            'estrategia', 'competicion', 'charla', 'vuelta_calma', 'otro'];
}

function espacios(): array
{
    return ['campo', 'gimnasio', 'sala', 'piscina', 'exterior', 'otro'];
}

/** El color de cada tipo de sesión cuando el equipo aún no ha elegido
 *  ninguno propio: para que la pantalla nazca ya en color. */
function colores_tipo_defecto(): array
{
    return [
        'entrenamiento' => '#9184d9',
        'partido'       => '#d9846f',
        'charla'        => '#6fa8d9',
        'recuperacion'  => '#7ecf8e',
        'descanso'      => '#9397ab',
        'otro'          => '#d9b36f',
    ];
}

/** Los colores por tipo de sesión de un equipo: los que ha elegido,
 *  completados con los de defecto en los tipos que aún no ha tocado. */
function colores_tipo_sesion(int $teamId): array
{
    $colores = colores_tipo_defecto();

    $st = db()->prepare('SELECT kind, color FROM session_type_colors WHERE team_id = ?');
    $st->execute([$teamId]);
    foreach ($st->fetchAll() as $c) {
        $colores[$c['kind']] = $c['color'];
    }

    return $colores;
}

/** Un '#rrggbb' válido, o null si lo que llega no lo es (incluido
 *  vacío: un guardado que no habla de color no debe borrar el que
 *  hubiera). */
function color_valido(string $v): ?string
{
    return preg_match('/^#[0-9a-fA-F]{6}$/', $v) ? strtolower($v) : null;
}

/** Recuerda este color como el que le toca por defecto a este tipo de
 *  sesión, de ahora en adelante, para este equipo. */
function guardar_color_tipo(int $teamId, string $kind, string $color): void
{
    if (!in_array($kind, tipos_sesion(), true)) {
        return;
    }
    $up = db()->prepare(
        'INSERT INTO session_type_colors (team_id, kind, color) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE color = VALUES(color)'
    );
    $up->execute([$teamId, $kind, $color]);
}

/** Da de alta un ejercicio en la biblioteca del usuario y lo devuelve
 *  tal cual lo vería la pantalla. Los campos que no llegan en el
 *  cuerpo de la petición se quedan vacíos: se pueden rellenar después
 *  editando el ejercicio. */
function insertar_ejercicio(int $userId, string $nombre, string $tipoPorDefecto = 'otro'): array
{
    $tipo = in_array(param('block_type'), tipos_bloque(), true) ? param('block_type') : $tipoPorDefecto;
    $esp  = in_array(param('space'), espacios(), true) ? param('space') : '';
    $dur  = body()['duration_min'] ?? null;
    $dur  = ($dur === '' || $dur === null) ? null : max(0, min(240, (int) $dur));
    $int  = body()['intensity'] ?? null;
    $int  = ($int === '' || $int === null) ? null : max(1, min(10, (int) $int));

    $ins = db()->prepare(
        'INSERT INTO exercises
            (owner_user_id, name, block_type, description, materials, duration_min, intensity, space)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $ins->execute([
        $userId, $nombre, $tipo, mb_substr(param('description'), 0, 400),
        mb_substr(param('materials'), 0, 200), $dur, $int, $esp,
    ]);

    return [
        'id' => (int) db()->lastInsertId(), 'name' => $nombre, 'block_type' => $tipo,
        'description' => param('description'), 'materials' => param('materials'),
        'duration_min' => $dur, 'intensity' => $int, 'space' => $esp, 'diagram' => null,
    ];
}

/** Corta la petición si el ejercicio no existe o no es de esta cuenta. */
function exige_ejercicio_propio(int $id, int $userId): void
{
    $st = db()->prepare('SELECT id FROM exercises WHERE id = ? AND owner_user_id = ?');
    $st->execute([$id, $userId]);
    if (!$st->fetch()) {
        fail('Ese ejercicio no está en tu biblioteca.', 403);
    }
}

/** El bloque, si es de un equipo de esta cuenta con nivel para
 *  planificar; si no, corta la petición aquí mismo. */
function bloque_propio(int $blockId, int $userId): array
{
    $st = db()->prepare(
        'SELECT sb.id, sb.block_type, s.team_id
           FROM session_blocks sb
           JOIN sessions s ON s.id = sb.session_id
          WHERE sb.id = ?'
    );
    $st->execute([$blockId]);
    $bloque = $st->fetch();

    if (!$bloque) {
        fail('Ese bloque no es tuyo.', 403);
    }
    exige_acceso((int) $bloque['team_id'], $userId, ['propietario', 'staff']);

    return $bloque;
}
