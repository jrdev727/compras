document.addEventListener('DOMContentLoaded', () => {
    // 1. Manejo de Pestañas (Tabs)
    const tabButtons = document.querySelectorAll('.tab-btn');
    const tabPanels = document.querySelectorAll('.tab-panel');

    if (tabButtons.length > 0) {
        // Restaurar pestaña seleccionada previamente (si existe en localStorage)
        const activeTabId = localStorage.getItem('activeTabId');
        if (activeTabId) {
            const activeBtn = document.querySelector(`.tab-btn[data-tab="${activeTabId}"]`);
            if (activeBtn) {
                tabButtons.forEach(btn => btn.classList.remove('active'));
                tabPanels.forEach(panel => panel.classList.remove('active'));
                
                activeBtn.classList.add('active');
                const panel = document.getElementById(activeTabId);
                if (panel) panel.classList.add('active');
            }
        }

        tabButtons.forEach(btn => {
            btn.addEventListener('click', () => {
                const targetTab = btn.getAttribute('data-tab');
                
                tabButtons.forEach(b => b.classList.remove('active'));
                tabPanels.forEach(p => p.classList.remove('active'));
                
                btn.classList.add('active');
                const targetPanel = document.getElementById(targetTab);
                if (targetPanel) {
                    targetPanel.classList.add('active');
                }
                
                // Guardar estado de pestaña activa
                localStorage.setItem('activeTabId', targetTab);
            });
        });
    }

    // 2. Carga Dinámica de Filas (Materiales Solicitados en Alta de Obra)
    const btnAddMaterial = document.getElementById('btn-add-material');
    const materialsContainer = document.getElementById('dynamic-materials');

    if (btnAddMaterial && materialsContainer) {
        let rowIdx = 1;
        btnAddMaterial.addEventListener('click', () => {
            const newRow = document.createElement('div');
            newRow.className = 'dynamic-item-row';
            newRow.innerHTML = `
                <div class="form-group">
                    <label class="form-label">Material / Descripción</label>
                    <input type="text" name="materiales[${rowIdx}][descripcion]" class="form-control" placeholder="Ej. Ladrillo Hueco 12x18x33" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Cantidad</label>
                    <input type="number" step="0.01" name="materiales[${rowIdx}][cantidad]" class="form-control" placeholder="0.00" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Unidad</label>
                    <select name="materiales[${rowIdx}][unidad]" class="form-control" required>
                        <option value="Bolsas">Bolsas</option>
                        <option value="Unidades">Unidades</option>
                        <option value="Metros Cúbicos">Metros Cúbicos</option>
                        <option value="Metros Cuadrados">Metros Cuadrados</option>
                        <option value="Varillas">Varillas</option>
                        <option value="Mallas">Mallas</option>
                        <option value="Metros">Metros</option>
                        <option value="Kilogramos">Kilogramos</option>
                        <option value="Litros">Litros</option>
                    </select>
                </div>
                <div style="padding-bottom: 5px;">
                    <button type="button" class="btn btn-danger btn-sm btn-remove-row" style="margin-top: 1.8rem;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2M10 11v6M14 11v6"/></svg>
                    </button>
                </div>
            `;
            materialsContainer.appendChild(newRow);
            rowIdx++;
        });

        // Event delegation para remover filas
        materialsContainer.addEventListener('click', (e) => {
            if (e.target.closest('.btn-remove-row')) {
                const row = e.target.closest('.dynamic-item-row');
                if (materialsContainer.children.length > 1) {
                    row.remove();
                } else {
                    alert("Debe registrar al menos un material.");
                }
            }
        });
    }

    // 3. Carga Dinámica de Compras / Items Adjudicados
    // En la página de carga de Orden de Compra, al seleccionar materiales de la obra
    const btnAddItemCompra = document.getElementById('btn-add-compra-item');
    const itemsCompraContainer = document.getElementById('dynamic-compra-items');

    if (btnAddItemCompra && itemsCompraContainer) {
        let itemIdx = 1;
        btnAddItemCompra.addEventListener('click', () => {
            // Obtenemos los materiales cargados previamente de la obra para el select
            const selectOptions = document.querySelector('#dynamic-compra-items select').innerHTML;
            const newRow = document.createElement('div');
            newRow.className = 'dynamic-item-row';
            newRow.innerHTML = `
                <div class="form-group">
                    <label class="form-label">Vincular a Material Solicitado (Opcional)</label>
                    <select name="items[${itemIdx}][material_solicitado_id]" class="form-control material-select">
                        ${selectOptions}
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Descripción de Compra</label>
                    <input type="text" name="items[${itemIdx}][descripcion]" class="form-control desc-input" placeholder="Ej. Cemento Loma Negra 50kg" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Cantidad</label>
                    <input type="number" step="0.01" name="items[${itemIdx}][cantidad_comprada]" class="form-control cant-input" placeholder="0.00" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Precio Unitario ($)</label>
                    <input type="number" step="0.01" name="items[${itemIdx}][precio_unitario]" class="form-control" placeholder="0.00" required>
                </div>
                <div style="padding-bottom: 5px;">
                    <button type="button" class="btn btn-danger btn-sm btn-remove-row" style="margin-top: 1.8rem;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2M10 11v6M14 11v6"/></svg>
                    </button>
                </div>
            `;
            itemsCompraContainer.appendChild(newRow);
            itemIdx++;
            
            // Auto completar datos si selecciona un material sugerido
            bindMaterialSelectEvents(newRow);
        });

        // Event delegation para remover filas
        itemsCompraContainer.addEventListener('click', (e) => {
            if (e.target.closest('.btn-remove-row')) {
                const row = e.target.closest('.dynamic-item-row');
                if (itemsCompraContainer.children.length > 1) {
                    row.remove();
                } else {
                    alert("Debe agregar al menos un ítem a la compra.");
                }
            }
        });

        // Aplicar lógica a las filas iniciales
        document.querySelectorAll('.dynamic-item-row').forEach(row => {
            bindMaterialSelectEvents(row);
        });
    }

    function bindMaterialSelectEvents(row) {
        const select = row.querySelector('.material-select');
        const descInput = row.querySelector('.desc-input');
        const cantInput = row.querySelector('.cant-input');

        if (select && descInput) {
            select.addEventListener('change', () => {
                const selectedOption = select.options[select.selectedIndex];
                if (selectedOption.value !== '') {
                    const desc = selectedOption.getAttribute('data-desc');
                    const cant = selectedOption.getAttribute('data-cant');
                    descInput.value = desc;
                    cantInput.value = cant;
                }
            });
        }
    }

    // 4. Filtrado de tablas cliente-side rápido
    const searchInputs = document.querySelectorAll('.table-search');
    searchInputs.forEach(input => {
        const targetTableId = input.getAttribute('data-table');
        // Soporta tanto ID (#tabla-id) como Clase (.tabla-id)
        const tables = document.querySelectorAll('#' + targetTableId + ', .' + targetTableId);
        if (tables.length > 0) {
            input.addEventListener('input', () => {
                const query = input.value.toLowerCase();
                tables.forEach(table => {
                    const rows = table.querySelectorAll('tbody tr');
                    let hasVisible = false;
                    rows.forEach(row => {
                        const text = row.textContent.toLowerCase();
                        if (text.includes(query)) {
                            row.style.display = '';
                            hasVisible = true;
                        } else {
                            row.style.display = 'none';
                        }
                    });
                    
                    // Si la tabla está en una tarjeta, ocultar la tarjeta entera si no tiene filas visibles
                    const card = table.closest('.card');
                    if (card) {
                        if (hasVisible || query === '') {
                            card.style.display = '';
                        } else {
                            card.style.display = 'none';
                        }
                    }
                });
            });
        }
    });
});

// Función inteligente para volver a la página/pestaña anterior sin perder el estado
window.goBack = function(fallbackUrl) {
    if (document.referrer && document.referrer !== window.location.href && !document.referrer.includes(window.location.pathname)) {
        window.history.back();
    } else {
        window.location.href = fallbackUrl;
    }
};
