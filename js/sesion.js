/* ═══════════════════════════════════════════════════════════════════
   PlayLoad · a qué panel pertenece esta cuenta
   ───────────────────────────────────────────────────────────────────
   Una cuenta de club no pinta nada en las pantallas del entrenador: no
   entrena, y su menú lateral es otro. Este guardián la devuelve a su
   sitio antes de que llegue a ver nada.

   El tipo de cuenta se recuerda en el navegador porque saberlo hace
   falta ANTES de pintar, y preguntárselo al servidor tarda: con la
   respuesta en la mano el menú del entrenador ya se habría visto. La
   copia local solo decide qué enseñar; quien manda de verdad sigue
   siendo el servidor, que corrige en cuanto contesta.

   Cada pantalla llama a plCuenta.marcar(tipo) al recibir su estado.
   ═══════════════════════════════════════════════════════════════════ */
(function(){
  var CLUB  = 'PlayLoad-club.html';
  var CLAVE = 'pl-account-type';

  /* localStorage no siempre está: en modo privado o dentro de un iframe
     lanza excepción. Sin esta red, el guardián tumbaría la página. */
  var mem = null;
  function lee(){ try { return localStorage.getItem(CLAVE); } catch(e){ return mem; } }
  function guarda(v){ try { localStorage.setItem(CLAVE, v); } catch(e){ mem = v; } }
  function borra(){ try { localStorage.removeItem(CLAVE); } catch(e){ mem = null; } }

  var enClub  = /PlayLoad-club\.html$/i.test(location.pathname);
  var conServidor = /^https?:$/.test(location.protocol);

  function ocultar(){
    if(document.getElementById('pl-guard')) return;
    var s = document.createElement('style');
    s.id = 'pl-guard';
    /* visibility y no display: así la página ya está maquetada cuando se
       revela y no da el salto de recolocarse a la vista. */
    s.textContent = 'body{visibility:hidden}';
    document.head.appendChild(s);
  }
  function mostrar(){
    var s = document.getElementById('pl-guard');
    if(s) s.parentNode.removeChild(s);
  }

  window.plCuenta = {
    /* La verdad del servidor: guarda el tipo y, si esta cuenta es de
       club y está donde no debe, se la lleva a su panel. */
    marcar: function(tipo){
      if(tipo !== 'club' && tipo !== 'pro'){ mostrar(); return; }
      guarda(tipo);
      if(tipo === 'club' && !enClub){ location.replace(CLUB); return; }
      mostrar();
    },
    olvidar: function(){ borra(); },
    mostrar: mostrar
  };

  if(enClub) return;      // aquí no hay nada que vigilar

  var tipo = lee();

  /* Ya sabemos que es de club: fuera antes de pintar una sola línea. */
  if(tipo === 'club'){ location.replace(CLUB); return; }

  /* Sin servidor —abierto con doble clic— no hay a quién preguntar y la
     página es la vista de diseño: se enseña tal cual. */
  if(!conServidor || tipo === 'pro') return;

  /* Primera visita: no sabemos de quién es la cuenta. Se espera tapado
     antes que enseñar un menú que a lo mejor hay que retirar. */
  ocultar();

  /* Y si el servidor no contesta, se destapa igual: quedarse en blanco
     para siempre es peor que enseñar la pantalla que no toca. */
  setTimeout(mostrar, 2500);
})();
