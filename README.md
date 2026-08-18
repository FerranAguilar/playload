# PlayLoad

Gestión de equipos, planificación y control de carga para clubes y academias de fútbol.

Prototipo estático: HTML y CSS, sin dependencias ni paso de compilación. Se abre haciendo
doble clic en `index.html`.

## Páginas

| Archivo | Qué es |
|---|---|
| `index.html` | Portada pública. Presenta el producto y da entrada al panel desde *Iniciar sesión* y desde la prueba de 30 días. |
| `PlayLoad-dashboard.html` | Panel del equipo una vez dentro: carga del microciclo, sesión del día, control de carga jugador a jugador, alertas e informe. Desde aquí se pasa el RPE de una sesión y el wellness del día. |
| `PlayLoad-equipos.html` | Gestor de equipos del club. Rejilla de equipos y, al abrir uno, su ficha con plantilla, cuerpo técnico, calendario y ajustes. Clicar un jugador abre su ficha completa —posición alternativa, pie, nacimiento, correo, comentarios del staff— editable por todo el equipo técnico; con correo puesto, se le puede invitar por email, que es la única forma de entrar. Los ajustes del equipo siguen siendo solo de quien lo creó o del club. |
| `PlayLoad-club.html` | Lo que ve una cuenta de club: un panel con el día de todos sus equipos y los avisos del staff, y luego plantillas, calendarios, staff y perfil del club. Plantillas muestra primero una rejilla con todos los equipos; clicar uno abre su ficha, con botón para editar nombre, categoría, género y demás. Perfil del club deja subir su escudo (.jpg, .png o .svg). El club no entrena: gestiona y mira, así que los calendarios de aquí son de solo lectura; la plantilla no, y la ficha del jugador —y su invitación por correo— se abren igual que en el gestor de equipos. |
| `PlayLoad-sesiones.html` | La sesión por dentro. A la izquierda todas las del equipo; al abrir una, se monta por bloques —calentamiento, tareas, vuelta a la calma— con sus minutos y su intensidad. De los bloques salen la duración y la carga prevista, que dejan de escribirse a mano. También se duplica, se edita y se cierra desde aquí. |
| `PlayLoad-calendario.html` | Calendario del equipo. Lo primero es la semana: siete columnas con las sesiones de cada día, su carga al pie y el total del microciclo. Debajo, lo que viene después y el mes en miniatura para saltar de semana. |
| `img/` | Fotografías de la portada. |

Las páginas de aplicación están enlazadas entre sí; la marca **PlayLoad** de la barra lateral lleva
siempre al panel.

## Diseño

Todo el producto usa el sistema **Nocturne**: fondo índigo oscuro, acento *blurple*
`#9184d9`, tipografía Inter y las mismas escalas de espacio y radio. Las páginas de
aplicación suben el contraste del texto secundario respecto a la portada, porque se leen
de cerca y durante horas.

## Datos

Las páginas de aplicación leen y escriben en `api/app.php`, contra la base de datos de
verdad; ver [BACKEND.md](BACKEND.md). Solo se ve un dato de ejemplo cuando el archivo se
abre con doble clic, sin servidor por detrás (`DEMO` en cada `<script>`): entonces se
enseña el estado inicial, vacío, en vez de intentar hablar con un API que no existe.

## Fotografías

De [Pexels](https://www.pexels.com), con licencia de uso libre. Los identificadores de cada
foto están en un comentario al principio de `index.html`.
