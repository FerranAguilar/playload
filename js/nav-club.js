/* ═══════════════════════════════════════════════════════════════════
   PlayLoad · barra lateral de la cuenta de club
   ───────────────────────────────────────────────────────────────────
   Hermano de js/nav.js pero para PlayLoad-club.html: el menú de una
   cuenta de club es distinto del de un entrenador (secciones dentro de
   la misma página, no enlaces a otros archivos), así que va en su
   propio archivo en vez de mezclarse con el otro.

   A diferencia de nav.js, aquí NO se decide qué enlace está activo:
   club.html cambia de sección por hash (#panel, #plantillas…) dentro
   de la misma página, y su propia función irA() ya se encarga de
   mover el aria-current cuando cambia de sección. Este archivo solo
   pone el HTML en su sitio.

   El botón de contraer/expandir es el mismo mecanismo que en nav.js:
   data-rail="contraido" en <html>, guardado en localStorage.
   ═══════════════════════════════════════════════════════════════════ */
(function(){
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
    <a class="rail-top" href="PlayLoad-club.html" title="Ir al club">
      <span class="mark">PL</span>
      <span>PlayLoad</span>
    </a>
    <button class="rail-toggle" id="railToggle" type="button">
      <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M10 3.5 5.5 8l4.5 4.5"/></svg>
    </button>

    <div class="club">
      <span class="club-badge" id="clubBadge">··</span>
      <span class="club-text">
        <span class="club-name" style="display:block" id="clubName">Cargando…</span>
        <span class="club-sub" style="display:block" id="clubSub"></span>
      </span>
    </div>

    <nav class="nav">
      <div class="nav-group">Club</div>

      <a class="nav-item" href="#panel" data-sec="panel">
        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.4"><rect x="2" y="2" width="5" height="5" rx="1"/><rect x="9" y="2" width="5" height="5" rx="1"/><rect x="2" y="9" width="5" height="5" rx="1"/><rect x="9" y="9" width="5" height="5" rx="1"/></svg>
        <span>Panel</span>
        <span class="nav-count" id="navAvisos" hidden>0</span>
      </a>

      <a class="nav-item" href="#plantillas" data-sec="plantillas">
        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"><circle cx="6" cy="5.4" r="2.5"/><path d="M1.8 13.4c0-2.2 1.9-3.6 4.2-3.6s4.2 1.4 4.2 3.6"/><path d="M11 6.3a2.2 2.2 0 0 0 0-3.5M12.4 13.4c0-1.6-.6-2.7-1.6-3.3"/></svg>
        <span>Plantillas</span>
        <span class="nav-count" id="navJugadores">0</span>
      </a>

      <a class="nav-item" href="#calendarios" data-sec="calendarios">
        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"><rect x="2" y="3.2" width="12" height="11" rx="2"/><path d="M2 6.6h12M5.4 1.6v3M10.6 1.6v3"/></svg>
        <span>Calendarios</span>
        <span class="nav-count" id="navEquipos">0</span>
      </a>

      <a class="nav-item" href="#staff" data-sec="staff">
        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"><path d="M2.2 13.4V12A2.6 2.6 0 0 1 4.8 9.4h2A2.6 2.6 0 0 1 9.4 12v1.4"/><circle cx="5.8" cy="5" r="2.4"/><path d="M11 9.6c1.6.3 2.8 1.3 2.8 2.7v1.1"/><circle cx="11.6" cy="5.6" r="1.9"/></svg>
        <span>Staff</span>
        <span class="nav-count" id="navStaff">0</span>
      </a>

      <a class="nav-item" href="#perfil" data-sec="perfil">
        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"><path d="M8 1.9 13.4 4.1v4.1c0 3-2.4 5.1-5.4 5.9-3-.8-5.4-2.9-5.4-5.9V4.1z"/></svg>
        <span>Perfil del club</span>
      </a>
    </nav>

    <div class="rail-foot">
      <div class="me">
        <span class="avatar" id="meAvatar">··</span>
        <span class="me-text">
          <div class="me-name" id="meName">…</div>
        </span>
      </div>
    </div>
  `;

  const mount = document.getElementById('rail');
  if(!mount) return;
  mount.innerHTML = NAV_HTML;

  railAplicar(railGuardado());
  const toggle = mount.querySelector('#railToggle');
  toggle.setAttribute('aria-label', 'Contraer u expandir el menú');
  toggle.title = 'Contraer u expandir el menú';
  toggle.addEventListener('click', () => {
    const contraido = document.documentElement.getAttribute('data-rail') === 'contraido';
    railAplicar(contraido ? 'expandido' : 'contraido');
  });
})();
