(function () {
  const ctx = window.TOUR_CONTEXT || { key: "pedidos", userId: 0 };
  const TOUR_KEY = `tour_seen_${ctx.key}_${ctx.userId || "anon"}`;

  function hasSeen() {
    try { return localStorage.getItem(TOUR_KEY) === "1"; } catch { return false; }
  }
  function setSeen() {
    try { localStorage.setItem(TOUR_KEY, "1"); } catch {}
  }

  function addStepIfExists(tour, { id, title, text, selector, position = "bottom", buttons }) {
    const el = selector ? document.querySelector(selector) : null;
    if (selector && !el) return; // si no existe, no añade el paso

    tour.addStep({
      id,
      title,
      text,
      attachTo: selector ? { element: selector, on: position } : undefined,
      scrollTo: selector ? { behavior: "smooth", block: "center" } : undefined,
      canClickTarget: true,
      classes: "shepherd-theme-custom",
      buttons,
    });
  }

  function buildTour() {
    const Shepherd = window.Shepherd;
    if (!Shepherd) return null;

    const tour = new Shepherd.Tour({
      defaultStepOptions: {
        cancelIcon: { enabled: true },
        modalOverlayOpeningPadding: 8,
        modalOverlayOpeningRadius: 10,
        useModalOverlay: true,
      },
    });

    const buttons = {
      next: { text: "Siguiente", action: tour.next },
      back: { text: "Atrás", action: tour.back, classes: "shepherd-button-secondary" },
      skip: { text: "Saltar", action: tour.cancel, classes: "shepherd-button-secondary" },
      done: { text: "Finalizar", action: tour.complete },
    };

    tour.on("complete", setSeen);
    tour.on("cancel", setSeen);

    // 1) Bienvenida
    addStepIfExists(tour, {
      id: "welcome",
      title: "Bienvenido al panel",
      text: `
        <div>
          <p>Este tutorial te muestra para qué sirve cada parte de <b>Pedidos</b>.</p>
          <p>Puedes <b>Saltar</b> el tour cuando quieras.</p>
        </div>
      `,
      selector: null,
      buttons: [buttons.skip, buttons.next],
    });

    // 2) Título
    addStepIfExists(tour, {
      id: "page-title",
      title: "Sección: Pedidos",
      text: `
        <div>
          <p>Aquí controlas pedidos, cambios, estados y totales.</p>
        </div>
      `,
      selector: '[data-tour="page-title"]',
      position: "bottom",
      buttons: [buttons.back, buttons.next],
    });

    // 3) Filtros
    addStepIfExists(tour, {
      id: "filters",
      title: "Filtros",
      text: `
        <div>
          <p>Esta zona sirve para encontrar pedidos rápido:</p>
          <ul style="margin:8px 0 0 16px;">
            <li><b>Buscar</b>: por pedido/cliente/ID</li>
            <li><b>Filtro</b>: opciones avanzadas (estado, fecha, entrega)</li>
          </ul>
        </div>
      `,
      selector: '[data-tour="filters"]',
      position: "bottom",
      buttons: [buttons.back, buttons.next],
    });

    addStepIfExists(tour, {
      id: "search-input",
      title: "Búsqueda",
      text: `
        <div>
          <p>Escribe el número de pedido o el nombre del cliente.</p>
          <p>Ejemplo: <b>#PEDIDO010769</b> o “Marina”.</p>
        </div>
      `,
      selector: '[data-tour="search-input"]',
      position: "bottom",
      buttons: [buttons.back, buttons.next],
    });

    addStepIfExists(tour, {
      id: "search-button",
      title: "Botón BUSCAR",
      text: `<div><p>Ejecuta la búsqueda con lo que hayas escrito.</p></div>`,
      selector: '[data-tour="search-button"]',
      position: "bottom",
      buttons: [buttons.back, buttons.next],
    });

    addStepIfExists(tour, {
      id: "filter-button",
      title: "Botón FILTRO",
      text: `<div><p>Abre filtros avanzados para acotar el listado.</p></div>`,
      selector: '[data-tour="filter-button"]',
      position: "left",
      buttons: [buttons.back, buttons.next],
    });

    // 4) Tabla
    addStepIfExists(tour, {
      id: "orders-table",
      title: "Listado de pedidos",
      text: `
        <div>
          <p>En el listado ves la información clave:</p>
          <ul style="margin:8px 0 0 16px;">
            <li><b>Pedido / Fecha / Cliente</b></li>
            <li><b>Método de entrega</b></li>
            <li><b>Estado</b> (producción)</li>
            <li><b>Último cambio</b></li>
            <li><b>Entrega</b> y <b>Total</b></li>
          </ul>
        </div>
      `,
      selector: '[data-tour="orders-table"]',
      position: "top",
      buttons: [buttons.back, buttons.next],
    });

    // 5) Estados (si existen en DOM)
    addStepIfExists(tour, {
      id: "estado-produccion",
      title: "Estado de producción",
      text: `
        <div>
          <p>Indica en qué punto está el pedido (ej. Diseñado / Confirmado / Por preparar).</p>
        </div>
      `,
      selector: '[data-tour="estado-produccion"]',
      position: "top",
      buttons: [buttons.back, buttons.next],
    });

    addStepIfExists(tour, {
      id: "estado-entrega",
      title: "Estado de entrega",
      text: `
        <div>
          <p>Indica el estado logístico (pendiente, enviado, etc.).</p>
        </div>
      `,
      selector: '[data-tour="estado-entrega"]',
      position: "top",
      buttons: [buttons.back, buttons.next],
    });

    // 6) Final
    addStepIfExists(tour, {
      id: "done",
      title: "Listo ✅",
      text: `
        <div>
          <p>Ya sabes qué hace cada parte de la sección.</p>
          <p>Si quieres repetirlo, pulsa el botón <b>¿Cómo funciona?</b>.</p>
        </div>
      `,
      selector: null,
      buttons: [buttons.back, buttons.done],
    });

    return tour;
  }

  function start({ force = false } = {}) {
    if (!force && hasSeen()) return;

    const tour = buildTour();
    if (!tour) return;
    tour.start();
  }

  // Arranque automático al cargar la página
  document.addEventListener("DOMContentLoaded", function () {
    start();

    // Botón para relanzar
    const helpBtn = document.getElementById("btn-ayuda");
    if (helpBtn) helpBtn.addEventListener("click", () => start({ force: true }));
  });
})();
