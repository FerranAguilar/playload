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
| `api/player_login.php` | POST | Acceso del jugador con el enlace que le mandó el club |
| `api/invitacion.php` | GET | Qué correo hay detrás de un enlace de invitación |
| `api/subir_escudo.php` | POST | El escudo del club, en `multipart/form-data` |
| `api/app.php` `quitar_escudo` | POST | Borra el escudo, de la base y del disco |
| `api/app.php?action=equipo` | GET | Un equipo con su plantilla completa; el nivel de acceso viene en `acceso` |
| `api/app.php` `editar_jugador` | POST | Cambia la ficha de un jugador |
| `api/app.php` `invitar_jugador` | POST | Manda (o reenvía) el enlace de acceso por correo |
| `api/app.php?action=sesion` | GET | Una sesión con sus bloques |
| `api/app.php` `editar_sesion` | POST | Cambia fecha, hora, título, tipo, MD y lugar |
| `api/app.php` `guardar_bloques` | POST | Sustituye los bloques y recalcula duración y carga |
| `api/app.php` `duplicar_sesion` | POST | Copia la sesión y sus bloques a otra fecha |
| `api/app.php` `borrar_sesion` | POST | Borra la sesión, sus bloques y sus RPE |
| `api/app.php?action=rpe_sesion` | GET | La plantilla de una sesión con el RPE que ya tenga cada uno |
| `api/app.php` `guardar_rpe` | POST | Guarda el RPE jugador a jugador y rehace la carga de la sesión |
| `api/app.php?action=wellness_dia` | GET | La plantilla con el wellness de un día |
| `api/app.php` `guardar_wellness` | POST | Guarda el wellness del día |
| `api/app.php?action=carga_plantilla` | GET | Carga de la semana, ACWR y wellness de cada jugador |
| `api/app.php?action=club` | GET | Equipos del club con su staff y las licencias gastadas |
| `api/app.php` `invitar_staff` | POST | Da acceso a un correo en un equipo del club, y le avisa por correo |
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

Ni el control de carga ni las sesiones por bloques traen **migración
nueva**: `rpe_entries`, `wellness_entries` y `session_blocks` ya venían
con la 03, esperando pantalla. Si en su día importaste esa, no hay nada
que hacer.

La ficha del jugador sí trae una: `db/migracion-09-jugador-perfil.sql`
añade sus columnas nuevas a `players`. Sin ella, el alta y la ficha se
siguen abriendo —nombre, dorsal y posición se guardan igual, en vez de
romper la pantalla por columnas que no existen todavía— pero lo que se
escriba en los campos nuevos no llega a ninguna parte, y `invitar_jugador`
falla, porque necesita el correo y el estado que trae esa migración.

El acceso por correo trae otra más: `db/migracion-10-acceso-por-correo.sql`
quita `access_code` —ya no hace falta, y era `NOT NULL`— y añade
`login_token_hash`. Hasta que se importe, dar de alta a un jugador sigue
generando un código como antes (`crear_jugador` cae a ese camino en
cuanto el de siempre falla), porque la base todavía lo exige; y
`invitar_jugador` y `player_login.php`, que dependen enteros de
`login_token_hash`, no funcionan. `invitar_jugador` lo dice con un
mensaje concreto en vez de romperse en silencio —antes, sin esta
migración, el navegador solo veía «el servidor no ha contestado como se
esperaba», sin ninguna pista de qué faltaba—.

La última por ahora: `db/migracion-11-genero-equipo.sql` añade `gender`
a `teams`. Sin ella, crear y editar un equipo siguen funcionando —se
cae al `INSERT`/`UPDATE` de siempre, sin esa columna— pero el género que
se elija en el formulario no se guarda hasta que se importe.

## La sesión por dentro

Una sesión se monta por **bloques** (`session_blocks`): calentamiento,
tareas, competición reducida, vuelta a la calma. Cada uno lleva sus
minutos y una intensidad de 1 a 10.

De ahí salen los dos números de la sesión, y no se escriben a mano:

- `duration_min` es la **suma de los minutos**.
- `planned_load` es la **suma de minutos × intensidad** de cada bloque.
- `planned_rpe` es la media pesada por minutos, que es lo que ese
  número significaba desde el principio.

Mientras la sesión tenga bloques, `editar_sesion` **no toca** ni los
minutos ni la carga: si los tocara, se podría decir que dura 60 minutos
mientras los bloques suman 90, y entonces ninguno de los dos
significaría nada. Sin bloques sí, porque entonces no hay nada que los
contradiga.

Un bloque **sin intensidad** suma tiempo pero no carga: es lo que pasa
con una charla táctica o con la vuelta a la calma. Y un bloque sin
nombre no es un bloque: se descarta al guardar.

