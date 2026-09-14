<?php
/**
 * Lexware Office -> 3CX Adapter — configuration (bare-metal / non-Docker).
 *
 * Copy this file to "config.php" in the same directory and fill in the values
 * below. If you deploy with Docker, you do NOT need this file — configure via
 * environment variables in docker-compose.yml instead.
 *
 * Kopiere diese Datei nach "config.php" und trage die Werte unten ein. Bei
 * einem Docker-Deployment wird diese Datei NICHT benötigt — dort erfolgt die
 * Konfiguration über Environment-Variablen in der docker-compose.yml.
 *
 * chmod 600 config.php   (it contains your API key / es enthält den API-Key)
 */

declare(strict_types=1);

return [

    'lexware' => [
        // REQUIRED: Lexware Office public API key.
        // ERFORDERLICH: Lexware-Office-API-Key.
        // app.lexware.de -> Settings/Einstellungen -> Public API/Öffentliche API
        'api_key'        => 'REPLACE_ME_lexware_api_key',

        // Import only customers (true) or all contacts incl. vendors (false).
        // Nur Kunden (true) oder alle Kontakte inkl. Lieferanten (false).
        'only_customers' => false,

        // Rarely changed.
        'base_url'       => 'https://api.lexware.io',
        'app_base_url'   => 'https://app.lexware.de',
        'page_size'      => 250,
        'page_delay_ms'  => 600,
    ],

    'adapter' => [
        // REQUIRED: shared secret. Must match the Token in the 3CX template.
        // ERFORDERLICH: gemeinsames Geheimnis. Muss dem Token im 3CX-Template
        // entsprechen. Generate/Erzeugen: openssl rand -hex 24
        'shared_token'          => 'REPLACE_ME_shared_token',

        // Where the refresh writes the index and the service reads it.
        // Wohin der Refresh den Index schreibt und der Dienst ihn liest.
        'cache_file'            => __DIR__ . '/cache.json',

        // Reject lookups once the cache is older than this (seconds; 0 = off).
        // Nachschlagen ablehnen, sobald der Cache älter ist (Sekunden; 0 = aus).
        'max_cache_age_seconds' => 3600,

        // Informational for bare-metal; the port is set on the `php -S` command.
        // Informativ; der Port wird am `php -S`-Aufruf gesetzt.
        'host' => '127.0.0.1',
        'port' => 8710,
    ],

    'sync' => [
        // Country code used to normalize national numbers to E.164.
        // Ländervorwahl zur Normalisierung nationaler Nummern auf E.164.
        'country_code' => '+49',
    ],
];
