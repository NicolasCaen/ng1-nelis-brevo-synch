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

class Ng1NelisBrevSync { // phpcs:ignore
    private $option_name = 'ng1_nelis_brevo_sync_settings';
    private $log_option_name = 'ng1_nelis_brevo_sync_logs';

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
        // Ajout d'un sous-menu pour la page de comparaison
        add_submenu_page(
            'options-general.php', // Parent slug
            'Comparaison Nelis/Brevo', // Page title
            'Comparaison Nelis/Brevo', // Menu title
            'manage_options', // Capability
            'ng1_nelis_brevo_comparison', // Menu slug
            array($this, 'comparison_page') // Function
        );

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
            'nelis_api_url'       => 'URL API Nelis (ex: https://votrenom.mynelis.com)',
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
        echo '<p><strong>Important:</strong> L\'URL API Nelis doit être l\'URL de base de votre plateforme (ex: <code>https://votrenom.mynelis.com</code>), sans <code>/api/v4</code> ou <code>/oauth</code> à la fin.</p>';
    }

    public function settings_field_callback($args) {
        $options = get_option($this->option_name);
        $field_id = $args['field_id'];
        $value = isset($options[$field_id]) ? $options[$field_id] : '';

        if ($field_id === 'sync_frequency') {
            echo '<select name="' . esc_attr($this->option_name) . '[' . esc_attr($field_id) . ']">';
            echo '<option value="hourly"' . selected($value, 'hourly', false) . '>Toutes les heures</option>';
            echo '<option value="twicedaily"' . selected($value, 'twicedaily', false) . '>Deux fois par jour</option>';
            echo '<option value="daily"' . selected($value, 'daily', false) . '>Quotidienne</option>';
            echo '</select>';
        } elseif ($field_id === 'batch_size') {
            $batch_size = !empty($value) ? intval($value) : 100;
            echo '<input type="number" name="' . $this->option_name . '[' . $field_id . ']" value="' . esc_attr($batch_size) . '" class="regular-text" min="1" max="1000" />';
            echo '<p class="description">Nombre de contacts à traiter par lot (recommandé: 100)</p>';
        } elseif (in_array($field_id, ['nelis_password', 'nelis_client_secret', 'brevo_api_key'])) {
            echo '<input type="password" name="' . esc_attr($this->option_name) . '[' . esc_attr($field_id) . ']" value="' . esc_attr($value) . '" class="regular-text" />';
        } else {
            echo '<input type="text" name="' . esc_attr($this->option_name) . '[' . esc_attr($field_id) . ']" value="' . esc_attr($value) . '" class="regular-text" />';
        }
    }

    // --- Nouvelle page de comparaison ---
    public function comparison_page() {
        $options = get_option($this->option_name);
        ?>
        <div class="wrap">
            <h1>Comparaison et Synchronisation des Contacts Nelis et Brevo</h1>

            <div id="ng1-comparison-actions">
                <p>
                    <button id="ng1-refresh-comparison-btn" class="button button-primary">Actualiser les données</button>
                    <button id="ng1-export-nelis-json-btn" class="button">Exporter JSON Nelis</button>
                    <button id="ng1-export-brevo-json-btn" class="button">Exporter JSON Brevo</button>
                     <button id="ng1-sync-bidirectional-btn" class="button button-secondary" style="background-color: #d63638; color: white;">Synchronisation Bidirectionnelle</button>
                </p>
                 <div id="ng1-sync-bidirectional-result"></div>
            </div>
            <div id="ng1-comparison-result"></div>
             <div id="ng1-export-result"></div>

            <table class="wp-list-table widefat fixed striped" id="ng1-comparison-table" style="display:none;">
                <thead>
                    <tr>
                        <th>Email</th>
                        <th>Prénom (Nelis)</th>
                        <th>Nom (Nelis)</th>
                        <th>Dans Nelis ?</th>
                        <th>Dans Brevo ?</th>
                        <th>État</th>
                    </tr>
                </thead>
                <tbody>
                    <!-- Les données seront insérées ici via JavaScript -->
                </tbody>
            </table>
        </div>

        <script>
        document.addEventListener('DOMContentLoaded', function () {
            const refreshBtn = document.getElementById('ng1-refresh-comparison-btn');
            const exportNelisBtn = document.getElementById('ng1-export-nelis-json-btn');
            const exportBrevoBtn = document.getElementById('ng1-export-brevo-json-btn');
            const syncBidirectionalBtn = document.getElementById('ng1-sync-bidirectional-btn');
            const resultDiv = document.getElementById('ng1-comparison-result');
            const exportResultDiv = document.getElementById('ng1-export-result');
            const syncResultDiv = document.getElementById('ng1-sync-bidirectional-result');
            const table = document.getElementById('ng1-comparison-table');
            const tbody = table.querySelector('tbody');

            function fetchData() {
                resultDiv.innerHTML = '⏳ Chargement des données...';
                table.style.display = 'none';

                fetch(ajaxurl + '?action=ng1_get_comparison_data')
                    .then(response => response.json())
                    .then(data => {
                        resultDiv.innerHTML = '';
                        if (data.success) {
                            populateTable(data.data);
                            table.style.display = 'table';
                        } else {
                            resultDiv.innerHTML = '<div class="notice notice-error"><p>Erreur : ' + data.data + '</p></div>';
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        resultDiv.innerHTML = '<div class="notice notice-error"><p>Erreur réseau lors du chargement des données.</p></div>';
                    });
            }

            function populateTable(data) {
                tbody.innerHTML = ''; // Vider le tableau
                data.forEach(contact => {
                    const row = tbody.insertRow();
                    row.insertCell(0).textContent = contact.email || 'N/A';
                    row.insertCell(1).textContent = contact.firstname_nelis || '';
                    row.insertCell(2).textContent = contact.lastname_nelis || '';
                    row.insertCell(3).textContent = contact.in_nelis ? '✅ Oui' : '❌ Non';
                    row.insertCell(4).textContent = contact.in_brevo ? '✅ Oui' : '❌ Non';

                    let statusCell = row.insertCell(5);
                    if (contact.in_nelis && !contact.in_brevo) {
                        statusCell.textContent = 'À ajouter à Brevo';
                        statusCell.style.color = 'orange';
                    } else if (!contact.in_nelis && contact.in_brevo) {
                        statusCell.textContent = 'À supprimer de Brevo';
                        statusCell.style.color = 'red';
                    } else if (contact.in_nelis && contact.in_brevo) {
                        statusCell.textContent = 'Synchronisé';
                        statusCell.style.color = 'green';
                    } else {
                        statusCell.textContent = 'Absent des deux';
                        statusCell.style.color = 'gray';
                    }
                });
            }

            refreshBtn.addEventListener('click', fetchData);

            // Initial load
            fetchData();

            // Export Nelis
            exportNelisBtn.addEventListener('click', function() {
                 exportResultDiv.innerHTML = '⏳ Export Nelis en cours...';
                fetch(ajaxurl + '?action=ng1_export_nelis_contacts')
                    .then(response => response.json())
                    .then(data => {
                         exportResultDiv.innerHTML = '';
                        if (data.success) {
                             exportResultDiv.innerHTML = '<div class="notice notice-success"><p>Fichier JSON Nelis généré : <a href="' + data.data.url + '" download="' + data.data.filename + '">Télécharger ' + data.data.filename + '</a></p></div>';
                        } else {
                             exportResultDiv.innerHTML = '<div class="notice notice-error"><p>Erreur Export Nelis : ' + data.data + '</p></div>';
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                         exportResultDiv.innerHTML = '<div class="notice notice-error"><p>Erreur réseau lors de l\'export Nelis.</p></div>';
                    });
            });

            // Export Brevo
             exportBrevoBtn.addEventListener('click', function() {
                 exportResultDiv.innerHTML = '⏳ Export Brevo en cours...';
                fetch(ajaxurl + '?action=ng1_export_brevo_contacts')
                    .then(response => response.json())
                    .then(data => {
                         exportResultDiv.innerHTML = '';
                        if (data.success) {
                             exportResultDiv.innerHTML = '<div class="notice notice-success"><p>Fichier JSON Brevo généré : <a href="' + data.data.url + '" download="' + data.data.filename + '">Télécharger ' + data.data.filename + '</a></p></div>';
                        } else {
                             exportResultDiv.innerHTML = '<div class="notice notice-error"><p>Erreur Export Brevo : ' + data.data + '</p></div>';
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                         exportResultDiv.innerHTML = '<div class="notice notice-error"><p>Erreur réseau lors de l\'export Brevo.</p></div>';
                    });
            });

             // Synchronisation Bidirectionnelle
            syncBidirectionalBtn.addEventListener('click', function() {
                if (!confirm("Êtes-vous sûr de vouloir effectuer la synchronisation bidirectionnelle ?\n\n⚠️ Cela ajoutera les contacts de Nelis manquants dans Brevo et supprimera ceux de Brevo qui ne sont pas dans Nelis.")) {
                    return;
                }
                syncResultDiv.innerHTML = '⏳ Synchronisation bidirectionnelle en cours...';
                syncBidirectionalBtn.disabled = true;

                fetch(ajaxurl + '?action=ng1_sync_bidirectional')
                    .then(response => response.json())
                    .then(data => {
                        syncBidirectionalBtn.disabled = false;
                        syncResultDiv.innerHTML = '';
                        if (data.success) {
                            syncResultDiv.innerHTML = '<div class="notice notice-success"><p>' + data.data + '</p></div>';
                            // Rafraîchir le tableau après la synchro
                            fetchData();
                        } else {
                            syncResultDiv.innerHTML = '<div class="notice notice-error"><p>Erreur Synchro : ' + data.data + '</p></div>';
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        syncBidirectionalBtn.disabled = false;
                        syncResultDiv.innerHTML = '<div class="notice notice-error"><p>Erreur réseau lors de la synchronisation.</p></div>';
                    });
            });

        });
        </script>
        <?php
    }
    // --- Fin de la nouvelle page de comparaison ---

    public function admin_page() {
        ?>
        <div class="wrap">
            <h1>Synchronisation Nelis vers Brevo</h1>
            <div id="ng1-nelis-test-result"></div>
            <p><button id="ng1-test-nelis-btn" class="button">Tester la connexion à l'API Nelis</button></p>

            <div class="notice notice-info">
                <p><strong>Prochaine synchronisation automatique :</strong> <?php echo esc_html(date('d/m/Y H:i:s', wp_next_scheduled('ng1_nelis_brevo_sync_cron'))); ?></p>
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
                <a href="<?php echo esc_url(admin_url('admin.php?page=ng1_nelis_brevo_sync&action=manual_sync')); ?>" class="button button-secondary">Synchronisation manuelle</a>
                <a href="<?php echo esc_url(admin_url('options-general.php?page=ng1_nelis_brevo_comparison')); ?>" class="button button-primary">Aller à la Comparaison & Synchro</a>
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
                $body = wp_remote_retrieve_body($response);
                throw new Exception('Code de réponse HTTP : ' . $code . '. Réponse : ' . $body);
            }
        } catch (Exception $e) {
            wp_send_json_error(['message' => 'Erreur de connexion à lAPI Nelis : ' . $e->getMessage()]);
        }
    }

    // --- Nouvelles fonctions AJAX ---

   // --- Placez cette fonction DANS la classe Ng1NelisBrevSync ---
// Remplace ou met à jour la fonction ajax_export_nelis_contacts
public function ajax_export_nelis_contacts() {
    $options = get_option($this->option_name);

    // Vérification des paramètres de base
    if (empty($options['nelis_api_url']) || empty($options['nelis_username']) ||
        empty($options['nelis_password']) || empty($options['nelis_client_id']) ||
        empty($options['nelis_client_secret'])) {
        wp_send_json_error('Paramètres de configuration Nelis manquants.');
        return;
    }

    try {
        $all_contacts_minimal = []; // Tableau pour stocker les données minimales
        $limit = 100; // Nombre de contacts par requête
        $page = 0; // Numéro de la page/itération
        $max_pages = 10000; // Sécurité contre les boucles infinies
        $has_more_data = true;
        $total_fetched = 0; // Compteur total

        while ($has_more_data && $page < $max_pages) {
            // Calcul du range pour la pagination
            $start = $page * $limit;
            $end = $start + $limit - 1;
            $range = $start . '-' . $end;

            // La requête API demande déjà seulement email, firstname, lastname
            $url = rtrim($options['nelis_api_url'], '/') . '/api/v4/people?limit=' . $limit . '&fields=email,firstname,lastname&range=' . urlencode($range);

            // Obtenir un token d'accès frais pour chaque requête (bonne pratique)
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
                throw new Exception('Erreur connexion Nelis (Page ' . ($page + 1) . ') : ' . $response->get_error_message());
            }

            $response_code = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);

            // Vérifier le code de réponse HTTP
            if ($response_code !== 200) {
                 $decoded_check = json_decode($body, true);
                 if ($response_code == 404 || json_last_error() !== JSON_ERROR_NONE || empty($decoded_check) || (is_array($decoded_check) && empty($decoded_check))) {
                     $has_more_data = false;
                     $this->log("Fin de la récupération des contacts Nelis à la page " . ($page + 1) . " (Code HTTP: $response_code).");
                     break;
                 } else {
                    throw new Exception('Erreur API Nelis (Page ' . ($page + 1) . ', Code ' . $response_code . ') : ' . substr($body, 0, 200) . '...');
                 }
            }

            $data = json_decode($body, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->log("Erreur parsing JSON Nelis (Page " . ($page + 1) . "): " . json_last_error_msg() . ". Réponse reçue (début) : " . substr($body, 0, 500));
                throw new Exception('Erreur parsing JSON Nelis (Page ' . ($page + 1) . ') : ' . json_last_error_msg());
            }

            // Extraire les contacts de la réponse
            $items = [];
            if (isset($data['items']) && is_array($data['items'])) {
                $items = $data['items'];
            } elseif (is_array($data)) {
                 $items = $data;
            }

            // Si aucun contact n'est retourné, on considère que c'est terminé
            if (empty($items)) {
                $has_more_data = false;
                 $this->log("Aucun contact supplémentaire trouvé à la page " . ($page + 1) . ". Arrêt de la récupération.");
            } else {
                $page_count = 0;
                // Ajouter les contacts récupérés au tableau global (version minimale)
                foreach ($items as $contact) {
                    // S'assurer que les champs nécessaires sont présents
                    if (!empty($contact['email'])) {
                        $email_key = strtolower(trim($contact['email']));
                        // Stocker uniquement les champs nécessaires
                        if (!isset($all_contacts_minimal[$email_key])) {
                            $all_contacts_minimal[$email_key] = [
                                'email' => $contact['email'],
                                'firstname' => $contact['firstname'] ?? '', // Utilisez les clés correctes
                                'lastname' => $contact['lastname'] ?? '',   // Utilisez les clés correctes
                                // Ajoutez d'autres champs ici si nécessaire pour la comparaison/synchronisation
                            ];
                            $page_count++;
                            $total_fetched++;
                        }
                    } else {
                        // Optionnel : logger les contacts sans email si nécessaire
                        // $this->log("Contact sans email ignoré (Page " . ($page + 1) . "): " . print_r(array_intersect_key($contact, array_flip(['id', 'firstname', 'lastname'])), true));
                    }
                }
                 $this->log("Page " . ($page + 1) . " traitée : $page_count contacts uniques ajoutés. Total : $total_fetched");
            }

            // Passer à la page suivante
            $page++;

            // Pause très courte optionnelle
            // usleep(50000); // 0.05 seconde

        }

        if ($page >= $max_pages) {
             $this->log("Limite de pages atteinte lors de l'export Nelis.");
        }

        // Convertir le tableau associatif en tableau indexé pour le JSON final
        $final_contacts_list = array_values($all_contacts_minimal);

        if (empty($final_contacts_list)) {
             wp_send_json_error('Aucun contact trouvé dans Nelis.');
             return;
        }

        // Créer le fichier JSON avec les données minimales
        $filename = 'nelis_contacts_minimal_' . date('Y-m-d_H-i-s') . '.json';
        $filepath = WP_CONTENT_DIR . '/' . $filename;

        if (file_put_contents($filepath, json_encode($final_contacts_list, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))) {
            $url = content_url($filename);
             $this->log("Export Nelis minimal terminé : " . count($final_contacts_list) . " contacts exportés dans $filename.");

             // --- Enregistrer le nom du fichier ---
             update_option('ng1_nelis_last_export_file', $filename);
             // --- Fin enregistrement ---

             wp_send_json_success(['filename' => $filename, 'url' => $url, 'count' => count($final_contacts_list)]);
        } else {
             throw new Exception('Impossible d\'écrire le fichier JSON dans ' . $filepath);
        }

    } catch (Exception $e) {
         $error_message = $e->getMessage();
         $this->log('Erreur Export Nelis Minimal : ' . $error_message);
         wp_send_json_error($error_message);
    }
}
// --- Fin de la fonction ---


    public function ajax_export_brevo_contacts() {
        $options = get_option($this->option_name);
        try {
            $contacts = $this->get_all_brevo_list_contacts($options); // Fonction à créer
            if (empty($contacts)) {
                 wp_send_json_error('Aucun contact trouvé dans le groupe Brevo.');
                 return;
            }

            $filename = 'brevo_list_contacts_' . date('Y-m-d_H-i-s') . '.json';
            $filepath = WP_CONTENT_DIR . '/' . $filename;

            if (file_put_contents($filepath, json_encode($contacts, JSON_PRETTY_PRINT))) {
                $url = content_url($filename);
                 wp_send_json_success(['filename' => $filename, 'url' => $url]);
            } else {
                 throw new Exception('Impossible d\'écrire le fichier.');
            }
        } catch (Exception $e) {
             wp_send_json_error($e->getMessage());
        }
    }

    public function ajax_get_comparison_data() {
        $options = get_option($this->option_name);
        try {
            $comparison_data = $this->get_comparison_data($options);
             wp_send_json_success($comparison_data);
        } catch (Exception $e) {
             wp_send_json_error($e->getMessage());
        }
    }

    public function ajax_sync_bidirectional() {
        $options = get_option($this->option_name);
        if (empty($options['nelis_api_url']) || empty($options['nelis_username']) || empty($options['nelis_password']) ||
            empty($options['nelis_client_id']) || empty($options['nelis_client_secret']) ||
            empty($options['brevo_api_key']) || empty($options['brevo_list_id'])) {
             wp_send_json_error('Paramètres de configuration manquants.');
             return;
        }

        try {
            $log_messages = [];
            $added_count = 0;
            $removed_count = 0;

            // 1. Récupérer les données de comparaison
            $comparison_data = $this->get_comparison_data($options);

            // 2. Ajouter à Brevo
            foreach ($comparison_data as $contact) {
                if ($contact['in_nelis'] && !$contact['in_brevo'] && !empty($contact['email'])) {
                    $success = $this->sync_contact_to_brevo(
                        ['email' => $contact['email'], 'firstname' => $contact['firstname_nelis'], 'lastname' => $contact['lastname_nelis']],
                        $options
                    );
                    if ($success) {
                        $added_count++;
                        $log_messages[] = "Ajouté à Brevo : " . $contact['email'];
                    } else {
                        $log_messages[] = "Échec de l'ajout à Brevo : " . $contact['email'];
                    }
                }
            }

            // 3. Supprimer de Brevo
            foreach ($comparison_data as $contact) {
                if (!$contact['in_nelis'] && $contact['in_brevo'] && !empty($contact['email'])) {
                    $success = $this->remove_contact_from_brevo_list($contact['email'], $options);
                    if ($success) {
                        $removed_count++;
                        $log_messages[] = "Supprimé de Brevo : " . $contact['email'];
                    } else {
                        $log_messages[] = "Échec de la suppression de Brevo : " . $contact['email'];
                    }
                }
            }

            $message = "Synchronisation terminée : {$added_count} contacts ajoutés, {$removed_count} contacts supprimés.";
            $log_messages[] = $message;
            foreach($log_messages as $msg) {
                $this->log($msg);
            }
             wp_send_json_success($message);

        } catch (Exception $e) {
            $this->log('Erreur Synchro Bidirectionnelle : ' . $e->getMessage());
             wp_send_json_error($e->getMessage());
        }
    }

    // --- Fin des nouvelles fonctions AJAX ---

    // Correction de l'URL pour le token OAuth selon la documentation Nelis
    private function get_access_token($options) {
        // Correction de l'URL pour le token (selon le PDF "Découverte")
        $url = rtrim($options['nelis_api_url'], '/') . '/oauth/access_token';
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
            $response_body = wp_remote_retrieve_body($response);
            throw new Exception('Token Nelis manquant ou invalide. Réponse : ' . print_r($data, true) . ' | Corps brut : ' . $response_body);
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

    // Fonction modifiée pour récupérer TOUS les contacts d'un groupe Brevo
    private function get_all_brevo_list_contacts($options) {
        $all_contacts = [];
        $list_id = $options['brevo_list_id'];
        $limit = 500; // Limite max pour Brevo GET /contacts
        $offset = 0;
        $max_iterations = 200; // Sécurité
        $iterations = 0;

        do {
            $url = "https://api.brevo.com/v3/contacts?limit={$limit}&offset={$offset}&listIds={$list_id}";

            $args = array(
                'headers' => array(
                    'api-key' => $options['brevo_api_key'],
                    'accept' => 'application/json',
                ),
                'timeout' => 30
            );

            $response = wp_remote_get($url, $args);
            if (is_wp_error($response)) {
                throw new Exception('Erreur connexion Brevo : ' . $response->get_error_message());
            }

            $response_code = wp_remote_retrieve_response_code($response);
            if ($response_code !== 200) {
                $body = wp_remote_retrieve_body($response);
                throw new Exception('Erreur API Brevo (Code ' . $response_code . ') : ' . $body);
            }

            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('Erreur parsing JSON Brevo : ' . json_last_error_msg());
            }

            $contacts = $data['contacts'] ?? [];
            if (empty($contacts)) {
                break;
            }

            foreach ($contacts as $contact) {
                if (!empty($contact['email'])) {
                    $email_key = strtolower($contact['email']);
                    if (!isset($all_contacts[$email_key])) {
                        $all_contacts[$email_key] = $contact;
                    }
                }
            }

            $count = $data['count'] ?? 0;
            $offset += $limit;
            $iterations++;

            // Vérifier si on a tous les contacts
            if ($offset >= $count) {
                break;
            }

        } while ($iterations < $max_iterations);

        if ($iterations >= $max_iterations) {
             $this->log("Avertissement : Limite d'itérations atteinte lors de la récupération des contacts Brevo.");
        }

        return array_values($all_contacts);
    }

    // Nouvelle fonction pour obtenir les données de comparaison (utilise le fichier JSON Nelis)
    private function get_comparison_data($options) {
        try {
            // --- Récupération des contacts Nelis depuis le fichier JSON ---
            $all_nelis_contacts = [];
            $last_export_filename = get_option('ng1_nelis_last_export_file', '');

            if (empty($last_export_filename) || !file_exists(WP_CONTENT_DIR . '/' . $last_export_filename)) {
                // Si pas de fichier ou fichier inexistant, générer une erreur
                throw new Exception("Fichier d'export Nelis introuvable ($last_export_filename). Veuillez d'abord générer l'export JSON Nelis sur la page de comparaison.");
            }

            $json_content = file_get_contents(WP_CONTENT_DIR . '/' . $last_export_filename);
            if ($json_content === false) {
                throw new Exception("Impossible de lire le fichier d'export Nelis : " . WP_CONTENT_DIR . '/' . $last_export_filename);
            }

            $decoded_contacts = json_decode($json_content, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('Erreur parsing JSON du fichier Nelis : ' . json_last_error_msg());
            }

            // Vérifier que c'est un tableau
            if (!is_array($decoded_contacts)) {
                throw new Exception("Le fichier JSON Nelis ne contient pas un tableau valide.");
            }

            // Indexer par email pour la comparaison
            foreach ($decoded_contacts as $contact) {
                 if (!empty($contact['email'])) {
                    $email_key = strtolower(trim($contact['email']));
                    // S'assurer qu'il n'y a pas de doublons (même si le fichier ne devrait pas en contenir)
                    if (!isset($all_nelis_contacts[$email_key])) {
                        $all_nelis_contacts[$email_key] = $contact;
                    }
                }
            }
            $this->log("Données Nelis chargées depuis le fichier '$last_export_filename' : " . count($all_nelis_contacts) . " contacts uniques.");
            // --- Fin récupération Nelis depuis fichier ---


            // --- Récupération de TOUS les contacts Brevo (logique existante) ---
            $all_brevo_contacts = [];
            $list_id = $options['brevo_list_id'];
            $limit_brevo = 500; // Limite max pour Brevo GET /contacts
            $offset_brevo = 0;
            $max_iterations_brevo = 200; // Ajustez si vous avez plus de 100k contacts
            $iterations_brevo = 0;

            do {
                $url_brevo = "https://api.brevo.com/v3/contacts?limit={$limit_brevo}&offset={$offset_brevo}&listIds={$list_id}";

                $args_brevo = array(
                    'headers' => array(
                        'api-key' => $options['brevo_api_key'],
                        'accept' => 'application/json',
                    ),
                    'timeout' => 45 // Augmenté pour les grosses requêtes
                );

                $response_brevo = wp_remote_get($url_brevo, $args_brevo);
                if (is_wp_error($response_brevo)) {
                    throw new Exception('Erreur connexion Brevo : ' . $response_brevo->get_error_message());
                }

                $response_code_brevo = wp_remote_retrieve_response_code($response_brevo);
                if ($response_code_brevo !== 200) {
                    $body_brevo = wp_remote_retrieve_body($response_brevo);
                    throw new Exception('Erreur API Brevo (Code ' . $response_code_brevo . ') : ' . $body_brevo);
                }

                $body_brevo = wp_remote_retrieve_body($response_brevo);
                $data_brevo = json_decode($body_brevo, true);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new Exception('Erreur parsing JSON Brevo : ' . json_last_error_msg());
                }

                $contacts_brevo = $data_brevo['contacts'] ?? [];
                if (empty($contacts_brevo)) {
                    break; // Plus de contacts
                }

                foreach ($contacts_brevo as $contact) {
                    if (!empty($contact['email'])) {
                        $email_key = strtolower(trim($contact['email']));
                        if (!isset($all_brevo_contacts[$email_key])) {
                            $all_brevo_contacts[$email_key] = $contact;
                        }
                    }
                }

                $count_brevo = $data_brevo['count'] ?? 0;
                $offset_brevo += $limit_brevo;
                $iterations_brevo++;

                if ($offset_brevo >= $count_brevo) {
                    break;
                }

                // Petite pause pour éviter de surcharger l'API Brevo
                // usleep(100000); // 0.1 seconde

            } while ($iterations_brevo < $max_iterations_brevo);

            if ($iterations_brevo >= $max_iterations_brevo) {
                 $this->log("Avertissement : Limite d'itérations atteinte lors de la récupération des contacts Brevo.");
            }
            $this->log("Données Brevo chargées : " . count($all_brevo_contacts) . " contacts uniques dans la liste.");
            // --- Fin récupération Brevo ---


            // --- Comparaison ---
            // Créer un tableau de tous les emails uniques
            $all_emails = array_unique(array_merge(array_keys($all_nelis_contacts), array_keys($all_brevo_contacts)));

            $comparison_data = [];
            foreach ($all_emails as $email) {
                $in_nelis = isset($all_nelis_contacts[$email]);
                $in_brevo = isset($all_brevo_contacts[$email]);

                $comparison_data[] = [
                    'email' => $email,
                    'firstname_nelis' => $in_nelis ? ($all_nelis_contacts[$email]['firstname'] ?? '') : '',
                    'lastname_nelis' => $in_nelis ? ($all_nelis_contacts[$email]['lastname'] ?? '') : '',
                    'in_nelis' => $in_nelis,
                    'in_brevo' => $in_brevo,
                ];
            }
            $this->log("Comparaison terminée : " . count($comparison_data) . " contacts uniques au total.");
            // --- Fin Comparaison ---

            return $comparison_data;

        } catch (Exception $e) {
            $error_msg = 'Erreur dans get_comparison_data : ' . $e->getMessage();
            $this->log($error_msg);
            throw new Exception($error_msg); // Relancer pour que l'AJAX la gère
        }
    }

    // --- Fin des fonctions modifiées/nouvelles ---

    // Fonction existante, légèrement modifiée pour le logging et la gestion des erreurs
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
            'updateEnabled' => true // Met à jour si existe
        );
        $args = array(
            'method' => 'POST',
            'headers' => array(
                'api-key' => $options['brevo_api_key'],
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ),
            'body' => wp_json_encode($payload), // Utilisation de wp_json_encode
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

    // Nouvelle fonction pour supprimer un contact d'une liste Brevo
    private function remove_contact_from_brevo_list($email, $options) {
         if (empty($email)) {
            return false;
        }
        $list_id = intval($options['brevo_list_id']);
        $url = "https://api.brevo.com/v3/contacts/lists/{$list_id}/contacts/remove";
        $payload = array(
            'emails' => array($email)
        );
        $args = array(
            'method' => 'POST',
            'headers' => array(
                'api-key' => $options['brevo_api_key'],
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ),
            'body' => wp_json_encode($payload), // Utilisation de wp_json_encode
            'timeout' => 30
        );

        $response = wp_remote_post($url, $args);
        if (is_wp_error($response)) {
            $this->log('Erreur suppression Brevo pour ' . $email . ' : ' . $response->get_error_message());
            return false;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        // Brevo retourne 201 ou 204 en cas de succès, ou 207 si partiel (mais ici on supprime un seul contact)
        if (in_array($response_code, [200, 201, 204])) {
            return true;
        } else {
            $body = wp_remote_retrieve_body($response);
            $this->log('Erreur suppression Brevo (' . $response_code . ') pour ' . $email . ' : ' . $body);
            return false;
        }
    }

    // Fonction existante, inchangée
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
                $this->log('Aucun contact à synchroniser depuis Nelis (Sync Auto)');
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

    // Fonction existante, inchangée
    private function update_cron_frequency($options) {
        $current_frequency = wp_get_schedule('ng1_nelis_brevo_sync_cron');
        $new_frequency = $options['sync_frequency'] ?? 'hourly';
        if ($current_frequency !== $new_frequency) {
            wp_clear_scheduled_hook('ng1_nelis_brevo_sync_cron');
            wp_schedule_event(time(), $new_frequency, 'ng1_nelis_brevo_sync_cron');
            $this->log('Fréquence de synchronisation mise à jour : ' . $new_frequency);
        }
    }

    // Fonction existante, inchangée
    private function log($message) {
        $logs = get_option($this->log_option_name, array());
        $logs[] = array(
            'timestamp' => current_time('mysql'),
            'message' => $message
        );
        $logs = array_slice($logs, -100); // Augmenté à 100 pour garder plus de logs
        update_option('ng1_nelis_brevo_sync_logs', $logs);
    }

    // Fonction existante, inchangée
    private function display_logs() {
        $logs = get_option($this->log_option_name, array());
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