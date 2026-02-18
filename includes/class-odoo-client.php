<?php

if (!defined('ABSPATH')) {
    exit;
}

class Woo_Odoo_Client {

    private $url;
    private $db;
    private $username;
    private $password;
    private $uid = null;

    public function __construct() {
        $this->url      = rtrim(get_option('woo_odoo_url'), '/');
        $this->db       = get_option('woo_odoo_db');
        $this->username = get_option('woo_odoo_username');
        $this->password = get_option('woo_odoo_password');
    }

    /*
    ==================================================
    LOW LEVEL REQUEST
    ==================================================
    */

    private function request($payload) {

        $response = wp_remote_post($this->url . '/jsonrpc', [
            'headers' => [
                'Content-Type' => 'application/json'
            ],
            'body'    => json_encode($payload),
            'timeout' => 20,
        ]);

        if (is_wp_error($response)) {
            error_log('Odoo HTTP Error: ' . $response->get_error_message());
            return false;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        return $body;
    }

    /*
    ==================================================
    LOGIN
    ==================================================
    */

    public function login() {

        $payload = [
            'jsonrpc' => '2.0',
            'method'  => 'call',
            'params'  => [
                'service' => 'common',
                'method'  => 'login',
                'args'    => [
                    $this->db,
                    $this->username,
                    $this->password
                ]
            ]
        ];

        $result = $this->request($payload);

        if (!empty($result['result'])) {
            $this->uid = $result['result'];
            error_log('Odoo login success. UID: ' . $this->uid);
            return true;
        }

        error_log('Odoo login failed.');
        return false;
    }

    /*
    ==================================================
    EXECUTE (FIXED VERSION)
    ==================================================
    */

    public function execute($model, $method, $args = [], $kwargs = []) {

        if (!$this->uid) {
            if (!$this->login()) {
                return false;
            }
        }

        error_log("Odoo EXECUTE: {$model} -> {$method}");

        // Core execute arguments
        $execute_args = [
            $this->db,
            $this->uid,
            $this->password,
            $model,
            $method,
            $args
        ];

        // 🔥 Only include kwargs if NOT empty
        if (!empty($kwargs)) {
            $execute_args[] = $kwargs;
        }

        $payload = [
            'jsonrpc' => '2.0',
            'method'  => 'call',
            'params'  => [
                'service' => 'object',
                'method'  => 'execute_kw',
                'args'    => $execute_args
            ]
        ];

        $response = $this->request($payload);

        if (!$response) {
            error_log('Odoo empty response.');
            return false;
        }

        if (isset($response['error'])) {
            error_log('Odoo ERROR: ' . print_r($response['error'], true));
            return false;
        }

        error_log('Odoo RESULT: ' . print_r($response['result'], true));

        return $response;
    }
}
