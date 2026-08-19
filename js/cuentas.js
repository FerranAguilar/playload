/* ═══════════════════════════════════════════════════════════════════
   PlayLoad · selector de cuentas del avatar
   ───────────────────────────────────────────────────────────────────
   El menú del avatar (barra lateral, abajo) puede tener varias cuentas
   enlazadas en este navegador —una de club, otra de entrenador, por
   ejemplo— y cambiar entre ellas sin volver a escribir la contraseña,
   como el selector de cuentas de Instagram.

   Cada página de panel construye su propio popover `.umenu` (no hay
   plantilla compartida en este proyecto). Este módulo solo pone en
   común lo que sí es igual en todas: pedir la lista de cuentas,
   pintar esa sección del menú, y reaccionar a los clics de cambiar,
   añadir o cerrar una cuenta. La propia página inserta el HTML que
   devuelve `bloqueHTML()` donde le convenga dentro de su plantilla, y
   llama a `conectar(pop)` una vez, al crear el popover.
   ═══════════════════════════════════════════════════════════════════ */
(function(){
  const esc = s => String(s ?? '').replace(/[<>&"']/g, c => (
    {'<':'&lt;','>':'&gt;','&':'&amp;','"':'&quot;','\'':'&#39;'}[c]
  ));
  const ini = n => (n || '').trim().split(/\s+/).map(w => w[0] || '')
    .join('').slice(0, 2).toUpperCase() || '··';

  async function post(url, data){
    const r = await fetch(url, {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      credentials: 'same-origin',
      body: JSON.stringify(data || {})
    });
    let body = {};
    try { body = await r.json(); } catch(e){ /* sin cuerpo JSON */ }
    return { status: r.status, ok: r.ok, ...body };
  }

  /** Las cuentas enlazadas en este navegador, la activa incluida. */
  async function cargar(){
    try{
      const r = await fetch('api/accounts.php', {credentials: 'same-origin'});
      const d = await r.json();
      return (d && d.ok) ? d.accounts : [];
    } catch(e){
      return [];
    }
  }

  const TIPO = { club: 'Club' };
  const CHECK = '<svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 8.4 6.2 11.6 13 4.8"/></svg>';
  const CRUZ  = '<svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"><path d="M4 4l8 8M12 4l-8 8"/></svg>';
  const MAS   = '<svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"><path d="M8 3.4v9.2M3.4 8h9.2"/></svg>';

  /**
   * El HTML de la sección "Cuentas" del menú, lista para insertar.
   * "Añadir cuenta" sale siempre, aunque la lista de cuentas enlazadas
   * aún no haya llegado o el servidor no la haya podido dar: si no,
   * nadie podría añadir la primera cuenta extra mientras esa petición
   * fallase o tardase.
   */
  function bloqueHTML(accounts){
    accounts = accounts || [];

    const filas = accounts.map(a => `
      <div class="umenu-acc${a.active ? ' is-active' : ''}" data-account-id="${a.id}">
        <button class="umenu-acc-btn" type="button" ${a.active ? 'data-active' : `data-switch-account="${a.id}"`}
          title="${a.active ? 'Cuenta activa' : 'Cambiar a esta cuenta'}">
          <span class="umenu-acc-avatar">${esc(ini(a.name || a.email))}</span>
          <span class="umenu-acc-text">
            <span class="umenu-acc-name">${esc(a.name || a.email)}</span>
            <span class="umenu-acc-sub">${esc(TIPO[a.account_type] || a.role || 'Entrenador')}</span>
          </span>
          ${a.active ? `<span class="umenu-acc-check">${CHECK}</span>` : ''}
        </button>
        <button class="umenu-acc-x" type="button" data-remove-account="${a.id}"
          title="Cerrar sesión de esta cuenta">${CRUZ}</button>
      </div>`).join('');

    return `
      ${accounts.length ? `
      <div class="umenu-tit">Cuentas</div>
      <div class="umenu-accounts" data-accounts-list>${filas}</div>` : ''}
      <button class="umenu-it" type="button" data-add-account>${MAS}Añadir cuenta</button>
      ${accounts.length > 1
        ? '<button class="umenu-it" type="button" data-logout-all>Cerrar todas las sesiones</button>'
        : ''}
      <div class="umenu-sep"></div>`;
  }

  /**
   * Conecta los clics del selector de cuentas dentro de un popover ya
   * pintado. Delegado, así que sigue funcionando aunque `pintar()` de
   * la página vuelva a montar el HTML del menú.
   *
   * `onCambiar(destino)` es opcional: se llama justo antes de navegar
   * a la cuenta recién activada, por si la página quiere hacer algo
   * (cerrar el popover, por ejemplo) antes del salto.
   */
  function conectar(pop, opts){
    opts = opts || {};

    pop.addEventListener('click', async e => {
      const btnCambiar = e.target.closest('[data-switch-account]');
      const btnQuitar  = e.target.closest('[data-remove-account]');
      const btnAnadir  = e.target.closest('[data-add-account]');
      const btnTodas   = e.target.closest('[data-logout-all]');

      if(btnAnadir){
        e.stopPropagation();
        location.href = 'acceso.html?add=1';
        return;
      }

      if(btnCambiar){
        e.stopPropagation();
        const id = btnCambiar.dataset.switchAccount;
        btnCambiar.setAttribute('aria-busy', 'true');
        const r = await post('api/switch_account.php', {user_id: id});
        if(!r.ok){
          alert(r.error || 'No se ha podido cambiar de cuenta.');
          btnCambiar.removeAttribute('aria-busy');
          return;
        }
        if(opts.onCambiar) opts.onCambiar(r.user);
        location.href = (r.user && r.user.account_type === 'club')
          ? 'PlayLoad-club.html' : 'PlayLoad-dashboard.html';
        return;
      }

      if(btnQuitar){
        e.stopPropagation();
        const id = btnQuitar.dataset.removeAccount;
        const fila = btnQuitar.closest('.umenu-acc');
        if(fila && fila.classList.contains('is-active')){
          // Cerrar la cuenta activa desde aquí puede dejar otra activa
          // o ninguna: mejor el mismo camino que "Cerrar sesión".
          cerrarSesion(id);
          return;
        }
        const r = await post('api/logout.php', {user_id: id});
        if(!r.ok){
          alert(r.error || 'No se ha podido cerrar esa sesión.');
          return;
        }
        const lista = await cargar();
        const cont = pop.querySelector('[data-accounts-list]');
        if(cont){
          const bloque = document.createElement('div');
          bloque.innerHTML = bloqueHTML(lista);
          const nuevaLista = bloque.querySelector('[data-accounts-list]');
          if(nuevaLista) cont.replaceWith(nuevaLista);
        }
        return;
      }

      if(btnTodas){
        e.stopPropagation();
        await post('api/logout.php', {});
        location.href = 'acceso.html';
        return;
      }
    });
  }

  /** Cierra sesión: solo la cuenta activa si hay más enlazadas, o todo. */
  async function cerrarSesion(userId){
    const r = await post('api/logout.php', {user_id: userId});
    if(r.active){
      location.reload();
    } else {
      location.href = 'acceso.html';
    }
  }

  window.plCuentas = { cargar, bloqueHTML, conectar, cerrarSesion };
})();
