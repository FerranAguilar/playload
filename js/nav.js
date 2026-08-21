/* ═══════════════════════════════════════════════════════════════════
   PlayLoad · barra lateral, en un solo sitio
   ───────────────────────────────────────────────────────────────────
   Antes cada pantalla llevaba su propia copia del <aside class="rail">,
   así que un enlace nuevo —o uno que cambiaba de sitio— había que
   tocarlo en las siete páginas a la vez, y era fácil dejarse alguna
   (así se quedaron "Temporada" y "Microciclos" muertos en cinco de
   ellas). Ahora el HTML vive aquí, una vez, y cada página solo pone
   el hueco: <aside class="rail" id="rail"></aside>.

   Se pinta con un <script src="js/nav.js"> normal, sin defer ni async,
   justo donde antes iba el <aside> completo: el navegador lo ejecuta
   en cuanto lo encuentra, con el hueco ya en el DOM, así que no hay
   parpadeo de barra vacía. Es el mismo patrón que ya usan
   sesion.js y cuentas.js.

   Qué página está activa se decide solo, comparando el nombre de
   archivo actual con el href de cada enlace — nadie tiene que marcar
   aria-current a mano nunca más.

   El botón de contraer/expandir guarda su estado en localStorage y lo
   marca con data-rail="contraido" en <html>, para que el CSS de cada
   página (que sigue siendo suyo, no de aquí) lo lea igual que ya lee
   data-theme. Se aplica nada más pintar la barra, antes de que se vea
   nada del resto: no hay parpadeo de la barra ancha.
   ═══════════════════════════════════════════════════════════════════ */
