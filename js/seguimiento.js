(() => {
  const base = window.SEGUIMIENTO_BASE || "";
  const tbody = document.getElementById("tbodySeguimiento");
  const loading = document.getElementById("loading");
  const errorBox = document.getElementById("error");

  const fromEl = document.getElementById("from");
  const toEl = document.getElementById("to");
  const btnFiltrar = document.getElementById("btnFiltrar");
  const btnLimpiar = document.getElementById("btnLimpiar");

  function setLoading(v) {
    loading.style.display = v ? "block" : "none";
  }

  function setError(msg) {
    errorBox.textContent = msg || "";
    errorBox.style.display = msg ? "block" : "none";
  }

  function render(rows) {
    tbody.innerHTML = "";

    if (!rows || rows.length === 0) {
      tbody.innerHTML = `<tr><td colspan="4" class="muted">No hay registros para mostrar.</td></tr>`;
      return;
    }

    const frag = document.createDocumentFragment();

    rows.forEach(r => {
      const tr = document.createElement("tr");
      tr.innerHTML = `
        <td>${escapeHtml(r.user_name ?? ("Usuario #" + r.user_id))}</td>
        <td>${escapeHtml(r.user_email ?? "-")}</td>
        <td>${escapeHtml(String(r.total_cambios ?? 0))}</td>
        <td>${escapeHtml(r.ultimo_cambio ?? "-")}</td>
      `;
      frag.appendChild(tr);
    });

    tbody.appendChild(frag);
  }

  function escapeHtml(str) {
    return String(str)
      .replaceAll("&", "&amp;")
      .replaceAll("<", "&lt;")
      .replaceAll(">", "&gt;")
      .replaceAll('"', "&quot;")
      .replaceAll("'", "&#039;");
  }

  async function cargar() {
    setError("");
    setLoading(true);

    const params = new URLSearchParams();
    if (fromEl.value) params.set("from", fromEl.value);
    if (toEl.value) params.set("to", toEl.value);

    try {
      const res = await fetch(`${base}/seguimiento/resumen?${params.toString()}`, {
        method: "GET",
        headers: { "Accept": "application/json" }
      });

      if (!res.ok) throw new Error(`HTTP ${res.status}`);

      const json = await res.json();
      if (!json.ok) throw new Error("Respuesta inválida del servidor.");

      render(json.data);
    } catch (err) {
      setError("Error cargando seguimiento: " + (err.message || err));
      render([]);
    } finally {
      setLoading(false);
    }
  }

  btnFiltrar.addEventListener("click", cargar);

  btnLimpiar.addEventListener("click", () => {
    fromEl.value = "";
    toEl.value = "";
    cargar();
  });

  // Carga inicial
  cargar();
})();
