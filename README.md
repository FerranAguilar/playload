# PlayLoad

Gestión de equipos, planificación y control de carga para clubes y academias de fútbol.

Prototipo estático: HTML y CSS, sin dependencias ni paso de compilación. Se abre haciendo
doble clic en `index.html`.

## Páginas

| Archivo | Qué es |
|---|---|
| `index.html` | Portada pública. Presenta el producto y da entrada al panel desde *Iniciar sesión* y desde la prueba de 30 días. |
| `PlayLoad-dashboard.html` | Panel del equipo una vez dentro: carga del microciclo, sesión del día, plantilla, alertas e informe. |
| `PlayLoad-equipos.html` | Gestor de equipos del club. Rejilla de equipos y, al abrir uno, su ficha con plantilla, cuerpo técnico, calendario y ajustes. |
| `img/` | Fotografías de la portada. |

Las tres páginas están enlazadas entre sí; la marca **PlayLoad** de la barra lateral lleva
siempre al panel.

## Diseño

Todo el producto usa el sistema **Nocturne**: fondo índigo oscuro, acento *blurple*
`#9184d9`, tipografía Inter y las mismas escalas de espacio y radio. Las páginas de
aplicación suben el contraste del texto secundario respecto a la portada, porque se leen
de cerca y durante horas.

## Datos

Los datos que aparecen (CF Bellvitge, plantillas, cargas, wellness) son de ejemplo y viven
dentro de cada archivo. En `PlayLoad-equipos.html` están en la constante `TEAMS`, al
principio del bloque `<script>`.

## Fotografías

De [Pexels](https://www.pexels.com), con licencia de uso libre. Los identificadores de cada
foto están en un comentario al principio de `index.html`.
