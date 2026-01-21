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
    if (selector && !el) return;

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
        useModalOverlay: true,
        modalOverlayOpeningPadding: 8,
        modalOverlayOpeningRadius: 10
      },
    });

    const b = {
      next: { text: "Siguiente", action: tour.next },
      back: { text: "Atrás", action: tour.back, classes: "shepherd-button-secondary" },
      skip: { text: "Saltar", action: tour.cancel, classes: "shepherd-button-secondary" },
      done: { text: "Finalizar", action: tour.complete },
    };

    tour.on("complete", setSeen);
    tour.on("cancel", setSeen);

    addStepIfExists(tour, {
      id: "welcome",
      title: "Tutorial de Pedidos",
      text: `
        <div>
          <p>Te muestro para qué sirve cada parte de esta pantalla.</p>
          <p>Puedes <b>saltar</b> cuando quieras.</p>
        </div>
      `,
      selector: null,
      buttons: [b.skip, b.next],
    });

    addStepIfExists(tour, {
      id: "page-title",
      title: "Sección Pedidos",
      text: `<div><p>Aquí controlas estados, cambios, entrega y totales.</p></div>`,
      selector: '[data-tour="page-title"]',
      position: "bottom",
      buttons: [b.back, b.next],
    });

    addStepIfExists(tour, {
      id: "filters",
      title: "Filtros",
      text: `
        <div>
          <p>Zona para localizar pedidos rápido:</p>
          <ul style="margin:8px 0 0 16px;">
            <li><b>Buscar</b>: pedido/cliente/ID</li>
            <li><b>Filtro</b>: opciones avanzadas (estado, fecha, entrega)</li>
          </ul>
        </div>
      `,
      selector: '[data-tour="filters"]',
      position: "bottom",
      buttons: [b.back, b.next],
    });

    addStepIfExists(tour, {
      id: "search-input",
      title: "Campo de búsqueda",
      text: `<div><p>Escribe un número de pedido o el nombre del cliente.</p></div>`,
      selector: '[data-tour="search-input"]',
      position: "bottom",
      buttons: [b.back, b.next],
    });

    addStepIfExists(tour, {
      id: "search-button",
      title: "Botón BUSCAR",
      text: `<div><p>Ejecuta la búsqueda con el texto introducido.</p></div>`,
      selector: '[data-tour="search-button"]',
      position: "bottom",
      buttons: [b.back, b.next],
    });

    addStepIfExists(tour, {
      id: "filter-button",
      title: "Botón FILTRO",
      text: `<div><p>Abre filtros avanzados para acotar el listado.</p></div>`,
      selector: '[data-tour="filter-button"]',
      position: "left",
      buttons: [b.back, b.next],
    });

    addStepIfExists(tour, {
      id: "orders-table",
      title: "Listado de pedidos",
      text: `
        <div>
          <p>Aquí ves la información clave:</p>
          <ul style="margin:8px 0 0 16px;">
            <li>Pedido / Fecha / Cliente</li>
            <li>Método de entrega</li>
            <li>Estado y último cambio</li>
            <li>Entrega y total</li>
          </ul>
        </div>
      `,
      selector: '[data-tour="orders-table"]',
      position: "top",
      buttons: [b.back, b.next],
    });

    addStepIfExists(tour, {
      id: "estado-produccion",
      title: "Estado de producción",
      text: `<div><p>Indica en qué punto está el pedido (Diseñado, Confirmado, Por preparar…).</p></div>`,
      selector: '[data-tour="estado-produccion"]',
      position: "top",
      buttons: [b.back, b.next],
    });

    addStepIfExists(tour, {
      id: "estado-entrega",
      title: "Estado de entrega",
      text: `<div><p>Indica el estado logístico (pendiente, enviado, entregado…).</p></div>`,
      selector: '[data-tour="estado-entrega"]',
      position: "top",
      buttons: [b.back, b.next],
    });

    addStepIfExists(tour, {
      id: "done",
      title: "Listo ✅",
      text: `<div><p>Si quieres verlo otra vez, pulsa <b>¿Cómo funciona?</b>.</p></div>`,
      selector: null,
      buttons: [b.back, b.done],
    });

    return tour;
  }

  function start({ force = false } = {}) {
    if (!force && hasSeen()) return;
    const tour = buildTour();
    if (!tour) return;
    tour.start();
  }

  document.addEventListener("DOMContentLoaded", function () {
    start();
    document.getElementById("btn-ayuda")?.addEventListener("click", () => start({ force: true }));
  });
})();