`guardar_bloques` recibe la lista entera y sustituye a la anterior.
Reordenar, borrar y añadir en la misma pasada sale más simple así que
llevando la cuenta de qué cambió. Si se queda sin ninguno, la sesión
pierde el plan pero conserva la duración: el hueco del calendario no lo
puso ningún bloque.

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

`invitar_staff` avisa por correo, en dos versiones según si ese correo
ya tiene cuenta en PlayLoad o no —lo mira antes de insertar la fila—.
Quien ya tiene cuenta recibe un enlace a `acceso.html`: entra con la de
siempre y el equipo nuevo ya está ahí. Quien no tiene cuenta recibe uno
a `registro.html?email=…`, con el correo puesto de antemano en el
formulario.

Ese enlace de alta **no verifica nada**: es solo un atajo que ahorra
escribir el correo. Quien reciba el aviso puede ignorarlo tranquilamente
y entrar por su cuenta más tarde —a `registro.html`, a mano, con **ese
mismo correo**—, porque lo que de verdad abre la puerta es la plaza que
espera en `team_staff`, que sirve de permiso de alta aunque el registro
esté cerrado y aunque ese correo no esté en `allowed_emails` (lo resuelve
`check_signup_allowed()`). El correo es la comodidad; la plaza es el
permiso.

Si el envío falla, la plaza se guarda igual: el `ok` de `invitar_staff`
trae aparte un `correo_enviado`, y si es `false` el panel del club lo
dice para que se avise a mano por otro lado.

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

| Nivel | Quién es | Equipo | Plantilla | Calendario y carga |
|---|---|---|---|---|
| `club` | dueño del club al que pertenece el equipo | escribe | escribe | **solo lee** |
| `propietario` | creó el equipo por su cuenta | escribe | escribe | escribe |
| `staff` | el club le dio el acceso | **solo lee** | escribe | escribe |

«Equipo» es nombre, categoría, sistema, color: los ajustes que casi
nunca cambian. «Plantilla» es la ficha de cada jugador —posición,
contacto, comentarios, invitación—, que se toca cada semana; por eso el
staff la escribe aunque no haya creado el equipo, mientras que los
ajustes le siguen quedando fuera.

`action=equipo` —la que abre la ficha de un equipo con su plantilla—
comprobaba la propiedad a mano en vez de llamar a `acceso_equipo()`, así
que el staff al que un club invita nunca podía abrirla: para él el
equipo existía en `action=estado` pero no en su propia ficha. Ahora usa
la misma función que todo lo demás, y el nivel viaja en la respuesta
como `acceso` para que `PlayLoad-equipos.html` sepa qué enseñar
editable y qué en solo lectura.

### La sección Plantillas del club: rejilla primero, ficha después

Antes se abría directamente la plantilla del primer equipo, con una
tira de chips arriba para cambiar de uno a otro. Ahora lo primero que
se ve es una rejilla con todos los equipos del club —mismo diseño de
tarjeta que la lista de `PlayLoad-equipos.html`, para que sea la misma
aplicación aunque sea otra pantalla—; clicar una abre su ficha, con un
enlace «← Todas las plantillas» para volver. Entrar en la sección desde
el menú vuelve siempre a la rejilla, aunque se hubiera dejado una ficha
abierta: es un estado de la interfaz, `plantillaLista`, que no depende
de qué equipo esté cargado por detrás.

La ficha abierta tiene botón **Editar equipo**: usa el mismo
`editar_equipo` que ya tenía `PlayLoad-equipos.html`, así que un cambio
de nombre o de categoría desde el club llega exactamente igual que si
lo hubiera hecho quien creó el equipo.

### Categoría libre, género con tres valores

La categoría es un select con la escalera habitual —prebenjamín a
sénior— y una opción **Otra…** que revela un campo de texto: cada
federación las llama a su manera, así que no hay catálogo cerrado que
imponer. El servidor no valida la categoría contra ninguna lista, igual
que ya pasaba con `position`: es la pantalla la que ofrece las
habituales, no la base la que las exige.

El género sí tiene tres valores fijos —masculino, femenino, mixto— y un
select normal, sin opción libre: a diferencia de la categoría, no hay
ambigüedad de federación que resolver ahí. Mismo patrón de columna que
`foot` o `position_alt`: `VARCHAR`, no `ENUM`, para no tener que tocar
la base el día que haga falta un cuarto valor.

### El escudo del club

`clubs.badge_url` viene en el esquema desde el principio —esperando
pantalla, como pasó con `session_blocks` o con los campos del jugador—
y ahora se sube desde «Datos del club».

