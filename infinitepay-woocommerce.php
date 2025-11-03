<?php
/**
 * Plugin Name: InfinitePay for WooCommerce
 * Description: Integração com InfinitePay via link de checkout, com suporte a Pix, boleto, cartão e QR Code.
 * Version: 1.0
 * Author: jjmmbb
 * Text Domain: infinitepay
 */

if (!defined('ABSPATH')) exit;

add_action('plugins_loaded', 'infinitepay_init');

function infinitepay_init() {
    if (!class_exists('WC_Payment_Gateway')) return;

    class WC_Gateway_InfinitePay extends WC_Payment_Gateway {
        public function __construct() {
            $this->id = 'infinitepay';
            $this->method_title = 'InfinitePay';
            $this->method_description = __('Pague com InfinitePay via link de checkout', 'infinitepay');
            $this->has_fields = false;

            $this->init_form_fields();
            $this->init_settings();

            $this->title = $this->get_option('title');
            $this->description = $this->get_option('description');
            $this->handle = $this->get_option('handle');

            add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
        }

        public function init_form_fields() {
            $this->form_fields = [
                'enabled' => [
                    'title' => __('Ativar/Desativar', 'infinitepay'),
                    'type' => 'checkbox',
                    'label' => __('Ativar InfinitePay', 'infinitepay'),
                    'default' => 'yes'
                ],
                'title' => [
                    'title' => __('Título', 'infinitepay'),
                    'type' => 'text',
                    'default' => 'InfinitePay'
                ],
                'description' => [
                    'title' => __('Descrição', 'infinitepay'),
                    'type' => 'textarea',
                    'default' => __('Você poderá pagar com Pix, boleto ou cartão de crédito via InfinitePay.', 'infinitepay')
                ],
                'handle' => [
                    'title' => __('Handle do Checkout', 'infinitepay'),
                    'type' => 'text',
                    'description' => __('Seu identificador de checkout da InfinitePay', 'infinitepay')
                ]
            ];
        }

        public function process_payment($order_id) {
            $order = wc_get_order($order_id);
            $items = [];

            foreach ($order->get_items() as $item) {
                $items[] = [
                    'name' => $item->get_name(),
                    'quantity' => $item->get_quantity(),
                    'value' => number_format($order->get_line_total($item, false, false), 2, '.', '')
                ];
            }

            $params = [
                'items' => urlencode(json_encode($items)),
                'order_nsu' => $order->get_order_number(),
                'customer_name' => urlencode($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
                'customer_email' => urlencode($order->get_billing_email()),
                'customer_cellphone' => urlencode($order->get_billing_phone()),
                'redirect_url' => urlencode(home_url('/infinitepay-return?order_nsu=' . $order->get_order_number()))
            ];

            $query = http_build_query($params);
            $checkout_url = "https://checkout.infinitepay.io/{$this->handle}?{$query}";

            // QR Code
            $qr_code_url = 'https://chart.googleapis.com/chart?chs=300x300&cht=qr&chl=' . urlencode($checkout_url);
            $order->add_order_note('QR Code para pagamento InfinitePay:<br><img src="' . esc_url($qr_code_url) . '" width="200" />');

            return [
                'result' => 'success',
                'redirect' => $checkout_url
            ];
        }
    }

    function add_infinitepay_gateway($methods) {
        $methods[] = 'WC_Gateway_InfinitePay';
        return $methods;
    }

    add_filter('woocommerce_payment_gateways', 'add_infinitepay_gateway');
}

// Endpoint de retorno
add_action('init', function() {
    add_rewrite_rule('^infinitepay-return/?', 'index.php?infinitepay_return=1', 'top');
    add_rewrite_tag('%infinitepay_return%', '1');
});

add_action('template_redirect', function() {
    if (get_query_var('infinitepay_return') == 1 && isset($_GET['order_nsu'])) {
        $order_id = wc_get_order_id_by_order_key($_GET['order_nsu']);
        $order = wc_get_order($order_id);
        $handle = get_option('woocommerce_infinitepay_settings')['handle'];

        $response = wp_remote_get("https://api.infinitepay.io/invoices/public/checkout/payment_check/{$handle}");
        if (!is_wp_error($response)) {
            $body = json_decode(wp_remote_retrieve_body($response), true);
            if (isset($body['status']) && $body['status'] === 'paid') {
                $order->payment_complete();
                $order->add_order_note('Pagamento confirmado via InfinitePay.');
                $order->update_meta_data('infinitepay_receipt_url', $body['receipt_url']);
                $order->save();
            }
        }

        wp_redirect(home_url('/pedido-confirmado'));
        exit;
    }
});

// Página de logs
add_action('admin_menu', function() {
    add_menu_page('Logs InfinitePay', 'Logs InfinitePay', 'manage_woocommerce', 'infinitepay-logs', function() {
        global $wpdb;
        $results = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}postmeta WHERE meta_key = 'infinitepay_receipt_url' ORDER BY meta_id DESC LIMIT 50");
        echo '<div class="wrap"><h1>Logs de Transações InfinitePay</h1><table class="widefat"><thead><tr><th>Pedido</th><th>Comprovante</th></tr></thead><tbody>';
        foreach ($results as $row) {
            $order_id = $row->post_id;
            $url = esc_url($row->meta_value);
            echo "<tr><td><a href='" . get_edit_post_link($order_id) . "'>Pedido #$order_id</a></td><td><a href='$url' target='_blank'>Ver comprovante</a></td></tr>";
        }
        echo '</tbody></table></div>';
    }, 'dashicons-clipboard', 56);
});
