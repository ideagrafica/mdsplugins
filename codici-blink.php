<?php
/**
 * Plugin Name: Codici Monouso per Ordini E-learning
 * Description: Plugin per caricare codici monouso da CSV e inviarli via email per ordini completati di prodotti nella categoria "e-learning".
 * Version: 1.2
 * Author: Marco De Sangro
 */

if (!defined('ABSPATH')) {
    exit; // Evita accessi diretti
}

class CodiciMonousoElearning {

    private $codici_table;
    private $used_codici_table; // Tabella per i codici utilizzati
    private $notification_threshold; // Soglia per la notifica

    public function __construct() {
        global $wpdb;
        $this->codici_table = $wpdb->prefix . 'codici_monouso';
        $this->used_codici_table = $wpdb->prefix . 'codici_utilizzati'; // Nuova tabella

        register_activation_hook(__FILE__, [$this, 'create_table']);
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_post_upload_csv', [$this, 'upload_csv']);
        add_action('admin_post_save_email_template', [$this, 'save_email_template']);
        add_action('admin_post_save_notification_email', [$this, 'save_notification_email']);
        add_action('admin_post_delete_all_codici', [$this, 'delete_all_codici']);
        add_action('admin_post_delete_codice', [$this, 'delete_codice']);
        add_action('woocommerce_order_status_completed', [$this, 'process_order']);
        add_action('admin_post_save_notification_threshold', [$this, 'save_notification_threshold']); // Nuova azione
        add_action('wp', [$this, 'schedule_weekly_report']); // Pianifica il report settimanale
        add_action('send_weekly_report', [$this, 'send_weekly_report']); // Azione per inviare il report
    }