Sube y quita son dos rutas distintas a propósito. Subir necesita leer
`$_FILES`, y el resto del API espera JSON en el cuerpo (lo lee
`body()`); mezclar los dos formatos en el mismo despachador de
`app.php` habría complicado cada acción para un caso que solo usa esta.
Por eso `api/subir_escudo.php` es un archivo aparte, con su propio
`require_method('POST')`. Quitar sí es una fila normal —solo pone
`badge_url` a NULL y borra el archivo— y vive en `app.php` como
`quitar_escudo`, con el resto.

Admite `.jpg`, `.png` y `.svg`, hasta 3 MB. Lo que decide el tipo es el
**contenido**, nunca la extensión ni el `Content-Type` que mande el
navegador —los dos se falsean sin esfuerzo—: `getimagesize()` para jpg
y png, que además de decir el tipo comprueba que el archivo es de
verdad una imagen y no una cabecera falseada. No `finfo_file()`: esa
función depende de una extensión de PHP que en según qué hosting
compartido puede no estar activa, y ahí toda la subida caía con un
error 500 sin ninguna pista de por qué; `getimagesize()` es del núcleo
del lenguaje, nada que activar aparte. El SVG es texto y
`getimagesize()` no lo reconoce como imagen, así que para ese caso se
mira si el contenido empieza de verdad como un SVG.

El nombre final tampoco sale de lo que suba el navegador: es
`bin2hex(random_bytes(16))` más la extensión que decidió el servidor
según el contenido. Así nunca es quien sube el archivo quien elige
dónde acaba escrito ni con qué extensión —cerrando de raíz el ataque
clásico de subir un `.php` disfrazado de imagen—.

Todo el archivo va dentro de un único `try`, con un `catch (Throwable)`
al final que convierte cualquier fallo no previsto en el JSON de
siempre en vez de en una página en blanco: en un hosting compartido
siempre puede faltar algo —una extensión, un permiso— que no se pueda
adivinar desde aquí. El detalle real solo se enseña si `debug` está
activo en `config.php`; si no, se queda en el registro de errores del
hosting y quien sube el escudo solo ve «No se ha podido procesar el
escudo».

El SVG, además, se sanea antes de guardarse: fuera `<script>`,
manejadores `on*` (`onload`, `onclick`…) y cualquier `javascript:` en
un atributo. No es un analizador de XML completo, va por expresiones
regulares sobre texto, pero es una capa más y no la única: el escudo
solo se enseña dentro de una etiqueta `<img>` en todas las pantallas
—nunca inyectado inline ni en un `<object>`—, que de por sí ya no
ejecuta nada de lo que haya dentro del archivo. `uploads/escudos/`
lleva además su propio `.htaccess` con el motor de PHP apagado, por si
alguna de las capas anteriores fallara.

Al reemplazar un escudo se borra el archivo anterior, para no ir
dejando huérfanos; al quitarlo, primero se limpia `badge_url` en la
base y solo después se borra el archivo, para que un borrado que
fallara deje una ficha sin escudo y no un escudo roto.

## La ficha del jugador

Nombre, dorsal y posición ya estaban. Se añaden posición alternativa,
pie, fecha de nacimiento, correo y unos comentarios que **solo ve el
cuerpo técnico y el club**: no hay pantalla de jugador que los enseñe,
así que no salen en ninguna respuesta que pudiera llegar a una. El
nombre sigue siendo el único obligatorio.

Se edita clicando la fila en la plantilla, tanto desde
`PlayLoad-equipos.html` (el entrenador) como desde la sección
Plantillas de `PlayLoad-club.html` (el club, que por la tabla de arriba
también escribe). La edita **todo el staff del equipo**, club incluido:
es trabajo del día a día, a diferencia de los ajustes del equipo
—nombre, categoría, sistema—, que siguen siendo solo de quien lo creó o
del club.

Añadir jugadores tiene un segundo botón, **Guardar y añadir otro
jugador**, que guarda el que hay y deja la ficha en blanco lista para
el siguiente: para dar de alta una plantilla entera de una sentada.

### El jugador entra por correo, no por código

Hasta la migración 10, el jugador entraba con un código de siete
caracteres que el club le dictaba. Ya no: `access_code` desaparece y el
acceso pasa a ser **exclusivamente por correo**. Con uno puesto en la
ficha aparece **Invitar al equipo**, que manda un enlace —no una
contraseña, no un código que copiar— a `acceso.html?v=player&ptoken=…`.
Ese enlace ES la credencial: `login_token_hash` guarda su hash, igual
que `password_resets` o `allowed_emails.token_hash`, así que quien lea
la tabla no puede entrar con lo que vea ahí.

El enlace **no caduca** —para poder guardarlo o añadirlo a la pantalla
de inicio del móvil y que siga sirviendo, como hacía el código— pero
**cambia cada vez que se manda un correo nuevo**: solo el último enlace
enviado vale, como el testigo de recuperación de contraseña. Por eso se
puede invitar aunque el jugador ya esté `registrado`: es como se le
manda un enlace nuevo a quien perdió el suyo, la única forma de
recuperar el acceso ahora que no hay nada que volver a dictar por
teléfono.

