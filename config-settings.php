<?php

function getSettings() {
    global $pdo;

    static $settings;

    if (!$settings) {
        $settings = $pdo->query("SELECT * FROM settings LIMIT 1")->fetch();
    }

    return $settings;
}