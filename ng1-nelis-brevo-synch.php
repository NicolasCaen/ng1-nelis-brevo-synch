<?php
/**
 * Plugin Name: NG1 | Nelis Brevo Sync
 * Plugin URI: https://example.com
 * Description: Synchronise les contacts de Nelis vers un groupe Brevo
 * Version: 1.0.0
 * Author: GEHIN Nicolas
 * License: GPL v2 or later
 */

if (!defined('ABSPATH')) {
    exit;
}

class Ng1NelisBrevSync {
    private $option_name = 'ng1_nelis_brevo_sync_settings';

    public function __construct() {
        add_action('init', array($this, 'init'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'settings_init'));
        add_action('ng1_nelis_brevo_sync_cron', array($this, 'execute_sync'));
        add_action('wp_ajax_ng1_test_nelis_connection', array($this, 'ajax_test_nelis_connection'));

        register_activation_hook(__FILE__, array($this, 'activate_plugin'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate_plugin'));
    }

    public function init() {}

    public function activate_plugin() {
        if (!wp_next_scheduled('ng1_nelis_brevo_sync_cron')) {
            wp_schedule_event(time(), 'hourly', 'ng1_nelis_brevo_sync_cron');
        }
    }

    public function deactivate_plugin() {
        wp_clear_scheduled_hook('ng1_nelis_brevo_sync_cron');
    }

    public function add_admin_menu() {
        add_options_page(
            'Nelis Brevo Sync',
            'Nelis Brevo Sync',
            'manage_options',
            'ng1_nelis_brevo_sync',
            array($this, 'admin_page')
        );
    }

    public function settings_init() {
        register_setting('ng1_nelis_brevo_sync', $this->option_name);

        add_settings_section(
            'ng1_nelis_brevo_sync_section',
            'Configuration API',
            array($this, 'settings_section_callback'),
            'ng1_nelis_brevo_sync'
        );

        $fields = array(
            'nelis_api_url'       => 'URL API Nelis',
            'nelis_username'      => "Nom d'utilisateur Nelis",
            'nelis_password'      => 'Mot de passe Nelis',
            'nelis_client_id'     => 'Client ID Nelis',
            'nelis_client_secret' => 'Client Secret Nelis',
            'brevo_api_key'       => 'Clé API Brevo',
            'brevo_list_id'       => 'ID du groupe Brevo',
            'sync_frequency'      => 'Fréquence de sync',
            'batch_size'          => 'Taille des lots (par défaut: 100)'
        );

        foreach ($fields as $field_id => $field_title) {
            add_settings_field(
                $field_id,
                $field_title,
                array($this, 'settings_field_callback'),
                'ng1_nelis_brevo_sync',
                'ng1_nelis_brevo_sync_section',
                array('field_id' => $field_id)
            );
        }
    }

    public function settings_section_callback() {
        echo '<p>Configurez les paramètres de synchronisation entre Nelis et Brevo.</p>';
    }

    public function settings_field_callback($args) {
        $options = get_option($this->option_name);
        $field_id = $args['field_id'];
        $value = isset($options[$field_id]) ? $options[$field_id] : '';

        if ($field_id === 'sync_frequency') {
            echo '<select name="' . $this->option_name . '[' . $field_id . ']">';
            echo '<option value="hourly"' . selected($value, 'hourly', false) . '>Toutes les heures</option>';
            echo '<option value="twicedaily"' . selected($value, 'twicedaily', false) . '>Deux fois par jour</option>';
            echo '<option value="daily"' . selected($value, 'daily', false) . '>Quotidienne</option>';
            echo '</select>';
        } elseif ($field_id === 'batch_size') {
            $batch_size = !empty($value) ? intval($value) : 100;
            echo '<input type="number" name="' . $this->option_name . '[' . $field_id . ']" value="' . esc_attr($batch_size) . '" class="regular-text" min="1" max="1000" />';
            echo '<p class="description">Nombre de contacts à traiter par lot (recommandé: 100)</p>';
        } elseif (in_array($field_id, ['nelis_password', 'nelis_client_secret', 'brevo_api_key'])) {
            echo '<input type="password" name="' . $this->option_name . '[' . $field_id . ']" value="' . esc_attr($value) . '" class="regular-text" />';
        } else {
            echo '<input type="text" name="' . $this->option_name . '[' . $field_id . ']" value="' . esc_attr($value) . '" class="regular-text" />';
        }
    }

    public function admin_page() {
        ?>
        <div class="wrap">
            <h1>Synchronisation Nelis vers Brevo</h1>
            <div id="ng1-nelis-test-result"></div>
            <p><button id="ng1-test-nelis-btn" class="button">Tester la connexion à l'API Nelis</button></p>

            <div class="notice notice-info">
                <p><strong>Prochaine synchronisation :</strong> <?php echo date('d/m/Y H:i:s', wp_next_scheduled('ng1_nelis_brevo_sync_cron')); ?></p>
            </div>
            <form action="options.php" method="post">
                <?php
                settings_fields('ng1_nelis_brevo_sync');
                do_settings_sections('ng1_nelis_brevo_sync');
                submit_button();
                ?>
            </form>
            <hr>
            <h2>Actions</h2>
            <p>
                <a href="<?php echo admin_url('admin.php?page=ng1_nelis_brevo_sync&action=manual_sync'); ?>" class="button button-secondary">Synchronisation manuelle</a>
            </p>
            <h3>Logs récents</h3>
            <div style="background: #f9f9f9; padding: 10px; max-height: 300px; overflow-y: auto;">
                <?php $this->display_logs(); ?>
            </div>
        </div>
        <script>
        document.getElementById('ng1-test-nelis-btn').addEventListener('click', function () {
            const btn = this;
            btn.disabled = true;
            document.getElementById('ng1-nelis-test-result').innerHTML = '⏳ Test en cours...';
            fetch(ajaxurl + '?action=ng1_test_nelis_connection')
                .then(r => r.json())
                .then(data => {
                    btn.disabled = false;
                    document.getElementById('ng1-nelis-test-result').innerHTML = data.success ? '<div class="notice notice-success"><p>' + data.message + '</p></div>' : '<div class="notice notice-error"><p>' + data.message + '</p></div>';
                });
        });
        </script>
        <?php
        if (isset($_GET['action']) && $_GET['action'] === 'manual_sync') {
            $this->execute_sync();
            echo '<div class="notice notice-success"><p>Synchronisation manuelle exécutée !</p></div>';
        }
    }

    public function ajax_test_nelis_connection() {
        $options = get_option($this->option_name);
        try {
            $token = $this->get_access_token($options);
            $url = rtrim($options['nelis_api_url'], '/') . '/api/v4/people?limit=1';
            $response = wp_remote_get($url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type' => 'application/json'
                ],
                'timeout' => 15
            ]);
            if (is_wp_error($response)) {
                throw new Exception($response->get_error_message());
            }
            $code = wp_remote_retrieve_response_code($response);
            if ($code >= 200 && $code < 300) {
                wp_send_json_success(['message' => 'Connexion API Nelis réussie (code ' . $code . ')']);
            } else {
                throw new Exception('Code de réponse HTTP : ' . $code);
            }
        } catch (Exception $e) {
            wp_send_json_error(['message' => 'Erreur de connexion à lAPI Nelis : ' . $e->getMessage()]);
        }
    }

