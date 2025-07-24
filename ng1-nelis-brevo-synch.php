<?php
/**
 * Plugin Name: NG1 | Nelis Brevo Sync
 * Plugin URI: https://example.com
 * Description: Synchronise les contacts de Nelis vers un groupe Brevo
 * Version: 1.1.0
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
        add_action('wp_ajax_ng1_test_brevo_connection', array($this, 'ajax_test_brevo_connection'));

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
            'bidirectional_sync'  => 'Supprimer les contacts Brevo absents de Nelis'
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
        } elseif ($field_id === 'bidirectional_sync') {
            echo '<input type="checkbox" name="' . $this->option_name . '[' . $field_id . ']" value="1"' . checked($value, '1', false) . ' />';
            echo '<label> Activer la suppression des contacts Brevo qui ne sont plus dans Nelis</label>';
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
            <div id="ng1-brevo-test-result"></div>
            <p>
                <button id="ng1-test-nelis-btn" class="button">Tester la connexion à l'API Nelis</button>
                <button id="ng1-test-brevo-btn" class="button">Tester la connexion à l'API Brevo</button>
            </p>

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
                    document.getElementById('ng1-nelis-test-result').innerHTML = data.success ? 
                        '<div class="notice notice-success"><p>' + data.data.message + '</p></div>' : 
                        '<div class="notice notice-error"><p>' + data.data.message + '</p></div>';
                });
        });

        document.getElementById('ng1-test-brevo-btn').addEventListener('click', function () {
            const btn = this;
            btn.disabled = true;
            document.getElementById('ng1-brevo-test-result').innerHTML = '⏳ Test en cours...';
            fetch(ajaxurl + '?action=ng1_test_brevo_connection')
                .then(r => r.json())
                .then(data => {
                    btn.disabled = false;
                    document.getElementById('ng1-brevo-test-result').innerHTML = data.success ? 
                        '<div class="notice notice-success"><p>' + data.data.message + '</p></div>' : 
                        '<div class="notice notice-error"><p>' + data.data.message + '</p></div>';
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
            
            // Test basique d'abord
            $test_url = rtrim($options['nelis_api_url'], '/') . '/api/v4/people?limit=1';
            $response = wp_remote_get($test_url, [
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
                $body = wp_remote_retrieve_body($response);
                $data = json_decode($body, true);
                
                // Essayer de récupérer le nombre total via l'endpoint count
                $total_contacts = 'Non disponible';
                try {
                    $count_url = rtrim($options['nelis_api_url'], '/') . '/api/v4/people/count';
                    $count_response = wp_remote_get($count_url, [
                        'headers' => [
                            'Authorization' => 'Bearer ' . $token,
                            'Content-Type' => 'application/json'
                        ],
                        'timeout' => 15
                    ]);
                    
                    if (!is_wp_error($count_response)) {
                        $count_body = wp_remote_retrieve_body($count_response);
                        $count_data = json_decode($count_body, true);
                        
                        // Essayer différents formats de réponse
                        if (isset($count_data['count'])) {
                            $total_contacts = $count_data['count'];
                        } elseif (isset($count_data['total'])) {
                            $total_contacts = $count_data['total'];
                        } elseif (is_numeric($count_data)) {
                            $total_contacts = $count_data;
                        } else {
                            // Debug: voir le format exact de la réponse
                            $total_contacts = 'Format: ' . json_encode($count_data);
                        }
                    }
                } catch (Exception $e) {
                    // Si l'endpoint count échoue, on continue sans
                    $total_contacts = 'Endpoint count non disponible';
                }
                
                // Vérifier le format de la réponse principale
                $format_info = '';
                if (isset($data['hydra:member'])) {
                    $format_info = ' (Format Hydra: ' . count($data['hydra:member']) . ' contacts dans cette page)';
                } elseif (isset($data['items'])) {
                    $format_info = ' (Format Items: ' . count($data['items']) . ' contacts dans cette page)';
                } elseif (is_array($data) && isset($data[0]) && is_array($data[0])) {
                    $format_info = ' (Format tableau indexé: ' . count($data) . ' contacts dans cette page)';
                } else {
                    $format_info = ' (Format inconnu: ' . json_encode(array_keys($data)) . ')';
                }
                
                wp_send_json_success(['message' => 'Connexion API Nelis réussie (code ' . $code . '). Total contacts : ' . $total_contacts . $format_info]);
            } else {
                throw new Exception('Code de réponse HTTP : ' . $code);
            }
        } catch (Exception $e) {
            wp_send_json_error(['message' => 'Erreur de connexion à l\'API Nelis : ' . $e->getMessage()]);
        }
    }

    public function ajax_test_brevo_connection() {
        $options = get_option($this->option_name);
        try {
            if (empty($options['brevo_api_key']) || empty($options['brevo_list_id'])) {
                throw new Exception('Clé API Brevo ou ID de liste manquant');
            }

            $url = 'https://api.brevo.com/v3/contacts/lists/' . $options['brevo_list_id'];
            $response = wp_remote_get($url, [
                'headers' => [
                    'api-key' => $options['brevo_api_key'],
                    'Content-Type' => 'application/json'
                ],
                'timeout' => 15
            ]);
            
            if (is_wp_error($response)) {
                throw new Exception($response->get_error_message());
            }
            
            $code = wp_remote_retrieve_response_code($response);
            if ($code >= 200 && $code < 300) {
                $body = wp_remote_retrieve_body($response);
                $data = json_decode($body, true);
                $list_name = $data['name'] ?? 'Non disponible';
                $contact_count = $data['totalSubscribers'] ?? 'Non disponible';
                wp_send_json_success(['message' => 'Connexion API Brevo réussie. Liste : ' . $list_name . ' (' . $contact_count . ' contacts)']);
            } else {
                throw new Exception('Code de réponse HTTP : ' . $code);
            }
        } catch (Exception $e) {
            wp_send_json_error(['message' => 'Erreur de connexion à l\'API Brevo : ' . $e->getMessage()]);
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

    private function get_nelis_contacts($options) {
        $access_token = $this->get_access_token($options);
        $base_url = rtrim($options['nelis_api_url'], '/') . '/api/v4/people';
        $headers = array(
            'Authorization' => 'Bearer ' . $access_token,
            'Content-Type'  => 'application/json'
        );
        
        // Essayer de récupérer le nombre total de contacts
        $total_contacts = 0;
        try {
            $count_url = rtrim($options['nelis_api_url'], '/') . '/api/v4/people/count';
            $count_response = wp_remote_get($count_url, [
                'headers' => $headers,
                'timeout' => 30
            ]);
            
            if (!is_wp_error($count_response)) {
                $count_body = wp_remote_retrieve_body($count_response);
                $count_data = json_decode($count_body, true);
                
                if (isset($count_data['count'])) {
                    $total_contacts = $count_data['count'];
                } elseif (isset($count_data['total'])) {
                    $total_contacts = $count_data['total'];
                } elseif (is_numeric($count_data)) {
                    $total_contacts = $count_data;
                }
            }
        } catch (Exception $e) {
            $this->log("Impossible de récupérer le count total : " . $e->getMessage());
        }
        
        if ($total_contacts > 0) {
            $this->log("Nombre total de contacts dans Nelis : {$total_contacts}");
        } else {
            $this->log("Nombre total de contacts inconnu, récupération de tous les contacts disponibles");
        }
        
        // Récupérer tous les contacts par pagination
        $all_contacts = [];
        $offset = 0;
        $limit = 100;
        $page = 1;
        
        do {
            $url = $base_url . '?limit=' . $limit . '&offset=' . $offset;
            $this->log("Récupération page {$page} : offset={$offset}, limit={$limit}");
            
            $response = wp_remote_get($url, [
                'headers' => $headers,
                'timeout' => 30
            ]);
    
            if (is_wp_error($response)) {
                throw new Exception('Erreur connexion Nelis page ' . $page . ' : ' . $response->get_error_message());
            }
    
            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);
    
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('Erreur parsing JSON Nelis page ' . $page);
            }
    
            // Supporter différents formats de réponse API
            $contacts_in_page = [];
            if (isset($data['hydra:member'])) {
                $contacts_in_page = $data['hydra:member'];
                $this->log("Format Hydra détecté - Page {$page} : " . count($contacts_in_page) . " contacts");
            } elseif (isset($data['items'])) {
                $contacts_in_page = $data['items'];
                $this->log("Format Items détecté - Page {$page} : " . count($contacts_in_page) . " contacts");
            } elseif (is_array($data) && isset($data[0]) && is_array($data[0])) {
                // Format tableau indexé numériquement (votre cas)
                $contacts_in_page = array_values($data);
                $this->log("Format tableau indexé détecté - Page {$page} : " . count($contacts_in_page) . " contacts");
            } elseif (is_array($data) && !empty($data)) {
                // Si c'est directement un tableau de contacts
                $contacts_in_page = $data;
                $this->log("Format direct détecté - Page {$page} : " . count($contacts_in_page) . " contacts");
            } else {
                $this->log("Format de réponse inattendu page {$page} : " . json_encode(array_keys($data)));
                break;
            }
            
            // Récupérer les détails étendus pour chaque contact
            foreach ($contacts_in_page as $contact) {
                if (isset($contact['id'])) {
                    $extended_contact = $this->get_nelis_contact_extended($contact['id'], $access_token, $options);
                    if ($extended_contact && !empty($extended_contact['email'])) {
                        $all_contacts[] = $extended_contact;
                    }
                }
                
                // Petite pause pour éviter de surcharger l'API
                usleep(50000); // 0.05 seconde
            }
            
            $offset += $limit;
            $page++;
            
            // Condition d'arrêt : si on a moins de contacts que la limite, c'est la dernière page
            $continue = count($contacts_in_page) === $limit;
            
            // Sécurité : éviter les boucles infinies
            if ($page > 1000) {
                $this->log("Arrêt de sécurité : plus de 1000 pages récupérées");
                break;
            }
            
        } while ($continue);
        
        $this->log("Total final : " . count($all_contacts) . " contacts avec email récupérés");
        return $all_contacts;
    }
    
    private function get_nelis_contact_extended($contact_id, $access_token, $options) {
        $url = rtrim($options['nelis_api_url'], '/') . '/api/v4/people/' . $contact_id . '/extended';
        $headers = array(
            'Authorization' => 'Bearer ' . $access_token,
            'Content-Type'  => 'application/json'
        );
        
        $response = wp_remote_get($url, [
            'headers' => $headers,
            'timeout' => 15
        ]);
        
        if (is_wp_error($response)) {
            $this->log('Erreur récupération contact étendu ' . $contact_id . ' : ' . $response->get_error_message());
            return null;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->log('Erreur parsing JSON contact étendu ' . $contact_id);
            return null;
        }
        
        // Extraire l'email principal
        $email = '';
        if (!empty($data['email_addresses'])) {
            foreach ($data['email_addresses'] as $email_addr) {
                if (!empty($email_addr['email'])) {
                    $email = $email_addr['email'];
                    break;
                }
            }
        }
        
        // Extraire le téléphone principal
        $phone = '';
        if (!empty($data['phones'])) {
            foreach ($data['phones'] as $phone_data) {
                if (!empty($phone_data['number'])) {
                    $phone = $phone_data['number'];
                    break;
                }
            }
        }
        
        return array(
            'id' => $data['id'],
            'email' => $email,
            'firstname' => $data['firstname'] ?? '',
            'lastname' => $data['lastname'] ?? '',
            'phone' => $phone
        );
    }

    private function get_brevo_contacts($options) {
        $url = 'https://api.brevo.com/v3/contacts/lists/' . $options['brevo_list_id'] . '/contacts';
        $headers = array(
            'api-key' => $options['brevo_api_key'],
            'Content-Type' => 'application/json'
        );
        
        $all_contacts = [];
        $offset = 0;
        $limit = 50;
        
        do {
            $query_url = $url . '?limit=' . $limit . '&offset=' . $offset;
            $response = wp_remote_get($query_url, [
                'headers' => $headers,
                'timeout' => 30
            ]);
            
            if (is_wp_error($response)) {
                throw new Exception('Erreur connexion Brevo : ' . $response->get_error_message());
            }
            
            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('Erreur parsing JSON Brevo');
            }
            
            $contacts_in_page = $data['contacts'] ?? [];
            $all_contacts = array_merge($all_contacts, $contacts_in_page);
            
            $offset += $limit;
        } while (count($contacts_in_page) === $limit);
        
        return $all_contacts;
    }

    private function sync_contact_to_brevo($contact, $options) {
        if (empty($contact['email'])) {
            return false;
        }
        
        $url = 'https://api.brevo.com/v3/contacts';
        $payload = array(
            'email' => $contact['email'],
            'attributes' => array(
                'FIRSTNAME' => $contact['firstname'] ?? '',
                'LASTNAME'  => $contact['lastname'] ?? '',
                'PHONE'     => $contact['phone'] ?? ''
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
        
        $response = wp_remote_post($url, $args);
        if (is_wp_error($response)) {
            $this->log('Erreur Brevo pour ' . $contact['email'] . ' : ' . $response->get_error_message());
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code === 201 || $response_code === 204) {
            return true;
        } else {
            $body = wp_remote_retrieve_body($response);
            $this->log('Erreur Brevo (' . $response_code . ') pour ' . $contact['email'] . ' : ' . $body);
            return false;
        }
    }

    private function remove_contact_from_brevo($email, $options) {
        $url = 'https://api.brevo.com/v3/contacts/' . urlencode($email);
        $args = array(
            'method' => 'DELETE',
            'headers' => array(
                'api-key' => $options['brevo_api_key'],
                'Content-Type' => 'application/json'
            ),
            'timeout' => 30
        );
        
        $response = wp_remote_request($url, $args);
        if (is_wp_error($response)) {
            $this->log('Erreur suppression Brevo pour ' . $email . ' : ' . $response->get_error_message());
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code === 204) {
            return true;
        } else {
            $body = wp_remote_retrieve_body($response);
            $this->log('Erreur suppression Brevo (' . $response_code . ') pour ' . $email . ' : ' . $body);
            return false;
        }
    }

    public function execute_sync() {
        $options = get_option($this->option_name);
        if (empty($options['nelis_api_url']) || empty($options['nelis_username']) || empty($options['nelis_password']) ||
            empty($options['nelis_client_id']) || empty($options['nelis_client_secret']) ||
            empty($options['brevo_api_key']) || empty($options['brevo_list_id'])) {
            $this->log('Erreur : Paramètres de configuration manquants');
            return false;
        }
        
        try {
            $this->log('Début de la synchronisation');
            
            // Récupération des contacts Nelis
            $nelis_contacts = $this->get_nelis_contacts($options);
            if (empty($nelis_contacts)) {
                $this->log('Aucun contact à synchroniser depuis Nelis');
                return false;
            }
            
            $this->log('Contacts Nelis récupérés : ' . count($nelis_contacts));
            
            // Synchronisation vers Brevo
            $synced_count = 0;
            $error_count = 0;
            
            foreach ($nelis_contacts as $contact) {
                if ($this->sync_contact_to_brevo($contact, $options)) {
                    $synced_count++;
                } else {
                    $error_count++;
                }
                
                // Pause pour éviter de surcharger l'API
                usleep(100000); // 0.1 seconde
            }
            
            $this->log("Synchronisation Nelis->Brevo : {$synced_count} contacts synchronisés, {$error_count} erreurs");
            
            // Synchronisation bidirectionnelle (suppression des contacts Brevo absents de Nelis)
            if (!empty($options['bidirectional_sync'])) {
                $this->log('Début de la synchronisation bidirectionnelle');
                
                $brevo_contacts = $this->get_brevo_contacts($options);
                $nelis_emails = array_column($nelis_contacts, 'email');
                $nelis_emails = array_filter($nelis_emails); // Supprimer les emails vides
                
                $removed_count = 0;
                foreach ($brevo_contacts as $brevo_contact) {
                    $brevo_email = $brevo_contact['email'];
                    if (!in_array($brevo_email, $nelis_emails)) {
                        if ($this->remove_contact_from_brevo($brevo_email, $options)) {
                            $removed_count++;
                            $this->log('Contact supprimé de Brevo : ' . $brevo_email);
                        }
                        usleep(100000); // Pause
                    }
                }
                
                $this->log("Synchronisation bidirectionnelle : {$removed_count} contacts supprimés de Brevo");
            }
            
            $this->log("Synchronisation terminée avec succès");
            $this->update_cron_frequency($options);
            return true;
            
        } catch (Exception $e) {
            $this->log('Erreur lors de la synchronisation : ' . $e->getMessage());
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
        $logs = array_slice($logs, -50);
        update_option('ng1_nelis_brevo_sync_logs', $logs);
    }

    private function display_logs() {
        $logs = get_option('ng1_nelis_brevo_sync_logs', array());
        if (empty($logs)) {
            echo '<p>Aucun log disponible.</p>';
            return;
        }
        $logs = array_reverse($logs);
        foreach (array_slice($logs, 0, 10) as $log) {
            echo '<p><strong>' . esc_html($log['timestamp']) . '</strong> : ' . esc_html($log['message']) . '</p>';
        }
    }
}

new Ng1NelisBrevSync();