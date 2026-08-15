# Acceso con base de datos en Hostinger

Cómo poner en marcha el acceso y el alta contra MySQL. Todo el backend es
PHP 8 sin dependencias: no hace falta Composer ni compilar nada.

> **Asunción**: hosting compartido de Hostinger, que trae Apache + PHP 8 +
> MySQL. Si estás en un VPS con Node, dímelo y lo reescribo; la parte de
> navegador no cambiaría.

## 1. Crear la base de datos

hPanel → **Bases de datos → Administración de bases de datos MySQL**.
Crea una base y un usuario, y **guarda la contraseña**: Hostinger no la
vuelve a enseñar. Los nombres quedan como `u123456789_playload` y
`u123456789_play`.

## 2. Importar el esquema

En la misma pantalla, **phpMyAdmin** → pestaña **Importar** → sube
`db/schema.sql` → *Continuar*. Deben aparecer nueve tablas: `users`,
`clubs`, `teams`, `memberships`, `password_resets`, `login_codes`,
`remember_tokens`, `login_attempts` y `players`.

## 3. Configurar la conexión

Copia `api/config.example.php` a `api/config.php` y rellena el bloque
`db` con los datos del paso 1. Desde el propio hosting, `host` es
`localhost`.

`api/config.php` está en `.gitignore` **a propósito**: contiene la
contraseña de la base de datos y no debe subirse nunca al repositorio.
Súbelo solo por el Administrador de archivos o por FTP.

## 4. Subir los archivos

Todo va a `public_html/`, manteniendo la estructura:

```
public_html/
  index.html  acceso.html  registro.html  perfil.html  precios.html  admin.html
  PlayLoad-dashboard.html  PlayLoad-equipos.html
  PlayLoad-calendario.html  PlayLoad-club.html
  .htaccess
  img/
  js/         ← no lo olvides: sin js/sesion.js las cuentas de club
                entran en las pantallas del entrenador
  api/        ← incluido config.php
  db/         ← opcional; el .htaccess bloquea los .sql
```

Comprueba que el certificado SSL está activo (hPanel → **Seguridad →
SSL**). El `.htaccess` fuerza HTTPS, y sin él las cookies de sesión, que
van marcadas `Secure`, no llegarían.

## 5. Probar

Abre `https://tudominio.com/registro.html` y crea una cuenta. Si todo
está bien, al terminar el paso 3 la fila aparece en la tabla `users` con
la contraseña ya cifrada. Después entra desde `acceso.html`.

Errores típicos:

| Qué ves | Qué pasa |
|---|---|
| «Falta api/config.php» | No copiaste el archivo del paso 3 |
| «No se puede conectar con la base de datos» | Usuario, contraseña o nombre mal; revisa que el usuario esté asignado a la base |
| El botón se queda en «Un momento…» | Mira el registro de errores en hPanel → **Avanzado → Registros de errores PHP** |

Para ver el detalle del fallo, pon `'debug' => true` en `config.php`
mientras lo arreglas, y **vuelve a ponerlo en false** al terminar.

## 6. Acceso con Google

