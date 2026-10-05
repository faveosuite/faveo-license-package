<?php

declare(strict_types=1);

// Keyed by Status::$case first, then Status::$state. Publish (--tag=license-client-lang) to edit or translate.
return [
    // States (fallback when a case has no text of its own)
    'active' => 'License OK.',
    'grace' => "Can't reach the licensing server. The application keeps working during the grace period.",
    'expired' => 'Your license has expired. Please renew it in billing.',
    'suspended' => 'Your license is suspended.',
    'cancelled' => 'Your license is cancelled.',
    'invalid' => 'This license is not valid for this installation.',
    'not_installed' => 'The license is not installed yet.',
    'tampered' => 'The license data is corrupted or was modified. Please install the license again.',
    'unreachable' => "Can't reach the licensing server and the grace period has ended.",
    'misconfigured' => 'The license client is not configured correctly.',

    // Cases
    'notification_license_ok' => 'License OK.',
    'notification_already_installed' => 'A license is already installed. Uninstall it first to install another.',
    'notification_invalid_domain' => 'This license is for a different domain. Please re-activate it for this domain.',
    'notification_invalid_product' => 'This license is for a different product.',
    'notification_invalid_machine' => 'This license is for a different server. Please re-activate it on this server.',
    'notification_machine_id_unavailable' => "Can't determine this server's machine ID.",
    'notification_no_connection' => "Can't connect to the licensing server.",
    'notification_invalid_response' => 'The licensing server sent an invalid response.',
    'notification_write_error' => "Can't save the license data.",
    'notification_domain_in_use' => 'This domain is already in use by another client. Release it in billing first.',
    'notification_domain_required' => 'This product can only be installed on a real, working domain.',
    'notification_invalid_ip' => 'Installation on this IP address is not allowed.',
    'notification_host_banned' => 'This host is banned by the licensing server.',
    'notification_license_not_found' => 'License code not found (or the product is missing or inactive).',
    'notification_license_limit' => 'The maximum number of installations for this license has been reached.',
    'notification_installation_not_found' => 'No installation found for this domain or IP address. Please activate the license again.',
    'notification_invalid_signature' => 'The license signature is invalid.',
    'notification_product_not_found' => 'The licensed product was not found.',
    'notification_product_inactive' => 'The licensed product is inactive.',
    'notification_unknown_error' => 'The licensing server reported an unknown error.',
    'feature_not_licensed' => 'Your license does not include this feature.',
    'notification_not_file_mode' => 'Licence files can only be uploaded when the offline (FILE) licence mode is used.',
    'license_file_unreadable' => "Can't read the licence file.",

    // Configuration errors (Status::$errors)
    'config_invalid_salt' => 'Set LICENSE_SALT to a random string of at least 16 characters.',
    'config_invalid_server_url' => 'Invalid licensing server URL.',
    'config_invalid_product_id' => 'Invalid product ID.',
    'config_invalid_product_key' => 'Invalid product key.',
    'config_invalid_days' => 'days must be between 1 and 365.',
    'config_invalid_grace' => 'grace_days must be 0 or more and retry_minutes at least 1.',
    'config_invalid_mode' => 'mode must be FILE or DATABASE.',
    'config_missing_license_file' => 'FILE mode needs license_file (LICENSE_FILE): the path billing writes license.json to.',
    'config_invalid_public_key' => 'Missing or invalid Ed25519 public key (LICENSE_PUBLIC_KEY), or the sodium extension is missing.',
    'config_ip_mismatch' => "The licensing server's IP address doesn't match root_ip.",
    'config_nameserver_mismatch' => "The licensing server's nameservers don't match the configured ones.",
    'config_install_dir_not_writable' => 'The install directory is not writable.',
    'config_zip_missing' => 'The zip PHP extension is required for updates.',

    // Invalid install input (Status::$errors)
    'input_invalid_url' => 'Invalid installation URL.',
    'input_empty_license_data' => 'Please provide an email address or a license code.',
    'input_invalid_email' => 'Invalid email address.',
];
