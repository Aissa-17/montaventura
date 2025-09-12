<?php
// Hook REST API al inicializar
add_action( 'rest_api_init', function() {
    register_rest_route( 'montaventura/v1', '/eventos', [
        'methods'  => 'GET',
        'callback' => 'mtv_calendario_obtener_eventos',
        'permission_callback' => '__return_true',
    ] );
} );

/**
 * Devuelve la lista de eventos para FullCalendar
 */
function mtv_calendario_obtener_eventos( \WP_REST_Request $request ) {
    $args = [
        'post_type'      => 'mtv_evento',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
    ];
    $query = new WP_Query( $args );
    $items = [];

    foreach ( $query->posts as $post ) {
        $start = get_post_meta( $post->ID, '_mtv_evento_start', true );
        $end   = get_post_meta( $post->ID, '_mtv_evento_end',   true );

        $items[] = [
            'id'    => $post->ID,
            'title' => $post->post_title,
            'start' => $start,
            'end'   => $end,
            // aquí meter cualquier extendedProp que necesites:
            'extendedProps' => [
                'actividad_tipo' => get_post_meta( $post->ID, 'actividad_tipo', true ),
                // ...
            ],
        ];
    }

    return rest_ensure_response( $items );
}