1. [Google Cloud Console](https://console.cloud.google.com/) → crea un
   proyecto → **Credenciales → Crear credenciales → ID de cliente de
   OAuth** → tipo *Aplicación web*.
2. En **Orígenes autorizados de JavaScript** pon `https://tudominio.com`.
3. Copia el ID de cliente a dos sitios: `google_client_id` en
   `api/config.php`, y la constante `GOOGLE_CLIENT_ID` al principio del
   `<script>` de `acceso.html`.

Con eso, `acceso.html` carga la biblioteca de Google y dibuja **su botón
oficial** en lugar del de demostración. El token que devuelve el
navegador se verifica siempre en el servidor (`api/google.php`): emisor,
destinatario y caducidad. Un token sin verificar es solo texto que envía
el cliente.

## 7. Correo

`forgot.php`, la verificación en dos pasos y **las invitaciones del panel
de administración** usan `mail()` de PHP. En Hostinger funciona si
`mail_from` es un buzón real de tu dominio (hPanel → **Correos**). Con un
remitente de Gmail o inventado, los mensajes se marcan como spam o se
rechazan.

Comprueba también que `app_url` en `api/config.php` es tu dominio real:
es lo que se pega delante del enlace de invitación, y si está mal el
correo sale con un enlace que no lleva a ninguna parte.

## Qué hace cada endpoint

| Archivo | Método | Para qué |
|---|---|---|
| `api/register.php` | POST | Alta: crea la cuenta y su espacio (club o personal) |
| `api/login.php` | POST | Entrada con correo y contraseña |
| `api/verify_code.php` | POST | Segundo paso, si la cuenta tiene `two_factor` |
| `api/google.php` | POST | Verifica el token de Google y entra |
| `api/forgot.php` | POST | Envía el enlace de recuperación |
| `api/reset.php` | POST | Guarda la contraseña nueva |
| `api/me.php` | GET | Quién ha iniciado sesión; reanuda «mantener la sesión» |
| `api/logout.php` | POST | Cierra la sesión |
| `api/player_login.php` | POST | Acceso del jugador con el código del club |
| `api/invitacion.php` | GET | Qué correo hay detrás de un enlace de invitación |
| `api/app.php?action=rpe_sesion` | GET | La plantilla de una sesión con el RPE que ya tenga cada uno |
| `api/app.php` `guardar_rpe` | POST | Guarda el RPE jugador a jugador y rehace la carga de la sesión |
| `api/app.php?action=wellness_dia` | GET | La plantilla con el wellness de un día |
| `api/app.php` `guardar_wellness` | POST | Guarda el wellness del día |
| `api/app.php?action=carga_plantilla` | GET | Carga de la semana, ACWR y wellness de cada jugador |
| `api/app.php?action=club` | GET | Equipos del club con su staff y las licencias gastadas |
| `api/app.php` `invitar_staff` | POST | Da acceso a un correo en un equipo del club |
| `api/app.php` `quitar_staff` | POST | Retira ese acceso |
| `api/app.php` `editar_club` | POST | Nombre y ciudad del club |
| `api/app.php` `crear_mensaje` | POST | El staff deja un aviso al club |
| `api/app.php` `leer_mensaje` | POST | El club lo da por leído |

Las tablas del staff llegan con `db/migracion-05-staff.sql`, el plan de
las cuentas invitadas con `db/migracion-06-plan-staff.sql`, el enlace de
invitación con `db/migracion-07-invitaciones.sql` y los avisos con
`db/migracion-08-avisos.sql`. Impórtalas desde phpMyAdmin igual que las
anteriores y en ese orden; sin la 05 la pantalla del club lo dice, sin la
07 el panel invita como antes sin mandar nada, y sin la 08 el panel del
club enseña el día pero no los avisos. El resto sigue funcionando.

El control de carga **no trae migración nueva**: `rpe_entries` y
`wellness_entries` ya venían con la 03, esperando pantalla. Si en su día
importaste esa, no hay nada que hacer.

## Control de carga

La carga se mide en unidades arbitrarias: **RPE × minutos**. Hay dos
formas de cerrar una sesión y conviven a propósito.

La rápida es `cerrar_sesion`: un RPE para todo el grupo. Sirve para salir
del paso, pero con un solo número no se puede decir nada de nadie en
concreto.

La buena es `guardar_rpe`: el RPE de cada jugador, y los minutos de quien
no hizo la sesión entera. De ahí sale todo lo demás. La carga de la
sesión pasa a ser la **media** de lo que le costó a cada uno, no la suma:
así se puede comparar con la prevista —que es la de un jugador
cualquiera— y no crece sola al fichar gente. Quien no entrenó se queda
sin fila, que no es lo mismo que un cero.

Con eso, `carga_plantilla` responde lo que enseña la tabla del panel:

- **Carga aguda**: lo que lleva cada uno en siete días.
- **Carga crónica**: su mes dividido entre cuatro, o sea su semana media.
- **ACWR**: aguda entre crónica. Por encima de **1,3** se sube demasiado
  deprisa; por debajo de **0,8** se está perdiendo lo acumulado. Sin un
  mes detrás no se calcula: con dos sesiones sueltas sale un número
  enorme que asusta sin motivo.

El **wellness** son cuatro preguntas de 1 a 5 —sueño, fatiga, agujetas,
estrés— y las cuatro **en el mismo sentido: 5 es lo bueno**. Mezclar
sentidos es el error clásico de estos cuestionarios y deja la media sin
significar nada. Hacen falta las cuatro para que la fila cuente; a medias
se borra lo que hubiera.

De momento lo pasa el entrenador desde el panel, porque el jugador
todavía no tiene pantalla propia. `player_login.php` ya existe: cuando la
tenga, escribirá en estas mismas tablas y el panel no se entera.

## Clubes, staff y licencias

Hay dos formas de usar PlayLoad y no se parecen.

Una **cuenta profesional** lleva sus equipos: los crea, sube la plantilla
y planifica. Cada equipo suyo le gasta una licencia de su plan.

Una **cuenta de club** no entrena. Crea los equipos del club, sube las
plantillas y reparte el acceso: escribe el correo de un entrenador y lo
mete en un equipo. Ese correo **no necesita tener cuenta**; la fila queda
en `team_staff` con `status = 'invitado'` y se ata sola (`user_id`,
`status = 'activo'`) la primera vez que alguien entra con él, ya sea
dándose de alta o abriendo la aplicación.

Quien recibe el acceso **entra con todas las funciones**, pero solo en
los equipos que el club le ha dado.

Las dos reglas que sostienen el negocio:

- **El club paga por pareja persona-equipo.** Quien lleva tres
  categorías gasta tres licencias del plan del club. Se cuenta en
  `staff_count()` y el límite sale de la columna `staff` de `plans`.
- **Los equipos de un club no le cuentan al entrenador.** `team_count()`
  mira `owner_user_id`, no `team_staff`: quien entrena tres categorías de
  un club y además quiere la suya propia solo paga por la suya.

### Cómo entra alguien a quien un club ha dado una plaza

No se le manda ningún enlace. Va a `registro.html` y crea la cuenta con
**ese mismo correo**; la plaza que le espera en `team_staff` le sirve de
permiso de alta aunque el registro esté cerrado y aunque no esté en
`allowed_emails` (lo resuelve `check_signup_allowed()`).

Esa cuenta nace con el plan **`staff`**, que permite **cero equipos
propios**: lo que le han dado es acceso a los del club, no una licencia.
Si algún día quiere los suyos, paga un plan normal y los del club siguen
sin contarle.

Es distinto de la lista de pruebas cerradas. `allowed_emails` la lleva el
administrador desde `admin.html`, y al añadir un correo **se le manda un
enlace**: `registro.html?inv=…`. Ese enlace hace tres cosas a la vez —
avisa a la persona, le trae el correo ya puesto y fijo en el formulario,
y sirve de verificación, así que la cuenta nace con `email_verified = 1`
sin ningún paso extra. Caduca a los 14 días y solo vale el último
enviado; el testigo se guarda en hash, como en `password_resets`.

Si el envío falla, la fila se queda igual: esa persona puede registrarse
a mano y desde el panel se le puede **reenviar** el enlace. Las
invitaciones anteriores a la migración 07 salen como «Sin enviar» y
tienen su botón para mandarles uno.

Las dos formas de usar PlayLoad tienen pantallas distintas y no se
mezclan: `js/sesion.js` devuelve a su panel a la cuenta de club que caiga
en una del entrenador. Lo hace antes de pintar, porque enseñar un menú
que hay que retirar es peor que esperar un momento; para eso recuerda el
tipo de cuenta en el navegador. Esa copia solo decide **qué enseñar** —
quién puede **hacer** qué lo sigue diciendo el servidor, equipo por
equipo, en la tabla de abajo.

Quién puede qué en un equipo lo decide `acceso_equipo()`, que devuelve
tres niveles. El orden importa: **el club se mira antes que la
propiedad**, porque los equipos que crea una cuenta de club quedan a su
nombre y si no se haría pasar por propietaria.

| Nivel | Quién es | Equipo y plantilla | Calendario y carga |
|---|---|---|---|
| `club` | dueño del club al que pertenece el equipo | escribe | **solo lee** |
| `propietario` | creó el equipo por su cuenta | escribe | escribe |
| `staff` | el club le dio el acceso | solo lee | escribe |

## Decisiones de seguridad

- **Contraseñas** con `password_hash()` (bcrypt por defecto) y rehash
  automático si PHP sube el coste. Nunca se guardan en claro ni se
  registran.
- **No se revela qué correos existen**: `forgot.php` responde 200 siempre
  y el error de acceso es el mismo para correo inexistente que para
  contraseña incorrecta. La comparación de hash se hace también cuando la
  cuenta no existe, para que el tiempo de respuesta no lo delate.
- **Freno a la fuerza bruta**: ocho fallos por correo o por IP en quince
  minutos y se responde 429.
- **Testigos guardados en hash** (SHA-256): quien lea las tablas de
  recuperación, de códigos o de sesiones recordadas no puede usarlos.
- **Cookies** `HttpOnly`, `Secure` y `SameSite=Lax`; el identificador de
  sesión se regenera al entrar.
- **Consultas preparadas** en todo (PDO, sin emulación), así que no hay
  hueco para inyección SQL.
- **El jugador entra en una sesión distinta**: guarda `player_id`, nunca
  `uid`, de modo que un código de jugador no abre el panel del staff.

## Lo que falta

- **El jugador no tiene pantalla.** El wellness y el RPE los pasa hoy el
  entrenador uno a uno, que funciona pero no escala: lo natural es que
  cada jugador entre con su código —`player_login.php` ya lo permite— y
  contestara él. Las tablas no cambiarían.
- **El calendario cierra las sesiones con el RPE del grupo**, sin la
  lista jugador a jugador que sí tiene el panel. Mientras tanto, desde el
  panel se puede abrir cualquier sesión de la semana.
- La verificación en dos pasos viene apagada (`two_factor = 0`). Para
  probarla, pon a 1 esa columna en tu usuario.
- No hay confirmación del correo en el alta: la cuenta entra directa.
