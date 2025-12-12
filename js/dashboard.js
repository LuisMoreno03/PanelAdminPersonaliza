let nextPageInfo = null;
let prevPageInfo = null;
let cargando = false;

function cargarPedidos(pageInfo = null) {

    if (cargando) return;
    cargando = true;

    let url = DASHBOARD_FILTER_URL;
    if (pageInfo) {
        url += "?page_info=" + pageInfo;
    }

    const tbody = document.getElementById("tablaPedidos");
    if (!tbody) {
        console.error("No existe #tablaPedidos");
        return;
    }

    tbody.innerHTML = `
        <tr>
            <td colspan="9" class="text-center py-10 text-gray-400">
                Cargando pedidos...
            </td>
        </tr>
    `;

    fetch(url, { headers: { "X-Requested-With": "XMLHttpRequest" } })
        .then(r => r.json())
        .then(data => {

            tbody.innerHTML = "";

            if (!data.success) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="9" class="text-center py-10 text-red-500">
                            Error cargando pedidos
                        </td>
                    </tr>
                `;
                cargando = false;
                return;
            }

            document.getElementById("total-pedidos").innerText = data.count;
            document.getElementById("contador-pedidos").innerText =
                `Mostrando ${data.count} pedidos`;

            data.orders.forEach(o => {
                tbody.innerHTML += `
                    <tr class="border-b hover:bg-gray-50">
                        <td class="py-3 px-4 font-semibold">${o.numero}</td>
                        <td class="py-3 px-4">${o.fecha}</td>
                        <td class="py-3 px-4">${o.cliente}</td>
                        <td class="py-3 px-4">${o.total}</td>

                        <td class="py-3 px-4 cursor-pointer"
                            onclick="abrirModal(${o.id})">
                            ${o.estado}
                        </td>

                        <td class="py-3 px-4 cursor-pointer"
                            onclick="abrirModalEtiquetas(${o.id}, '${(o.etiquetas || "").replace(/'/g, "\\'")}')">
                            ${o.etiquetas || "-"}
                        </td>

                        <td class="py-3 px-4 text-center">${o.articulos}</td>
                        <td class="py-3 px-4">${o.estado_envio}</td>
                        <td class="py-3 px-4">${o.forma_envio}</td>
                    </tr>
                `;
            });

            nextPageInfo = data.next_page_info;
            prevPageInfo = data.prev_page_info;

            const btnPrev = document.getElementById("btn-prev");
            const btnNext = document.getElementById("btn-next");

            if (btnPrev) {
                prevPageInfo
                    ? (btnPrev.classList.remove("hidden"), btnPrev.onclick = () => cargarPedidos(prevPageInfo))
                    : btnPrev.classList.add("hidden");
            }

            if (btnNext) {
                nextPageInfo
                    ? (btnNext.classList.remove("hidden"), btnNext.onclick = () => cargarPedidos(nextPageInfo))
                    : btnNext.classList.add("hidden");
            }

            cargando = false;
        })
        .catch(err => {
            console.error(err);
            cargando = false;
        });
}

document.addEventListener("DOMContentLoaded", () => {
    cargarPedidos();
});