    private function get_access_token($options) {
        $url = rtrim($options['nelis_api_url'], '/') . '/token';
        $body = array(
            'grant_type'    => 'password',
            'username'      => $options['nelis_username'] ?? '',
            'password'      => $options['nelis_password'] ?? '',
            'client_id'     => $options['nelis_client_id'] ?? '',
            'client_secret' => $options['nelis_client_secret'] ?? '',
        );
        $args = array(
            'headers' => array('Content-Type' => 'application/x-www-form-urlencoded'),
            'body'    => http_build_query($body),
            'timeout' => 20
        );
        $response = wp_remote_post($url, $args);
        if (is_wp_error($response)) {
            throw new Exception('Erreur lors de la récupération du token Nelis : ' . $response->get_error_message());
        }
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($data['access_token'])) {
            throw new Exception('Token Nelis manquant ou invalide');
        }
        return $data['access_token'];
    }

    private function get_nelis_contacts_batch($options, $offset = 500, $limit = 100) {
        $offsetmax = $offset + $limit;
        $range = trim($offset . '-' . $offsetmax);

        $url = rtrim($options['nelis_api_url'], '/') . '/api/v4/people?limit=' . $limit . '&range=' . $range;
        $access_token = $this->get_access_token($options);
        $args = array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type'  => 'application/json'
            ),
            'timeout' => 30
        );
        $response = wp_remote_get($url, $args);
        if (is_wp_error($response)) {
            throw new Exception('Erreur connexion Nelis : ' . $response->get_error_message());
        }
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Erreur parsing JSON Nelis');
        }
        return $data['items'] ?? $data ?? array();
    }

    private function get_all_nelis_contacts($options) {
        $all_contacts = array();
        $batch_size = isset($options['batch_size']) && !empty($options['batch_size']) ? intval($options['batch_size']) : 100;
        $offset = 0;
        $batch_number = 1;
        
        $this->log("Début de la récupération de tous les contacts Nelis (taille des lots: {$batch_size})");
        
        while (true) {
            try {
                $this->log("Récupération du lot #{$batch_number} (range: {$offset}-" . ($offset + $batch_size) . ")");
                
                $contacts_batch = $this->get_nelis_contacts_batch($options, $offset, $batch_size);
                
                if (empty($contacts_batch)) {
                    $this->log("Aucun contact trouvé dans le lot #{$batch_number}. Fin de la récupération.");
                    break;
                }
                
                $contact_count = count($contacts_batch);
                $this->log("Lot #{$batch_number} : {$contact_count} contacts récupérés");
                
                $all_contacts = array_merge($all_contacts, $contacts_batch);
                
                // Si le nombre de contacts récupérés est inférieur à la taille du lot,
                // cela signifie qu'on a atteint la fin
                if ($contact_count < $batch_size) {
                    $this->log("Dernier lot atteint (contacts récupérés < taille du lot). Fin de la récupération.");
                    break;
                }
                
                $offset += $batch_size;
                $batch_number++;
                
                // Petite pause entre les requêtes pour éviter de surcharger l'API
                sleep(1);
                
            } catch (Exception $e) {
                $this->log("Erreur lors de la récupération du lot #{$batch_number} : " . $e->getMessage());
                break;
            }
        }
        
        $total_contacts = count($all_contacts);
        $this->log("Récupération terminée : {$total_contacts} contacts au total récupérés en {$batch_number} lots");
        
        return $all_contacts;
    }

    private function is_valid_email($email) {
        // Validation basique de l'email
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        
        // Vérification des caractères interdits ou problématiques
        if (strpos($email, ' / ') !== false || strpos($email, ' ') !== false) {
            return false;
        }
        
        return true;
    }

    private function sync_contact_to_brevo($contact, $options) {
        if (empty($contact['email']) || !$this->is_valid_email($contact['email'])) {
            if (!empty($contact['email'])) {
                $this->log('Email invalide ignoré: ' . $contact['email']);
            }
            return false;
        }
        
        $url = 'https://api.brevo.com/v3/contacts';
        $payload = array(
            'email' => trim($contact['email']),
            'attributes' => array(
                'FIRSTNAME' => isset($contact['firstname']) ? trim($contact['firstname']) : '',
                'LASTNAME'  => isset($contact['lastname']) ? trim($contact['lastname']) : '',
                'PHONE'     => isset($contact['phone']) ? trim($contact['phone']) : ''
            ),
            'listIds' => array(intval($options['brevo_list_id'])),
            'updateEnabled' => true
        );
        $args = array(
            'method' => 'POST',
            'headers' => array(
                'api-key' => $options['brevo_api_key'],
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode($payload),
            'timeout' => 30
        );
        
        // Retry logic pour gérer les erreurs temporaires
        $max_retries = 3;
        $retry_count = 0;
        
        while ($retry_count < $max_retries) {
            $response = wp_remote_post($url, $args);
            
            if (is_wp_error($response)) {
                $retry_count++;
                if ($retry_count < $max_retries) {
                    $this->log('Erreur temporaire Brevo pour ' . $contact['email'] . ', tentative ' . $retry_count . '/' . $max_retries);
                    sleep(2); // Pause de 2 secondes avant retry
                    continue;
                } else {
                    $this->log('Erreur Brevo définitive pour ' . $contact['email'] . ' : ' . $response->get_error_message());
                    return false;
                }
            }
            
            $response_code = wp_remote_retrieve_response_code($response);
            
            // Gestion des codes de réponse
            if ($response_code === 201 || $response_code === 204) {
                return true;
            } elseif ($response_code === 400) {
                // Erreur de validation - ne pas retry
                $body = wp_remote_retrieve_body($response);
                $this->log('Erreur de validation Brevo (400) pour ' . $contact['email'] . ' : ' . $body);
                return false;
            } elseif ($response_code === 429) {
                // Rate limit atteint
                $retry_count++;
                if ($retry_count < $max_retries) {
                    $this->log('Rate limit Brevo atteint pour ' . $contact['email'] . ', pause de 5 secondes (tentative ' . $retry_count . '/' . $max_retries . ')');
                    sleep(5);
                    continue;
                } else {
                    $this->log('Rate limit Brevo persistant pour ' . $contact['email']);
                    return false;
                }
            } else {
                $body = wp_remote_retrieve_body($response);
                $this->log('Erreur Brevo (' . $response_code . ') pour ' . $contact['email'] . ' : ' . $body);
                return false;
            }
        }
        
        return false;
    }
    
    private function sync_contacts_to_brevo_batch($contacts, $options, $batch_start) {
        $synced_count = 0;
        $error_count = 0;
        $skipped_count = 0;
        
        $this->log("Début de la synchronisation du lot Brevo (contacts " . ($batch_start + 1) . " à " . ($batch_start + count($contacts)) . ")");
        
        foreach ($contacts as $index => $contact) {
            $global_index = $batch_start + $index + 1;
            
            try {
                // Vérification de la limite de temps d'exécution
                if (function_exists('set_time_limit')) {
                    set_time_limit(300); // Réinitialiser à 5 minutes
                }
                
                // Validation de l'email avant traitement
                if (empty($contact['email']) || !$this->is_valid_email($contact['email'])) {
                    $skipped_count++;
                    if (!empty($contact['email'])) {
                        $this->log("Contact #{$global_index} ignoré - email invalide: " . $contact['email']);
                    }
                    continue;
                }
                
                if ($this->sync_contact_to_brevo($contact, $options)) {
                    $synced_count++;
                } else {
                    $error_count++;
                }
                
                // Pause tous les 5 contacts pour éviter de surcharger l'API
                if (($index + 1) % 5 === 0) {
                    usleep(300000); // 0.3 seconde
                }
                
                // Pause plus longue et log tous les 25 contacts
                if (($index + 1) % 25 === 0) {
                    $this->log("Lot Brevo - Progression: " . ($index + 1) . "/" . count($contacts) . " contacts traités (S:{$synced_count} E:{$error_count} I:{$skipped_count})");
                    sleep(1); // 1 seconde
                }
                
            } catch (Exception $e) {
                $error_count++;
                $this->log("Erreur fatale pour le contact #{$global_index}: " . $e->getMessage());
                
                // En cas d'erreur fatale, on continue avec le suivant
                continue;
            }
        }
        
        $this->log("Lot Brevo terminé: {$synced_count} synchronisés, {$error_count} erreurs, {$skipped_count} ignorés");
        
        return array('synced' => $synced_count, 'errors' => $error_count, 'skipped' => $skipped_count);
    }

    public function execute_sync() {
        // Augmenter les limites pour les grosses synchronisations
        if (function_exists('set_time_limit')) {
            set_time_limit(0); // Pas de limite de temps
        }
        if (function_exists('ini_set')) {
            ini_set('memory_limit', '512M'); // Augmenter la mémoire
        }
        
        $options = get_option($this->option_name);
        if (empty($options['nelis_api_url']) || empty($options['nelis_username']) || empty($options['nelis_password']) ||
            empty($options['nelis_client_id']) || empty($options['nelis_client_secret']) ||
            empty($options['brevo_api_key']) || empty($options['brevo_list_id'])) {
            $this->log('Erreur : Paramètres de configuration manquants');
            return false;
        }
        
        try {
            $start_time = microtime(true);
            $this->log('=== DÉBUT DE LA SYNCHRONISATION COMPLÈTE ===');
            
            // Récupération de tous les contacts Nelis
            $nelis_contacts = $this->get_all_nelis_contacts($options);
            
            if (empty($nelis_contacts)) {
                $this->log('Aucun contact à synchroniser depuis Nelis');
                return false;
            }
            
            $total_contacts = count($nelis_contacts);
            $this->log("Début de la synchronisation vers Brevo de {$total_contacts} contacts");
            
            // Traitement par lots de 200 pour Brevo (réduit pour plus de stabilité)
            $brevo_batch_size = 200;
            $total_synced = 0;
            $total_errors = 0;
            $total_skipped = 0;
            $batch_number = 1;
            
            for ($i = 0; $i < $total_contacts; $i += $brevo_batch_size) {
                try {
                    $batch_contacts = array_slice($nelis_contacts, $i, $brevo_batch_size);
                    $batch_count = count($batch_contacts);
                    
                    $this->log("=== TRAITEMENT DU LOT BREVO #{$batch_number} ===");
                    $this->log("Contacts " . ($i + 1) . " à " . ($i + $batch_count) . " sur {$total_contacts}");
                    
                    // Réinitialiser la limite de temps pour chaque lot
                    if (function_exists('set_time_limit')) {
                        set_time_limit(600); // 10 minutes par lot
                    }
                    
                    // Synchronisation du lot
                    $batch_results = $this->sync_contacts_to_brevo_batch($batch_contacts, $options, $i);
                    
                    $total_synced += $batch_results['synced'];
                    $total_errors += $batch_results['errors'];
                    $total_skipped += isset($batch_results['skipped']) ? $batch_results['skipped'] : 0;
                    
                    $this->log("Lot #{$batch_number} terminé: {$batch_results['synced']} synchronisés, {$batch_results['errors']} erreurs, " . (isset($batch_results['skipped']) ? $batch_results['skipped'] : 0) . " ignorés");
                    
                    // Pause longue entre les lots (sauf pour le dernier)
                    if ($i + $brevo_batch_size < $total_contacts) {
                        $this->log("Pause de 15 secondes avant le prochain lot...");
                        sleep(15);
                    }
                    
                    $batch_number++;
                    
                    // Mise à jour du progress
                    $progress_percent = round((($i + $batch_count) / $total_contacts) * 100, 1);
                    $this->log("Progression globale: {$progress_percent}% ({$total_synced} synchronisés, {$total_errors} erreurs, {$total_skipped} ignorés)");
                    
                } catch (Exception $batch_error) {
                    $this->log("Erreur fatale dans le lot #{$batch_number}: " . $batch_error->getMessage());
                    $this->log("Tentative de continuer avec le lot suivant...");
                    $batch_number++;
                    continue;
                }
            }
            
            $end_time = microtime(true);
            $duration = round($end_time - $start_time, 2);
            
            $this->log("=== SYNCHRONISATION TERMINÉE ===");
            $this->log("Durée totale: {$duration} secondes");
            $this->log("Résultats finaux: {$total_synced} contacts synchronisés, {$total_errors} erreurs, {$total_skipped} ignorés sur {$total_contacts} contacts traités");
            $this->log("Nombre de lots Brevo traités: " . ($batch_number - 1));
            
            $this->update_cron_frequency($options);
            return true;
            
        } catch (Exception $e) {
            $this->log('Erreur fatale lors de la synchronisation : ' . $e->getMessage());
            return false;
        }
    }

    private function update_cron_frequency($options) {
        $current_frequency = wp_get_schedule('ng1_nelis_brevo_sync_cron');
        $new_frequency = $options['sync_frequency'] ?? 'hourly';
        if ($current_frequency !== $new_frequency) {
            wp_clear_scheduled_hook('ng1_nelis_brevo_sync_cron');
            wp_schedule_event(time(), $new_frequency, 'ng1_nelis_brevo_sync_cron');
            $this->log('Fréquence de synchronisation mise à jour : ' . $new_frequency);
        }
    }

    private function log($message) {
        $logs = get_option('ng1_nelis_brevo_sync_logs', array());
        $logs[] = array(
            'timestamp' => current_time('mysql'),
            'message' => $message
        );
        $logs = array_slice($logs, -100); // Augmenté à 100 pour garder plus de logs
        update_option('ng1_nelis_brevo_sync_logs', $logs);
    }

    private function display_logs() {
        $logs = get_option('ng1_nelis_brevo_sync_logs', array());
        if (empty($logs)) {
            echo '<p>Aucun log disponible.</p>';
            return;
        }
        $logs = array_reverse($logs);
        foreach (array_slice($logs, 0, 20) as $log) { // Affichage des 20 derniers logs
            echo '<p><strong>' . esc_html($log['timestamp']) . '</strong> : ' . esc_html($log['message']) . '</p>';
        }
    }
}

new Ng1NelisBrevSync();