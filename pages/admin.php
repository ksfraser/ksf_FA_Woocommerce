<?php
/**
 * WooCommerce Admin Page - configuration status and connectivity diagnostics.
 *
 * Wired into the application menu as 'WooCommerce Admin' with security area
 * SA_WOOCOMMERCE_STAGING (see hooks_ksf_FA_Woocommerce::after_menu()).
 *
 * WooCommerce only authenticates consumer-key/secret requests over HTTPS, and
 * the certificate has to chain to something FA trusts. Both failure modes look
 * like a bad API key from the outside, so this page shows the resolved config
 * and offers an explicit authenticated round trip rather than leaving the user
 * to guess from a generic sync error.
 *
 * @since 1.1.0
 * @module ksf_FA_Woocommerce
 */

$page_security = 'SA_WOOCOMMERCE_STAGING';

require_once dirname(__DIR__) . '/includes/api/load.inc';

$title = _('WooCommerce Admin');
$hooks = woo_module_hooks();
$config = $hooks->get_woo_config();

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['test_connection'])) {
    try {
        $services = woo_services();
        $status = $services['restClient']->get('system_status');
        $environment = isset($status['environment']) ? $status['environment'] : array();
        $message = sprintf(
            _('Connected to WooCommerce %s at %s'),
            isset($environment['version']) ? $environment['version'] : '?',
            isset($environment['site_url']) ? $environment['site_url'] : $config['wc_url']
        );
    } catch (Exception $e) {
        $error = _('Connection failed: ') . $e->getMessage();
    }
}

page($title);

start_form();

start_row(_('WooCommerce URL'));
label_cell($config['wc_url'] !== '' ? $config['wc_url'] : _('Not configured'));
end_row();

start_row(_('Consumer Key'));
label_cell($config['wc_key'] !== '' ? _('Configured') : _('Not configured'));
end_row();

start_row(_('Consumer Secret'));
label_cell($config['wc_secret'] !== '' ? _('Configured') : _('Not configured'));
end_row();

start_row(_('SSL Verification'));
label_cell($config['verify_ssl'] ? _('Enabled') : _('Disabled'));
end_row();

start_row(_('CA Bundle'));
label_cell($config['ca_bundle'] !== ''
    ? $config['ca_bundle'] . ($config['ca_bundle'] !== '' && is_readable($config['ca_bundle'])
        ? _(' (readable)')
        : _(' (not readable)'))
    : _('Not configured'));
end_row();

start_row(_('API Timeout'));
label_cell($config['timeout'] . 's');
end_row();

submit_row(_('Test Connection'), '', false, 'test_connection');

end_form();

page_footer();
