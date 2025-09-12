/**
 * mtv-admin.js
 *
 * Gestiona dinámicamente la tabla de slots en Clases:
 * - Al hacer clic en “Añadir slot”, añade una fila nueva con inputs [hora][plazas].
 * - Al hacer clic en “Eliminar” en una fila, la elimina.
 *
 * Asume que usamos jQuery (que ya está cargado por WP en el admin).
 */
jQuery(document).ready(function($) {
    // Contenedor <table> y <tbody> donde se van a añadir filas
    var $tabla = $('#mtv_clases_slots_table');
    var $tbody = $tabla.find('tbody');

    // Función que devuelve el índice siguiente (basado en el número de filas actuales)
    function obtenerNuevoIndice() {
        // Si no hay filas, índice=0; sino, tomamos la cantidad de <tr> como base.
        return $tbody.find('tr').length; 
    }

    // Handler “Añadir slot”
    $('#mtv_add_slot').on('click', function(e) {
        e.preventDefault();

        var nuevoIndex = obtenerNuevoIndice();
        // Creamos el HTML de la nueva fila
        var filaHtml = ''
            + '<tr>'
            + '  <td>'
            + '    <input type="time" name="mtv_clases_slots[' + nuevoIndex + '][hora]" value="" />'
            + '  </td>'
            + '  <td>'
            + '    <input type="number" min="1" step="1" name="mtv_clases_slots[' + nuevoIndex + '][plazas]" value="" />'
            + '  </td>'
            + '  <td>'
            + '    <button class="button mtv-remove-slot" type="button">Eliminar</button>'
            + '  </td>'
            + '</tr>';

        // Añadimos al <tbody>
        $tbody.append( filaHtml );
    });

    // Handler “Eliminar slot” (delegado para que funcione en filas nuevas)
    $tbody.on('click', '.mtv-remove-slot', function(e) {
        e.preventDefault();
        $(this).closest('tr').remove();

        // Además, después de eliminar, renovamos los índices de name="mtv_clases_slots[0]...".
        // Esto evita “saltos” en el array y asegura que los índices sean 0,1,2,...
        // De lo contrario habría “huecos” y WP guardaría un array con keys discontinuas.
        $tbody.find('tr').each(function(index, tr) {
            $(tr).find('input[type="time"]').attr('name', 'mtv_clases_slots[' + index + '][hora]');
            $(tr).find('input[type="number"]').attr('name', 'mtv_clases_slots[' + index + '][plazas]');
        });
    });
});
