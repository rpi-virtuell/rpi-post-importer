<?php
/*
Plugin Name: RPI Post Importer
Plugin URI: https://github.com/rpi-virtuell/rpi-post-importer
Description: RPI Post Importer Plugin and Library
Version: 1.0
Author: reintanz
Author URI: https://github.com/FreelancerAMP
License: A "Slug" license name e.g. GPL2
*/


class RpiPostImporter
{
    public function __construct()
    {
    }

    public function rpi_import_post($api_url = '', $status_ignorelist = [], $dryrun = false, $logging = true)
    {


        // Überprüfen, ob Polylang-Funktionen vorhanden sind
        if (!function_exists('pll_save_post_translations') || !function_exists('pll_set_post_language')) {
            // Fehlermeldung oder Logging, falls Polylang nicht installiert ist
            error_log('Polylang-Plugin ist nicht installiert oder aktiviert.');
            return;
        }
        $this->log('Start des Importvorgangs.');

        $url = sanitize_url($api_url);

        // HTTP request to the API
        $response = wp_remote_get($url);

        // Check if the request was successful
        if (is_wp_error($response)) {
            return; // Skip to the next URL in case of an error
        }

        // Parse the JSON response
        $posts = json_decode(wp_remote_retrieve_body($response), true);

        // Fetch all pages of results
        $news_items = $this->fetch_all_pages($url, $posts);

        foreach ($news_items as $item) {

            if (in_array($item['status'], $status_ignorelist) ){
                continue;
            }

            if ($dryrun)
            {
               //TODO dry run
            }
                // Erstellen eines neuen Beitrags für jede Sprache.
                $post_id = $this->create_post($item);
            $posts[] = $post_id;

        }

        $this->log('Importvorgang abgeschlossen.');
    }

    private function log($message)
    {
        $log_file = WP_CONTENT_DIR . '/rpi_post_importer_log.txt'; // Pfad zur Log-Datei
        $timestamp = current_time('mysql');
        $entry = "{$timestamp}: {$message}\n";

        file_put_contents($log_file, $entry, FILE_APPEND);
    }

    public function fetch_all_pages($api_url, $data, $page = 1)
    {
        $per_page = 10; // Number of items per page
        $args = array(
            'per_page' => $per_page,
            'page' => $page,
        );

        $request_url = add_query_arg($args, $api_url);
        $response = wp_remote_get($request_url);

        if (is_wp_error($response)) {
            return $data; // Return existing data if request fails
        }

        $next = json_decode(wp_remote_retrieve_body($response), true);
        $data = array_merge($data, $next);

        // Check if there are more pages to fetch
        if (count($next) == $per_page) {
            $page++;
            $data = $this->fetch_all_pages($api_url, $data, $page); // Recursively fetch next page
        }

        return $data;
    }

    private function create_post($item)
    {
        // Annahme: $item enthält alle notwendigen Informationen
        $post_data = array(
            'post_author' => 1, // oder einen dynamischen Autor
            'post_content' => $item['content']['rendered'],
            'post_title' => $item['title']['rendered'],
            'post_status' => 'publish',
            'post_type' => 'post',
            'meta_input' => array(
                'import_id' => $item['id'], // oder eine andere eindeutige ID
            ),
        );

        // Erstellen des Beitrags
        $post_id = wp_insert_post($post_data);

        // Überprüfen, ob der Beitrag erfolgreich erstellt wurde.
        if ($post_id && !is_wp_error($post_id)) {

            // Medieninhalte hinzufügen
            if (!empty($item['featured_media'])) {
                $this->import_media($item['featured_media'], $post_id);
            }

            // Kategorien und Tags hinzufügen
            if (!empty($item['categories'])) {
                $this->assign_terms($post_id, $item['categories'], 'category');
            }
            if (!empty($item['tags'])) {
                $this->assign_terms($post_id, $item['tags'], 'post_tag');
            }


            $this->log("Beitrag erstellt: '{$post_data['post_title']}'");

        }

        return $post_id;
    }

    private function import_media($media_url)
    {
        if (!$media_url) {
            return null;
        }

        // Der Dateiname wird aus der URL extrahiert.
        $file_name = basename($media_url);

        // Temporärdatei erstellen.
        $temp_file = download_url($media_url);
        if (is_wp_error($temp_file)) {
            return null;
        }

        // Dateiinformationen vorbereiten.
        $file = array(
            'name' => $file_name,
            'type' => mime_content_type($temp_file),
            'tmp_name' => $temp_file,
            'error' => 0,
            'size' => filesize($temp_file),
        );

        // Die Datei wird der Medienbibliothek hinzugefügt.
        $media_id = media_handle_sideload($file, 0);
        if (is_wp_error($media_id)) {
            @unlink($temp_file);
            return null;
        }

        return $media_id;
    }

    private function assign_terms($post_id, $term_ids, $taxonomy)
    {
        foreach ($term_ids as $term_id) {
            // Namen des Terms aus der Quelle holen
            $term_name = get_term_by('id', $term_id, $taxonomy)->name;

            // Term-ID im Zielblog übersetzen
            $translated_term_id = $this->translate_term_id($term_name, $taxonomy);
            if ($translated_term_id) {
                wp_set_object_terms($post_id, $translated_term_id, $taxonomy, true);
            }
        }
    }

    private function translate_term_id($source_term_name, $taxonomy)
    {
        // Überprüfen, ob ein Term mit diesem Namen im Zielblog existiert.
        $term = get_term_by('name', $source_term_name, $taxonomy);

        // Wenn der Term existiert, gibt die ID zurück.
        if ($term) {
            return $term->term_id;
        }

        // Wenn der Term nicht existiert, erstelle einen neuen Term.
        $new_term = wp_insert_term($source_term_name, $taxonomy);

        // Überprüfen, ob die Erstellung erfolgreich war.
        if (is_wp_error($new_term)) {
            // Fehlerbehandlung, eventuell Logging.
            return null;
        }

        // Gibt die ID des neu erstellten Terms zurück.
        return $new_term['term_id'];
    }


}

new RpiPostImporter();