    public function create_table() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        // Creazione della tabella per i codici utilizzati
        $sql_used = "CREATE TABLE $this->used_codici_table (
            id BIGINT(20) UNSIGNED AUTO_INCREMENT,
            codice VARCHAR(255) NOT NULL,
            codice_editore VARCHAR(255) NOT NULL,
            titolo_prodotto VARCHAR(255) NOT NULL,
            isbn VARCHAR(255) NOT NULL,
            PRIMARY KEY (id)
        ) $charset_collate;";

        $sql = "CREATE TABLE $this->codici_table (
            id BIGINT(20) UNSIGNED AUTO_INCREMENT,
            codice VARCHAR(255) NOT NULL,
            codice_editore VARCHAR(255) NOT NULL,
            titolo_prodotto VARCHAR(255) NOT NULL,
            isbn VARCHAR(255) NOT NULL,
            PRIMARY KEY (id)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        dbDelta($sql_used);

        if (get_option('codici_email_template') === false) {
            add_option('codici_email_template', 'Grazie per il tuo ordine! Ecco il tuo codice: {{codice}}');
        }

        if (get_option('codici_notification_email') === false) {
            add_option('codici_notification_email', get_option('admin_email'));
        }

        if (get_option('codici_notification_threshold') === false) {
            add_option('codici_notification_threshold', 2); // Soglia predefinita
        }
    }

    public function add_admin_menu() {
        add_menu_page(
            'Codici Monouso',
            'Codici Monouso',
            'manage_options',
            'codici-monouso',
            [$this, 'render_admin_page'],
            'dashicons-clipboard'
        );
    }

    public function render_admin_page() {
        global $wpdb;
        $codici = $wpdb->get_results("SELECT * FROM $this->codici_table");
        $used_codici = $wpdb->get_results("SELECT * FROM $this->used_codici_table");
        $email_template = get_option('codici_email_template', '');
        $notification_email = get_option('codici_notification_email', '');
        $notification_threshold = get_option('codici_notification_threshold', 2);
        ?>
        <div class="wrap">
            <h1>Codici Monouso</h1>

            <form action="<?php echo admin_url('admin-post.php'); ?>" method="post">
                <input type="hidden" name="action" value="save_email_template">
                <h2>Template Email</h2>
                <p>Personalizza l'email che verrà inviata agli utenti. Usa <code>{{codice}}</code> dove vuoi che appaia il codice.</p>
                <?php
                wp_editor(
                    $email_template,
                    'email_template',
                    [
                        'textarea_name' => 'email_template',
                        'textarea_rows' => 10,
                        'media_buttons' => false,
                    ]
                );
                ?>
                <button type="submit" class="button button-primary">Salva Template</button>
            </form>

            <form action="<?php echo admin_url('admin-post.php'); ?>" method="post">
                <input type="hidden" name="action" value="save_notification_email">
                <h2>Email di Notifica</h2>
                <p>Specifica l'indirizzo email che riceverà la notifica quando i codici stanno per terminare.</p>
                <input type="email" name="notification_email" value="<?php echo esc_attr($notification_email); ?>" class="regular-text" required>
                <button type="submit" class="button button-primary">Salva Email</button>
            </form>

            <form action="<?php echo admin_url('admin-post.php'); ?>" method="post">
                <input type="hidden" name="action" value="save_notification_threshold">
                <h2>Soglia Notifica Codici</h2>
                <p>Inserisci il numero di codici rimanenti per inviare la notifica.</p>
                <input type="number" name="notification_threshold" value="<?php echo esc_attr($notification_threshold); ?>" class="small-text" required>
                <button type="submit" class="button button-primary">Salva Soglia</button>
            </form>

            <form action="<?php echo admin_url('admin-post.php'); ?>" method="post" enctype="multipart/form-data">
                <input type="hidden" name="action" value="upload_csv">
                <h2>Carica Codici da CSV</h2>
                <input type="file" name="csv_file" accept=".csv" required>
                <button type="submit" class="button button-primary">Carica CSV</button>
            </form>

            <form action="<?php echo admin_url('admin-post.php'); ?>" method="post">
                <input type="hidden" name="action" value="delete_all_codici">
                <button type="submit" class="button button-secondary" onclick="return confirm('Sei sicuro di voler eliminare tutti i codici?');">Elimina Tutti i Codici</button>
            </form>

            <h2>Codici Disponibili</h2>
            <table class="widefat">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Codice</th>
                        <th>Codice Editore</th>
                        <th>Titolo Prodotto</th>
                        <th>ISBN</th>
                        <th>Azioni</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($codici as $codice): ?>
                        <tr>
                            <td><?php echo $codice->id; ?></td>
                            <td><?php echo $codice->codice; ?></td>
                            <td><?php echo $codice->codice_editore; ?></td>
                            <td><?php echo $codice->titolo_prodotto; ?></td>
                            <td><?php echo $codice->isbn; ?></td>
                            <td>
                                <form action="<?php echo admin_url('admin-post.php'); ?>" method="post" style="display:inline;">
                                    <input type="hidden" name="action" value="delete_codice">
                                    <input type="hidden" name="codice_id" value="<?php echo $codice->id; ?>">
                                    <button type="submit" class="button button-link-delete" onclick="return confirm('Sei sicuro di voler eliminare questo codice?');">Elimina</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <h2>Codici Utilizzati</h2>
            <table class="widefat">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Codice</th>
                        <th>Codice Editore</th>
                        <th>Titolo Prodotto</th>
                        <th>ISBN</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($used_codici as $used_codice): ?>
                        <tr>
                            <td><?php echo $used_codice->id; ?></td>
                            <td><?php echo $used_codice->codice; ?></td>
                            <td><?php echo $used_codice->codice_editore; ?></td>
                            <td><?php echo $used_codice->titolo_prodotto; ?></td>
                            <td><?php echo $used_codice->isbn; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public function save_email_template() {
        if (!current_user_can('manage_options')) {
            wp_die('Non hai il permesso di accedere a questa pagina.');
        }

        if (isset($_POST['email_template'])) {
            $email_template = wp_kses_post($_POST['email_template']);
            update_option('codici_email_template', $email_template);
        }

        wp_redirect(admin_url('admin.php?page=codici-monouso'));
        exit;
    }

    public function save_notification_email() {
        if (!current_user_can('manage_options')) {
            wp_die('Non hai il permesso di accedere a questa pagina.');
        }

        if (isset($_POST['notification_email'])) {
            $notification_email = sanitize_email($_POST['notification_email']);
            update_option('codici_notification_email', $notification_email);
        }

        wp_redirect(admin_url('admin.php?page=codici-monouso'));
        exit;
    }

    public function upload_csv() {
        if (!current_user_can('manage_options')) {
            wp_die('Non hai il permesso di accedere a questa pagina.');
        }

        if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
            wp_die('Errore durante il caricamento del file.');
        }

        $file = fopen($_FILES['csv_file']['tmp_name'], 'r');
        global $wpdb;

        while (($line = fgetcsv($file)) !== false) {
            $codice = sanitize_text_field($line[0]);
            $codice_editore = sanitize_text_field($line[1]);
            $titolo_prodotto = sanitize_text_field($line[2]);
            $isbn = sanitize_text_field($line[3]);
            $wpdb->insert($this->codici_table, [
                'codice' => $codice,
                'codice_editore' => $codice_editore,
                'titolo_prodotto' => $titolo_prodotto,
                'isbn' => $isbn
            ]);
        }

        fclose($file);
        wp_redirect(admin_url('admin.php?page=codici-monouso'));
        exit;
    }

    public function delete_all_codici() {
        if (!current_user_can('manage_options')) {
            wp_die('Non hai il permesso di accedere a questa pagina.');
        }

        global $wpdb;
        $wpdb->query("TRUNCATE TABLE $this->codici_table");

        wp_redirect(admin_url('admin.php?page=codici-monouso'));
        exit;
    }

    public function delete_codice() {
        if (!current_user_can('manage_options')) {
            wp_die('Non hai il permesso di accedere a questa pagina.');
        }

        if (isset($_POST['codice_id'])) {
            $codice_id = intval($_POST['codice_id']);

            global $wpdb;
            $wpdb->delete($this->codici_table, ['id' => $codice_id]);
        }

        wp_redirect(admin_url('admin.php?page=codici-monouso'));
        exit;
    }

    // Processa ordine completato
    public function process_order($order_id) {
        $order = wc_get_order($order_id);

        foreach ($order->get_items() as $item) {
            $product = $item->get_product();

            if ($product && has_term('e-learning', 'product_cat', $product->get_id())) {
                global $wpdb;
                $isbn = $product->get_sku(); // Ottieni l'ISBN dal prodotto
                $codice = $wpdb->get_row($wpdb->prepare("SELECT * FROM $this->codici_table WHERE isbn = %s LIMIT 1", $isbn));

                if ($codice) {
                    $wpdb->delete($this->codici_table, ['id' => $codice->id]);

                    // Salva il codice utilizzato nella nuova tabella
                    $wpdb->insert($this->used_codici_table, [
                        'codice' => $codice->codice,
                        'codice_editore' => $codice->codice_editore,
                        'titolo_prodotto' => $codice->titolo_prodotto,
                        'isbn' => $codice->isbn
                    ]);

                    // Imposta le informazioni per l'email
                    $to = $order->get_billing_email();
                    $subject = 'Il tuo codice E-learning è pronto!';
                    $email_template = get_option('codici_email_template', 'Grazie per il tuo ordine! Ecco il tuo codice: {{codice}}');
                    
                    // Sostituisci {{codice}} nel template
                    $message = str_replace('{{codice}}', $codice->codice, $email_template);

                    // Imposta le intestazioni per inviare l'email in formato HTML
                    $headers = ['Content-Type: text/html; charset=UTF-8'];

                    // Invia l'email
                    wp_mail($to, $subject, $message, $headers);

                    // Controlla il numero di codici rimanenti
                    $remaining_codici = $wpdb->get_var("SELECT COUNT(*) FROM $this->codici_table");
                    $notification_threshold = get_option('codici_notification_threshold', 2);
                    if ($remaining_codici == $notification_threshold) {
                        $notification_email = get_option('codici_notification_email', get_option('admin_email'));
                        $admin_subject = 'Attenzione: Codici Monouso in esaurimento';
                        $admin_message = 'La lista dei codici monouso sta per terminare. Sono rimasti solo ' . $remaining_codici . ' codici. Si prega di caricare una nuova lista.';
                        wp_mail($notification_email, $admin_subject, $admin_message, $headers);
                    }

                    break; // Invio il codice solo una volta per ordine
                }
            }
        }
    }

    public function save_notification_threshold() {
        if (!current_user_can('manage_options')) {
            wp_die('Non hai il permesso di accedere a questa pagina.');
        }

        if (isset($_POST['notification_threshold'])) {
            $notification_threshold = intval($_POST['notification_threshold']);
            update_option('codici_notification_threshold', $notification_threshold);
        }

        wp_redirect(admin_url('admin.php?page=codici-monouso'));
        exit;
    }

    public function schedule_weekly_report() {
        if (!wp_next_scheduled('send_weekly_report')) {
            wp_schedule_event(time(), 'weekly', 'send_weekly_report');
        }
    }

    public function send_weekly_report() {
        global $wpdb;
        $used_codici = $wpdb->get_results("SELECT * FROM $this->used_codici_table");
        $notification_email = get_option('codici_notification_email', get_option('admin_email'));

        if ($used_codici) {
            $message = '<h2>Report Settimanale dei Codici Utilizzati</h2>';
            $message .= '<table style="border-collapse: collapse; width: 100%;">';
            $message .= '<thead><tr><th style="border: 1px solid #ddd; padding: 8px;">ID</th><th style="border: 1px solid #ddd; padding: 8px;">Codice</th><th style="border: 1px solid #ddd; padding: 8px;">Codice Editore</th><th style="border: 1px solid #ddd; padding: 8px;">Titolo Prodotto</th><th style="border: 1px solid #ddd; padding: 8px;">ISBN</th></tr></thead>';
            $message .= '<tbody>';

            foreach ($used_codici as $used_codice) {
                $message .= '<tr>';
                $message .= '<td style="border: 1px solid #ddd; padding: 8px;">' . $used_codice->id . '</td>';
                $message .= '<td style="border: 1px solid #ddd; padding: 8px;">' . $used_codice->codice . '</td>';
                $message .= '<td style="border: 1px solid #ddd; padding: 8px;">' . $used_codice->codice_editore . '</td>';
                $message .= '<td style="border: 1px solid #ddd; padding: 8px;">' . $used_codice->titolo_prodotto . '</td>';
                $message .= '<td style="border: 1px solid #ddd; padding: 8px;">' . $used_codice->isbn . '</td>';
                $message .= '</tr>';
            }

            $message .= '</tbody></table>';
        } else {
            $message = 'Nessun codice utilizzato questa settimana.';
        }

        $subject = 'Report Settimanale dei Codici Utilizzati';
        $headers = ['Content-Type: text/html; charset=UTF-8'];

        wp_mail($notification_email, $subject, $message, $headers);
    }
}

new CodiciMonousoElearning();