(function(){
  const PAGINA   = location.pathname.split('/').pop();
  const RAIL_KEY = 'pl-rail';

  function railGuardado(){
    try { return localStorage.getItem(RAIL_KEY) || 'expandido'; } catch(e){ return 'expandido'; }
  }
  function railAplicar(v){
    if(v === 'contraido') document.documentElement.setAttribute('data-rail', 'contraido');
    else document.documentElement.removeAttribute('data-rail');
    try { localStorage.setItem(RAIL_KEY, v); } catch(e){}
  }

  const NAV_HTML = `
    <a class="rail-top" href="PlayLoad-dashboard.html" title="Ir al panel">
      <span class="mark">PL</span>
      <span>PlayLoad</span>
    </a>
    <button class="rail-toggle" id="railToggle" type="button">
      <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M10 3.5 5.5 8l4.5 4.5"/></svg>
    </button>

    <a class="club" href="PlayLoad-equipos.html" title="Cambiar de equipo" style="color:inherit;text-decoration:none">
      <span class="club-badge" id="clubBadge">··</span>
      <span class="club-text">
        <span class="club-name" style="display:block" id="clubName">Cargando…</span>
        <span class="club-sub" style="display:block" id="clubSub"></span>
      </span>
      <svg style="margin-left:auto;width:13px;height:13px;color:var(--muted-2)" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 6.5 8 3.5l3 3M5 9.5l3 3 3-3"/></svg>
    </a>

    <nav class="nav">
      <div class="nav-group">General</div>
      <a class="nav-item" href="PlayLoad-dashboard.html">
        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.4"><rect x="2" y="2" width="5" height="5" rx="1"/><rect x="9" y="2" width="5" height="5" rx="1"/><rect x="2" y="9" width="5" height="5" rx="1"/><rect x="9" y="9" width="5" height="5" rx="1"/></svg>
        <span>Panel</span>
      </a>
      <a class="nav-item" href="PlayLoad-equipos.html">
        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"><path d="M2.2 13.4V12A2.6 2.6 0 0 1 4.8 9.4h2A2.6 2.6 0 0 1 9.4 12v1.4"/><circle cx="5.8" cy="5" r="2.4"/><path d="M11 9.6c1.6.3 2.8 1.3 2.8 2.7v1.1"/><circle cx="11.6" cy="5.6" r="1.9"/></svg>
        <span>Equipos</span>
        <span class="nav-count" id="navEquipos">0</span>
      </a>
      <a class="nav-item" href="PlayLoad-calendario.html">
        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"><rect x="2" y="3.2" width="12" height="11" rx="2"/><path d="M2 6.6h12M5.4 1.6v3M10.6 1.6v3"/></svg>
        <span>Calendario</span>
      </a>

      <div class="nav-group">Planificación</div>
      <a class="nav-item" href="PlayLoad-temporada.html">
        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><path d="M3 13V8.5M8 13V3.5M13 13V6.5"/></svg>
        <span>Temporada</span>
      </a>
      <a class="nav-item" href="PlayLoad-microciclos.html">
        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"><path d="M8 2.2 14 5.6 8 9 2 5.6z"/><path d="M2 10.4 8 13.8l6-3.4"/></svg>
        <span>Microciclos</span>
      </a>
      <a class="nav-item" href="PlayLoad-sesiones.html">
        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"><rect x="3" y="3" width="10" height="11" rx="2"/><path d="M6 2h4v2.6H6z" fill="none"/><path d="M6 8.4h4M6 11h2.6"/></svg>
        <span>Sesiones</span>
        <span class="nav-count" id="navSesiones"></span>
      </a>
      <a class="nav-item" href="PlayLoad-biblioteca.html">
        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"><rect x="2" y="3" width="4.2" height="4.2" rx="1"/><rect x="2" y="9" width="4.2" height="4.2" rx="1"/><path d="M8.4 5.1H14M8.4 11.1H14"/></svg>
        <span>Biblioteca de tareas</span>
        <span class="nav-count" id="navTareas"></span>
      </a>

      <div class="nav-group">Equipo</div>
      <a class="nav-item" href="PlayLoad-equipos.html">
        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"><circle cx="6" cy="5.4" r="2.5"/><path d="M1.8 13.4c0-2.2 1.9-3.6 4.2-3.6s4.2 1.4 4.2 3.6"/><path d="M11 9.6c1.6.3 2.8 1.3 2.8 2.7v1.1"/><circle cx="11.6" cy="5.6" r="1.9"/></svg>
        <span>Plantilla</span>
        <span class="nav-count" id="navPlantilla">0</span>
      </a>
      <a class="nav-item" href="#">
        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"><circle cx="8" cy="5.2" r="2.7"/><path d="M2.8 14c0-2.7 2.3-4.4 5.2-4.4s5.2 1.7 5.2 4.4"/></svg>
        <span>Perfiles de jugador</span>
      </a>
      <a class="nav-item" href="#">
        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"><path d="M2.4 4.4 3.9 5.9 6.4 3.4M2.4 11 3.9 12.5 6.4 10M8.8 4.9h4.8M8.8 11.5h4.8"/></svg>
        <span>Convocatorias</span>
      </a>
      <a class="nav-item" href="#">
        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"><circle cx="8" cy="8" r="5.8"/><path d="M8 5.2v5.6M5.2 8h5.6"/></svg>
        <span>Parte médico</span>
      </a>

      <div class="nav-group">Control de carga</div>
      <a class="nav-item" href="#panelCarga" data-wellness>
        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"><path d="M8 13.4S2.2 10 2.2 6.3A3.1 3.1 0 0 1 8 4.7a3.1 3.1 0 0 1 5.8 1.6c0 3.7-5.8 7.1-5.8 7.1z"/></svg>
        <span>Wellness</span>
        <span class="nav-count" id="navWell"></span>
      </a>
      <a class="nav-item" href="#panelCarga">
        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"><path d="M2.4 12.2a5.8 5.8 0 1 1 11.2 0"/><path d="M8 12.2 10.9 7"/></svg>
        <span>RPE por sesión</span>
      </a>
      <a class="nav-item" href="#">
        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"><circle cx="8" cy="6.8" r="2.1"/><path d="M8 14.2s4.4-3.9 4.4-7a4.4 4.4 0 1 0-8.8 0c0 3.1 4.4 7 4.4 7z"/></svg>
        <span>Carga externa · GPS</span>
      </a>

      <div class="nav-group">Informes</div>
      <a class="nav-item" href="#">
        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"><path d="M4 1.8h5l3.4 3.4v9H4z"/><path d="M9 1.8v3.4h3.4M6.4 8.6h3.6M6.4 11h3.6"/></svg>
        <span>Informes</span>
      </a>
      <a class="nav-item" href="#">
        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"><path d="M8 10.2V2.4M5.3 5.1 8 2.4l2.7 2.7"/><path d="M2.6 9.6v4h10.8v-4"/></svg>
        <span>Exportación</span>
      </a>

      <div class="nav-group">Club</div>
      <a class="nav-item" href="#">
        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.4"><circle cx="4.2" cy="4.2" r="1.9"/><circle cx="11.8" cy="6.2" r="1.9"/><circle cx="6.2" cy="11.8" r="1.9"/><path d="M6 4.6 10 5.7M4.8 6 5.7 9.9" stroke-linecap="round"/></svg>
        <span>Integraciones</span>
      </a>
      <a class="nav-item" href="#">
        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"><path d="M8 1.9 13.4 4.1v4.1c0 3-2.4 5.1-5.4 5.9-3-.8-5.4-2.9-5.4-5.9V4.1z"/></svg>
        <span>Roles y permisos</span>
      </a>
      <a class="nav-item" href="#">
        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"><circle cx="8" cy="8" r="2.4"/><path d="M8 1.6v2M8 12.4v2M1.6 8h2M12.4 8h2M3.5 3.5l1.4 1.4M11.1 11.1l1.4 1.4M12.5 3.5l-1.4 1.4M4.9 11.1l-1.4 1.4"/></svg>
        <span>Ajustes del club</span>
      </a>
    </nav>

    <div class="rail-foot">
      <div class="me">
        <span class="avatar" id="meAvatar">··</span>
        <span class="me-text">
          <div class="me-name" id="meName">…</div>
          <div class="me-role" id="meRole"></div>
        </span>
      </div>
    </div>
  `;

  const mount = document.getElementById('rail');
  if(!mount) return;
  mount.innerHTML = NAV_HTML;

  /* Solo el primer enlace cuyo href sea esta página: "Equipos" y
     "Plantilla" apuntan las dos a PlayLoad-equipos.html, y ahí gana la
     primera — es la que ya llevaba el resaltado antes de este archivo. */
  for (const a of mount.querySelectorAll('.nav-item')) {
    if ((a.getAttribute('href') || '') === PAGINA) {
      a.setAttribute('aria-current', 'page');
      break;
    }
  }

  railAplicar(railGuardado());
  const toggle = mount.querySelector('#railToggle');
  toggle.setAttribute('aria-label', 'Contraer u expandir el menú');
  toggle.title = 'Contraer u expandir el menú';
  toggle.addEventListener('click', () => {
    const contraido = document.documentElement.getAttribute('data-rail') === 'contraido';
    railAplicar(contraido ? 'expandido' : 'contraido');
  });
})();
