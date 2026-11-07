<?php
/**
 * Plugin Name: AF Catalogo Documenti
 * Description: Progetto dimostrativo. Custom post type "Documento" con tassonomia, campi personalizzati, import/export JSON e CSV, endpoint REST e sincronizzazione pianificata da una sorgente JSON esterna.
 * Version:     1.0.0
 * Author:      Alessandro Fior
 * License:     GPL-2.0-or-later
 * Text Domain: af-catalogo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const AF_CAT_META   = array( '_af_codice', '_af_versione', '_af_url_fonte' );
const AF_CAT_OPTION = 'af_cat_sync_url';
const AF_CAT_CRON   = 'af_cat_sync_event';

/* ------------------------------------------------------------------
 * 1. Custom post type e tassonomia
 * ----------------------------------------------------------------*/
add_action( 'init', function () {
	register_post_type( 'af_documento', array(
		'labels'       => array(
			'name'          => 'Documenti',
			'singular_name' => 'Documento',
			'add_new_item'  => 'Aggiungi documento',
		),
		'public'       => true,
		'menu_icon'    => 'dashicons-media-document',
		'supports'     => array( 'title', 'editor', 'excerpt', 'thumbnail' ),
		'has_archive'  => true,
		'rewrite'      => array( 'slug' => 'documenti' ),
		'show_in_rest' => true,
	) );

	register_taxonomy( 'af_categoria', 'af_documento', array(
		'label'        => 'Categorie documento',
		'hierarchical' => true,
		'show_in_rest' => true,
		'rewrite'      => array( 'slug' => 'categoria-documento' ),
	) );

	// Campi personalizzati esposti anche nella REST API nativa.
	foreach ( AF_CAT_META as $key ) {
		register_post_meta( 'af_documento', $key, array(
			'type'              => 'string',
			'single'            => true,
			'show_in_rest'      => true,
			'sanitize_callback' => '_af_url_fonte' === $key ? 'esc_url_raw' : 'sanitize_text_field',
			'auth_callback'     => function () {
				return current_user_can( 'edit_posts' );
			},
		) );
	}
} );

/* ------------------------------------------------------------------
 * 2. Meta box per i campi personalizzati
 * ----------------------------------------------------------------*/
add_action( 'add_meta_boxes', function () {
	add_meta_box( 'af_dati', 'Dati documento', 'af_cat_render_metabox', 'af_documento', 'side' );
} );

function af_cat_render_metabox( $post ) {
	wp_nonce_field( 'af_cat_save', 'af_cat_nonce' );
	$campi = array(
		'_af_codice'    => 'Codice',
		'_af_versione'  => 'Versione',
		'_af_url_fonte' => 'URL fonte',
	);
	foreach ( $campi as $key => $label ) {
		printf(
			'<p><label for="%1$s"><strong>%2$s</strong></label><br><input type="text" class="widefat" id="%1$s" name="%1$s" value="%3$s"></p>',
			esc_attr( $key ),
			esc_html( $label ),
			esc_attr( get_post_meta( $post->ID, $key, true ) )
		);
	}
}