`invite_status` tiene tres valores. `sin_invitar` es el de siempre.
`invitado` es que se le mandó un correo y todavía no lo ha abierto.
`registrado` llega solo, la primera vez que ese enlace entra de verdad
en `player_login.php` —**registrarse es entrar, no que el enlace en
concreto siga siendo el mismo**—, y no se deshace si después se le manda
uno nuevo.

Si se corrige el correo mientras había una invitación esperando
(`invitado`), ese enlace se mandó a una dirección que ya no vale: el
estado vuelve a `sin_invitar` y `login_token_hash` se pone a NULL en una
consulta aparte, para que si esa columna todavía no existe —falta la
migración 10— no se lleve por delante el resto de la ficha, que sí
acababa de guardarse bien. Si el jugador ya está `registrado`, cambiar
el correo no le toca el acceso: entrar no depende del correo que haya
hoy en la ficha, y corregirlo después no debería cerrarle lo que ya
tiene.

`acceso.html` ya no tiene ningún formulario de código: la vista de
jugador solo sabe leer el `ptoken` de la URL y llamar sola a
`player_login.php` al cargar, mostrando «Entrando…» mientras comprueba
y el error si el enlace no vale. No hay manera de escribir nada a mano.

Todavía no hay pantalla de jugador de verdad más allá de eso: la
vista «Hola, Marc» a la que se llega tras entrar es un diseño con datos
de ejemplo. Mandar los formularios de RPE y wellness, y enseñar las
convocatorias y el calendario a quien haya entrado, es el siguiente
paso: la tabla `rpe_entries`/`wellness_entries` ya guarda por
`player_id`, así que cuando exista esa pantalla no hace falta tocar la
base.

## El diseño de los correos

`send_mail()` mandaba solo texto plano. Ahora admite un cuarto
argumento —`send_mail($para, $asunto, $texto, $html)`— y, si se le pasa,
manda las dos versiones a la vez (`multipart/alternative`): el texto
sigue yendo siempre, porque es lo que enseña un lector de pantalla o un
resumen de avisos cuando el HTML no llega. Los tres correos que ya
existían (el código en dos pasos, la recuperación de contraseña, las
invitaciones de `admin.html`) no tocan ese cuarto argumento y siguen
tal cual, en texto plano; el diseño nuevo es solo para las dos
invitaciones que se abren desde la plantilla —`invitar_jugador` e
`invitar_staff`—.

`correo_html($titulo, $párrafos, $botón, $nota)` construye la tarjeta:
la marca **PL** arriba, el título, los párrafos, un botón *blurple*
(`#9184d9`, el acento de toda la app) si hace falta uno, y una nota
pequeña al pie con la letra pequeña de siempre —qué hacer si el correo
no se esperaba—. Todo con estilos en línea y tablas, sin flexbox, sin
grid, sin `<style>`: un cliente de correo puede ser cualquier cosa,
Outlook de escritorio incluido, y ahí solo llega limpio lo más simple.
Vive en `api/bootstrap.php`, junto a `send_mail()`, para que cualquier
otro correo que se diseñe más adelante la reutilice sin escribir HTML
desde cero.

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
  `uid`, de modo que su enlace de acceso no abre el panel del staff.
- **El testigo del enlace de jugador se guarda en hash** (SHA-256), como
  los de recuperación y los de invitación: no se guarda en claro en
  ningún sitio, ni siquiera para poder reenviarlo.

## Lo que falta

- **El jugador no tiene pantalla de verdad.** Ya se le puede invitar por
  correo, y `player_login.php` marca cuándo entra por primera vez, pero
  al otro lado solo hay el diseño de ejemplo de `acceso.html`. El
  wellness y el RPE los pasa hoy el entrenador uno a uno, que funciona
  pero no escala: lo natural es que cada jugador conteste él, entrando
  con su enlace. Las tablas no cambiarían: `rpe_entries` y
  `wellness_entries` ya guardan por `player_id`.
- **El calendario cierra las sesiones con el RPE del grupo**, sin la
  lista jugador a jugador que sí tiene el panel. Mientras tanto, desde el
  panel se puede abrir cualquier sesión de la semana.
- **No hay biblioteca de tareas.** Los bloques se escriben cada vez; lo
  natural es poder guardarlos y arrastrarlos a la sesión. Es el enlace
  que sigue muerto en la barra lateral, y la tabla que falta.
- La verificación en dos pasos viene apagada (`two_factor = 0`). Para
  probarla, pon a 1 esa columna en tu usuario.
- No hay confirmación del correo en el alta: la cuenta entra directa.