add_action( 'save_post_af_documento', function ( $post_id ) {
	if ( ! isset( $_POST['af_cat_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['af_cat_nonce'] ) ), 'af_cat_save' ) ) {
		return;
	}
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}
	foreach ( AF_CAT_META as $key ) {
		if ( isset( $_POST[ $key ] ) ) {
			$val = wp_unslash( $_POST[ $key ] );
			update_post_meta( $post_id, $key, '_af_url_fonte' === $key ? esc_url_raw( $val ) : sanitize_text_field( $val ) );
		}
	}
} );

/* ------------------------------------------------------------------
 * 3. Funzioni di dominio: esportazione e importazione (upsert per codice)
 * ----------------------------------------------------------------*/
function af_cat_export() {
	$query = new WP_Query( array(
		'post_type'      => 'af_documento',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'no_found_rows'  => true,
	) );
	$out = array();
	foreach ( $query->posts as $p ) {
		$out[] = array(
			'codice'     => (string) get_post_meta( $p->ID, '_af_codice', true ),
			'titolo'     => $p->post_title,
			'contenuto'  => $p->post_content,
			'versione'   => (string) get_post_meta( $p->ID, '_af_versione', true ),
			'url_fonte'  => (string) get_post_meta( $p->ID, '_af_url_fonte', true ),
			'categorie'  => wp_list_pluck( wp_get_object_terms( $p->ID, 'af_categoria' ), 'name' ),
			'modificato' => $p->post_modified_gmt,
		);
	}
	return $out;
}

/**
 * Crea o aggiorna documenti. La chiave univoca è il campo "codice".
 *
 * @return array{creati:int,aggiornati:int,scartati:int}
 */
function af_cat_import( array $righe ) {
	$esito = array( 'creati' => 0, 'aggiornati' => 0, 'scartati' => 0 );

	foreach ( $righe as $r ) {
		$codice = isset( $r['codice'] ) ? sanitize_text_field( $r['codice'] ) : '';
		$titolo = isset( $r['titolo'] ) ? sanitize_text_field( $r['titolo'] ) : '';
		if ( '' === $codice || '' === $titolo ) {
			$esito['scartati']++;
			continue;
		}

		$esistente = get_posts( array(
			'post_type'      => 'af_documento',
			'post_status'    => 'any',
			'meta_key'       => '_af_codice',
			'meta_value'     => $codice,
			'posts_per_page' => 1,
			'fields'         => 'ids',
		) );

		$dati = array(
			'post_type'    => 'af_documento',
			'post_status'  => 'publish',
			'post_title'   => $titolo,
			'post_content' => isset( $r['contenuto'] ) ? wp_kses_post( $r['contenuto'] ) : '',
		);

		if ( $esistente ) {
			$dati['ID'] = $esistente[0];
			$id         = wp_update_post( $dati, true );
			$chiave     = 'aggiornati';
		} else {
			$id     = wp_insert_post( $dati, true );
			$chiave = 'creati';
		}
		if ( is_wp_error( $id ) ) {
			$esito['scartati']++;
			continue;
		}

		update_post_meta( $id, '_af_codice', $codice );
		update_post_meta( $id, '_af_versione', isset( $r['versione'] ) ? sanitize_text_field( $r['versione'] ) : '' );
		update_post_meta( $id, '_af_url_fonte', isset( $r['url_fonte'] ) ? esc_url_raw( $r['url_fonte'] ) : '' );

		if ( ! empty( $r['categorie'] ) ) {
			$cat = is_array( $r['categorie'] ) ? $r['categorie'] : explode( '|', (string) $r['categorie'] );
			wp_set_object_terms( $id, array_map( 'sanitize_text_field', $cat ), 'af_categoria' );
		}
		$esito[ $chiave ]++;
	}
	return $esito;
}

/* ------------------------------------------------------------------
 * 4. REST API: /wp-json/af-catalogo/v1/...
 * ----------------------------------------------------------------*/
add_action( 'rest_api_init', function () {
	register_rest_route( 'af-catalogo/v1', '/export', array(
		'methods'             => 'GET',
		'callback'            => function () {
			return rest_ensure_response( af_cat_export() );
		},
		'permission_callback' => '__return_true', // Dati già pubblici: solo documenti pubblicati.
	) );

	register_rest_route( 'af-catalogo/v1', '/import', array(
		'methods'             => 'POST',
		'callback'            => function ( WP_REST_Request $req ) {
			$righe = $req->get_json_params();
			if ( ! is_array( $righe ) ) {
				return new WP_Error( 'af_payload', 'Atteso un array JSON di documenti.', array( 'status' => 400 ) );
			}
			return rest_ensure_response( af_cat_import( $righe ) );
		},
		'permission_callback' => function () {
			return current_user_can( 'edit_others_posts' );
		},
	) );
} );

/* ------------------------------------------------------------------
 * 5. Pagina di amministrazione: CSV import/export e URL di sincronizzazione
 * ----------------------------------------------------------------*/
add_action( 'admin_menu', function () {
	add_submenu_page( 'edit.php?post_type=af_documento', 'Import / Export', 'Import / Export', 'edit_others_posts', 'af-cat-io', 'af_cat_render_page' );
} );

function af_cat_render_page() {
	if ( ! current_user_can( 'edit_others_posts' ) ) {
		return;
	}
	$msg = get_transient( 'af_cat_msg_' . get_current_user_id() );
	delete_transient( 'af_cat_msg_' . get_current_user_id() );
	?>
	<div class="wrap">
		<h1>Catalogo documenti – Import / Export</h1>
		<?php if ( $msg ) : ?>
			<div class="notice notice-info"><p><?php echo esc_html( $msg ); ?></p></div>
		<?php endif; ?>

		<h2>Esporta</h2>
		<p>
			<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=af_cat_export_csv' ), 'af_cat_export' ) ); ?>">Scarica CSV</a>
			<a class="button" href="<?php echo esc_url( rest_url( 'af-catalogo/v1/export' ) ); ?>" target="_blank" rel="noopener">Vedi JSON</a>
		</p>

		<h2>Importa CSV</h2>
		<p>Colonne: <code>codice;titolo;contenuto;versione;url_fonte;categorie</code> (categorie separate da <code>|</code>). I documenti con codice già presente vengono aggiornati.</p>
		<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="af_cat_import_csv">
			<?php wp_nonce_field( 'af_cat_import' ); ?>
			<input type="file" name="csv" accept=".csv" required>
			<?php submit_button( 'Importa', 'primary', 'submit', false ); ?>
		</form>

		<h2>Sincronizzazione giornaliera</h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="af_cat_save_url">
			<?php wp_nonce_field( 'af_cat_url' ); ?>
			<input type="url" class="regular-text" name="url" placeholder="https://esempio.it/documenti.json" value="<?php echo esc_attr( get_option( AF_CAT_OPTION, '' ) ); ?>">
			<?php submit_button( 'Salva', 'secondary', 'submit', false ); ?>
		</form>
		<p class="description">Il file JSON deve avere lo stesso formato dell'endpoint di esportazione. Lasciare vuoto per disattivare.</p>
	</div>
	<?php
}

add_action( 'admin_post_af_cat_export_csv', function () {
	if ( ! current_user_can( 'edit_others_posts' ) ) {
		wp_die( 'Permessi insufficienti.', 403 );
	}
	check_admin_referer( 'af_cat_export' );

	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="documenti-' . gmdate( 'Ymd' ) . '.csv"' );
	$out = fopen( 'php://output', 'w' );
	fwrite( $out, "\xEF\xBB\xBF" ); // BOM per Excel.
	fputcsv( $out, array( 'codice', 'titolo', 'contenuto', 'versione', 'url_fonte', 'categorie' ), ';' );
	foreach ( af_cat_export() as $r ) {
		fputcsv( $out, array( $r['codice'], $r['titolo'], $r['contenuto'], $r['versione'], $r['url_fonte'], implode( '|', $r['categorie'] ) ), ';' );
	}
	fclose( $out );
	exit;
} );

add_action( 'admin_post_af_cat_import_csv', function () {
	if ( ! current_user_can( 'edit_others_posts' ) ) {
		wp_die( 'Permessi insufficienti.', 403 );
	}
	check_admin_referer( 'af_cat_import' );

	$msg = 'Nessun file ricevuto.';
	if ( ! empty( $_FILES['csv']['tmp_name'] ) && is_uploaded_file( $_FILES['csv']['tmp_name'] ) ) {
		$h     = fopen( $_FILES['csv']['tmp_name'], 'r' ); // phpcs:ignore
		$righe = array();
		$head  = null;
		while ( ( $row = fgetcsv( $h, 0, ';' ) ) !== false ) {
			if ( null === $head ) {
				$row[0] = ltrim( $row[0], "\xEF\xBB\xBF" );
				$head   = array_map( 'trim', $row );
				continue;
			}
			if ( count( $row ) === count( $head ) ) {
				$righe[] = array_combine( $head, $row );
			}
		}
		fclose( $h );
		$e   = af_cat_import( $righe );
		$msg = sprintf( 'Import completato: %d creati, %d aggiornati, %d scartati.', $e['creati'], $e['aggiornati'], $e['scartati'] );
	}
	set_transient( 'af_cat_msg_' . get_current_user_id(), $msg, 60 );
	wp_safe_redirect( admin_url( 'edit.php?post_type=af_documento&page=af-cat-io' ) );
	exit;
} );

add_action( 'admin_post_af_cat_save_url', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Permessi insufficienti.', 403 );
	}
	check_admin_referer( 'af_cat_url' );
	$url = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
	update_option( AF_CAT_OPTION, $url );
	set_transient( 'af_cat_msg_' . get_current_user_id(), $url ? 'URL di sincronizzazione salvato.' : 'Sincronizzazione disattivata.', 60 );
	wp_safe_redirect( admin_url( 'edit.php?post_type=af_documento&page=af-cat-io' ) );
	exit;
} );

/* ------------------------------------------------------------------
 * 6. Sincronizzazione pianificata (WP-Cron) da sorgente JSON esterna
 * ----------------------------------------------------------------*/
register_activation_hook( __FILE__, function () {
	if ( ! wp_next_scheduled( AF_CAT_CRON ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', AF_CAT_CRON );
	}
	flush_rewrite_rules();
} );

register_deactivation_hook( __FILE__, function () {
	wp_clear_scheduled_hook( AF_CAT_CRON );
	flush_rewrite_rules();
} );

add_action( AF_CAT_CRON, function () {
	$url = get_option( AF_CAT_OPTION, '' );
	if ( ! $url ) {
		return;
	}
	$risposta = wp_safe_remote_get( $url, array( 'timeout' => 20 ) );
	if ( is_wp_error( $risposta ) || 200 !== wp_remote_retrieve_response_code( $risposta ) ) {
		error_log( '[AF Catalogo] Sincronizzazione fallita.' ); // phpcs:ignore
		return;
	}
	$dati = json_decode( wp_remote_retrieve_body( $risposta ), true );
	if ( is_array( $dati ) ) {
		$e = af_cat_import( $dati );
		update_option( 'af_cat_last_sync', array( 'quando' => time(), 'esito' => $e ), false );
	}
} );
